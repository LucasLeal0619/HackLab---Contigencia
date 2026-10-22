<?php

namespace Tests\Feature\Database;

use App\Domain\Users\Enums\RoleCode;
use App\Models\Event;
use App\Models\Occurrence;
use App\Models\Sector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regras de integridade dos triggers acessadas direto no banco (sem services), nos DOIS bancos — PostgreSQL
 * (referência) e MySQL local (HACKLAB-DEV-001, etapa 5). Cobre regras que não tinham teste direto: histórico de
 * ocorrências somente inserção, campos imutáveis de ocorrência, responsável coerente com o setor e usuário com
 * ocorrência aberta atribuída.
 */
class DatabaseIntegrityRulesTest extends DatabaseTestCase
{
    public function test_occurrence_history_is_append_only(): void
    {
        $occurrence = Occurrence::factory()->create();
        DB::table('occurrence_interactions')->insert(['occurrence_id' => $occurrence->id, 'type' => 'COMMENT', 'message' => 'ok', 'created_at' => now()]);
        $row = DB::table('occurrence_interactions')->where('occurrence_id', $occurrence->id)->where('type', 'COMMENT');

        $this->assertRefused('somente inserção', fn () => (clone $row)->update(['message' => 'alterado']));
        $this->assertRefused('somente inserção', fn () => (clone $row)->delete());
        $this->assertSame('ok', (clone $row)->value('message'));
    }

    public function test_occurrence_reference_event_and_origin_never_change(): void
    {
        $occurrence = Occurrence::factory()->create();
        $other = Sector::factory()->create(['event_id' => $occurrence->event_id]);
        $row = fn () => DB::table('occurrences')->where('id', $occurrence->id);

        $row()->update(['title' => 'Título novo']); // permitido

        $this->assertRefused('referência, evento e origem não mudam', fn () => $row()->update(['reference' => 'OCO-9999']));
        $this->assertRefused('referência, evento e origem não mudam', fn () => $row()->update(['origin_sector_id' => $other->id]));
        $this->assertSame('Título novo', $row()->value('title'));
    }

    public function test_occurrence_assignee_must_be_an_active_user_of_the_responsible_sector(): void
    {
        $event = Event::factory()->create();
        $sectorA = Sector::factory()->create(['event_id' => $event->id]);
        $sectorB = Sector::factory()->create(['event_id' => $event->id]);
        $editorA = $this->sectorUser(RoleCode::Editor, $sectorA);
        $inA = Occurrence::factory()->between($sectorA)->create(['event_id' => $event->id]);
        $inB = Occurrence::factory()->between($sectorB)->create(['event_id' => $event->id]);

        DB::table('occurrences')->where('id', $inA->id)->update(['assigned_user_id' => $editorA->id]); // permitido

        $this->assertRefused('responsável individual precisa ser usuário ativo do setor responsável',
            fn () => DB::table('occurrences')->where('id', $inB->id)->update(['assigned_user_id' => $editorA->id]));

        // Inserção direta também é verificada.
        $this->assertRefused('responsável individual precisa ser usuário ativo do setor responsável',
            fn () => Occurrence::factory()->between($sectorB)->create(['event_id' => $event->id, 'assigned_user_id' => $editorA->id]));

        // Ocorrência resolvida não exige coerência.
        DB::table('occurrences')->where('id', $inB->id)->update([
            'status' => 'RESOLVED', 'resolved_at' => now(), 'assigned_user_id' => $editorA->id,
        ]);
        $this->assertSame($editorA->id, (int) DB::table('occurrences')->where('id', $inB->id)->value('assigned_user_id'));
    }

    public function test_user_with_an_open_occurrence_cannot_change_sector_or_be_inactivated(): void
    {
        $event = Event::factory()->create();
        $sectorA = Sector::factory()->create(['event_id' => $event->id]);
        $sectorB = Sector::factory()->create(['event_id' => $event->id]);
        $editor = $this->sectorUser(RoleCode::Editor, $sectorA);
        $occurrence = Occurrence::factory()->between($sectorA)->create(['event_id' => $event->id, 'assigned_user_id' => $editor->id]);
        $user = fn () => DB::table('users')->where('id', $editor->id);

        $this->assertRefused('reatribua antes de mudar setor ou inativar', fn () => $user()->update(['sector_id' => $sectorB->id]));
        $this->assertRefused('reatribua antes de mudar setor ou inativar', fn () => $user()->update(['status' => 'INACTIVE']));

        DB::table('occurrences')->where('id', $occurrence->id)->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
        $user()->update(['sector_id' => $sectorB->id]); // agora permitido

        $this->assertSame($sectorB->id, (int) $user()->value('sector_id'));
    }

    public function test_sector_must_match_the_role_on_insert_and_update(): void
    {
        $sector = Sector::factory()->create();

        $this->assertRefused('exige setor', fn () => $this->userWithRole(RoleCode::Manager, ['sector_id' => null]));
        $this->assertRefused('não tem vínculo setorial', fn () => $this->userWithRole(RoleCode::Consultant, ['sector_id' => $sector->id]));

        $manager = $this->sectorUser(RoleCode::Manager, $sector);
        $this->assertRefused('exige setor', fn () => DB::table('users')->where('id', $manager->id)->update(['sector_id' => null]));
    }

    private function assertRefused(string $message, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("O banco aceitou uma operação que deveria recusar ({$message}).");
    }
}
