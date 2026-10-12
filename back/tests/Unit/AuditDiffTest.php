<?php

namespace Tests\Unit;

use App\Domain\Audit\AuditDiff;
use PHPUnit\Framework\TestCase;

/**
 * Diferença before/after para visualização (derivada, nunca persistida). Ausente ≠ null; listas são valor.
 */
class AuditDiffTest extends TestCase
{
    private function change(string $path, mixed $before, mixed $after, bool $existsBefore = true, bool $existsAfter = true): array
    {
        return ['path' => $path, 'before' => $before, 'after' => $after, 'exists_before' => $existsBefore, 'exists_after' => $existsAfter];
    }

    public function test_changed_value(): void
    {
        $this->assertSame([$this->change('status', 'PENDING', 'COMPLETED')], AuditDiff::changes(['status' => 'PENDING', 'title' => 'T'], ['status' => 'COMPLETED', 'title' => 'T']));
    }

    public function test_created_and_removed_fields_are_distinguished_from_null(): void
    {
        $this->assertSame([
            $this->change('removed', 'x', null, existsAfter: false),
            $this->change('created', null, 5, existsBefore: false),
        ], AuditDiff::changes(['removed' => 'x'], ['created' => 5]));
    }

    public function test_null_values(): void
    {
        $this->assertSame([$this->change('due_at', null, '2026-10-12')], AuditDiff::changes(['due_at' => null], ['due_at' => '2026-10-12']));
        $this->assertSame([$this->change('due_at', '2026-10-12', null)], AuditDiff::changes(['due_at' => '2026-10-12'], ['due_at' => null]));
        // Chave existia com null nos dois lados: sem mudança.
        $this->assertSame([], AuditDiff::changes(['due_at' => null], ['due_at' => null]));
        // Ausente → null é mudança (a chave passou a existir).
        $this->assertSame([$this->change('due_at', null, null, existsBefore: false)], AuditDiff::changes([], ['due_at' => null]));
    }

    public function test_nested_objects_use_dot_paths(): void
    {
        $this->assertSame(
            [$this->change('profile.role', 'EDITOR', 'MANAGER'), $this->change('team.id', 1, 2)],
            AuditDiff::changes(
                ['profile' => ['role' => 'EDITOR', 'name' => 'A'], 'team' => ['id' => 1]],
                ['profile' => ['role' => 'MANAGER', 'name' => 'A'], 'team' => ['id' => 2]],
            ),
        );
    }

    public function test_lists_are_compared_as_whole_values(): void
    {
        $this->assertSame([$this->change('team_ids', [1, 2], [2, 3])], AuditDiff::changes(['team_ids' => [1, 2]], ['team_ids' => [2, 3]]));
        $this->assertSame([], AuditDiff::changes(['team_ids' => [1, 2]], ['team_ids' => [1, 2]]));
        $this->assertSame([$this->change('team_ids', [1], [])], AuditDiff::changes(['team_ids' => [1]], ['team_ids' => []]));
    }

    public function test_before_or_after_null(): void
    {
        $this->assertSame([$this->change('name', null, 'Equipe', existsBefore: false)], AuditDiff::changes(null, ['name' => 'Equipe']));
        $this->assertSame([$this->change('name', 'Equipe', null, existsAfter: false)], AuditDiff::changes(['name' => 'Equipe'], null));
        $this->assertSame([], AuditDiff::changes(null, null));
    }

    public function test_no_changes(): void
    {
        $this->assertSame([], AuditDiff::changes(['a' => 1, 'b' => ['c' => 2]], ['b' => ['c' => 2], 'a' => 1]));
    }
}
