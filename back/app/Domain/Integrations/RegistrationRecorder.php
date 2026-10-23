<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\People\Enums\PersonStatus;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\ExternalTicketType;
use App\Models\Person;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Único caminho que cria/atualiza Person + EventRegistration a partir da plataforma externa
 * (sincronização pela API e webhook). Tudo sob o advisory lock da integração, numa transação pequena:
 * API e webhook do mesmo inscrito nunca correm em paralelo e convergem para um único registro.
 *
 * Matching: inscrição conhecida pelos IDs externos → documento único → e-mail único → nova Person.
 * Documento e e-mail apontando para pessoas diferentes (ou ambíguos) = conflito: nada é vinculado.
 *
 * Não cria conta, perfil, jurado, empresa, participante nem elegibilidade. Não gera AuditLog por inscrito.
 */
class RegistrationRecorder
{
    public const LOCK_CLASS = 91003;

    /** Campos que mudam a cada leitura e não contam como "atualizado". */
    private const VOLATILE = ['last_seen_at', 'last_synced_at', 'updated_at'];

    /**
     * @param  array<string, mixed>  $attendee  formato normalizado do EventPlatformProvider
     * @param  RegistrationStatus|null  $explicitStatus  status explícito do webhook (null = não informado)
     * @return array{result: string, registration: ?EventRegistration, person_created: bool, warnings: list<array{code: string, external_id: ?string, message: string}>}
     */
    public function record(EventIntegration $integration, array $attendee, string $source, ?RegistrationStatus $explicitStatus = null, ?CarbonImmutable $observedAt = null): array
    {
        return DB::transaction(function () use ($integration, $attendee, $source, $explicitStatus, $observedAt) {
            self::lock($integration);

            return $this->recordLocked($integration, $attendee, $source, $explicitStatus, $observedAt);
        });
    }

    public static function lock(EventIntegration $integration): void
    {
        TransactionLock::acquire(self::LOCK_CLASS, (int) $integration->id);
    }

    /**
     * @param  array<string, mixed>  $attendee
     * @return array{result: string, registration: ?EventRegistration, person_created: bool, warnings: list<array{code: string, external_id: ?string, message: string}>}
     */
    private function recordLocked(EventIntegration $integration, array $attendee, string $source, ?RegistrationStatus $explicitStatus, ?CarbonImmutable $observedAt): array
    {
        $externalId = $attendee['external_attendee_id'] ?? $attendee['external_registration_id'] ?? $attendee['external_person_id'] ?? null;
        $warnings = [];
        // Por referência: os avisos acumulados até o retorno entram no resultado.
        $outcome = function (string $result, ?EventRegistration $registration = null, bool $personCreated = false) use (&$warnings): array {
            return ['result' => $result, 'registration' => $registration, 'person_created' => $personCreated, 'warnings' => $warnings];
        };

        if ((string) ($attendee['external_event_id'] ?? '') !== (string) $integration->external_event_id) {
            $warnings[] = $this->warning('EVENT_MISMATCH', $externalId, 'Inscrição de outro evento externo: rejeitada.');

            return $outcome('rejected');
        }

        $email = IdentityNormalizer::email($attendee['email'] ?? null);
        $document = IdentityNormalizer::document($attendee['document'] ?? null);

        // 1) Inscrição já conhecida pelos IDs externos (prioridade: inscrição → participante → pessoa externa).
        [$registration, $idConflict] = $this->registrationByExternalIds($integration, $attendee);

        if ($idConflict !== null) {
            $warnings[] = $this->warning($idConflict, $externalId, 'Os identificadores externos apontam para inscrições diferentes ou divergem da inscrição encontrada.');

            return $outcome('conflict');
        }

        $personCreated = false;

        if ($registration !== null) {
            $person = Person::query()->findOrFail($registration->person_id);
        } else {
            // 2–3) Matching controlado da Person.
            $byDocument = $document === null ? collect() : Person::query()
                ->whereRaw('upper('.self::regexpReplaceAll("coalesce(document, '')", '[^A-Za-z0-9]', '').') = ?', [$document])->limit(2)->pluck('id');
            $byEmail = $email === null ? collect() : Person::query()
                ->whereRaw('lower(trim(email)) = ?', [$email])->limit(2)->pluck('id');

            if ($byDocument->count() > 1 || $byEmail->count() > 1
                || ($byDocument->isNotEmpty() && $byEmail->isNotEmpty() && $byDocument->first() !== $byEmail->first())) {
                $warnings[] = $this->warning('IDENTITY_CONFLICT', $externalId, 'Documento e e-mail apontam para pessoas diferentes ou ambíguas: não vinculado.');

                return $outcome('conflict');
            }

            $personId = $byDocument->first() ?? $byEmail->first();

            if ($personId !== null) {
                $person = Person::query()->lockForUpdate()->findOrFail($personId);

                // Documento/e-mail localizam a Person, mas não provam que é a mesma inscrição. Só reaproveita
                // uma inscrição da pessoa se houver exatamente UMA candidata compatível (ex.: criada pelo webhook,
                // ainda sem o id do participante da API). Mais de uma = ambíguo: conflito, sem merge arbitrário.
                // Nenhuma compatível = é outra inscrição da mesma pessoa (permitido).
                $candidates = EventRegistration::query()->where('event_integration_id', $integration->id)
                    ->where('person_id', $person->id)->lockForUpdate()->get()
                    ->filter(fn (EventRegistration $existing) => $this->compatible($existing, $attendee))
                    ->values();

                if ($candidates->count() > 1) {
                    $warnings[] = $this->warning('REGISTRATION_AMBIGUOUS', $externalId, 'A pessoa tem mais de uma inscrição compatível: não foi possível decidir qual é esta.');

                    return $outcome('conflict');
                }

                $registration = $candidates->first();
            } else {
                // 4) Nova Person (sem conta; sem auditoria por inscrito).
                if (($attendee['name'] ?? null) === null) {
                    $warnings[] = $this->warning('MISSING_NAME', $externalId, 'Inscrição sem nome: não foi possível cadastrar a pessoa.');

                    return $outcome('conflict');
                }

                $person = Person::query()->create([
                    'full_name' => $attendee['name'],
                    'email' => $email,
                    'phone' => IdentityNormalizer::phone($attendee['phone'] ?? null),
                    'document' => $document,
                    'status' => PersonStatus::Active,
                ]);
                $personCreated = true;
            }
        }

        if (! $personCreated) {
            $this->fillEmptyPersonFields($person, $attendee, $email, $document);
        }

        $isNew = $registration === null;
        $registration ??= new EventRegistration([
            'event_id' => $integration->event_id,
            'event_integration_id' => $integration->id,
            'person_id' => $person->id,
            'source_name' => $attendee['name'] ?? $person->full_name,
        ]);

        $this->applyAttendee($registration, $integration, $attendee, $email, $document, $warnings);
        $this->applyStatus($registration, $isNew, $source, $attendee, $explicitStatus, $observedAt);
        $registration->identity_mismatch = $this->mismatch($person->fresh(), $registration);

        $changed = $isNew || array_diff(array_keys($registration->getDirty()), self::VOLATILE) !== [];
        $now = now();
        $registration->last_seen_at = $now;
        if ($source === 'API') {
            $registration->last_synced_at = $now;
        }
        $registration->save();

        return $outcome($isNew ? 'created' : ($changed ? 'updated' : 'unchanged'), $registration, $personCreated);
    }

    /**
     * Localiza a inscrição pelos IDs externos, nesta ordem: external_registration_id → external_attendee_id →
     * external_person_id. O id da pessoa externa só identifica a inscrição se houver exatamente uma compatível.
     *
     * @param  array<string, mixed>  $attendee
     * @return array{0: ?EventRegistration, 1: ?string} [inscrição, código de conflito]
     */
    private function registrationByExternalIds(EventIntegration $integration, array $attendee): array
    {
        $found = collect();

        foreach (['external_registration_id', 'external_attendee_id'] as $column) {
            if (($attendee[$column] ?? null) !== null) {
                $match = EventRegistration::query()->where('event_integration_id', $integration->id)
                    ->where($column, (string) $attendee[$column])->lockForUpdate()->first();

                if ($match !== null) {
                    $found->put($match->id, $match);
                }
            }
        }

        if ($found->count() > 1) {
            return [null, 'REGISTRATION_ID_CONFLICT'];
        }

        if ($found->count() === 1) {
            $registration = $found->first();

            return $this->compatible($registration, $attendee) ? [$registration, null] : [null, 'REGISTRATION_ID_CONFLICT'];
        }

        if (($attendee['external_person_id'] ?? null) !== null) {
            $byPerson = EventRegistration::query()->where('event_integration_id', $integration->id)
                ->where('external_person_id', (string) $attendee['external_person_id'])->lockForUpdate()->get()
                ->filter(fn (EventRegistration $existing) => $this->compatible($existing, $attendee))->values();

            if ($byPerson->count() > 1) {
                return [null, 'REGISTRATION_AMBIGUOUS'];
            }

            if ($byPerson->count() === 1) {
                return [$byPerson->first(), null];
            }
        }

        return [null, null];
    }

    /**
     * Pode ser a mesma inscrição? Nenhum id externo nem código de credenciamento que chega pode divergir
     * do que a inscrição já tem.
     *
     * @param  array<string, mixed>  $attendee
     */
    private function compatible(EventRegistration $registration, array $attendee): bool
    {
        foreach (['external_attendee_id', 'external_registration_id', 'external_person_id', 'check_in_code'] as $column) {
            $incoming = $attendee[$column] ?? null;

            if ($incoming !== null && $registration->{$column} !== null && (string) $registration->{$column} !== (string) $incoming) {
                return false;
            }
        }

        return true;
    }

    /**
     * Person existente: só preenche campos vazios (nunca sobrescreve cadastro interno).
     *
     * @param  array<string, mixed>  $attendee
     */
    private function fillEmptyPersonFields(Person $person, array $attendee, ?string $email, ?string $document): void
    {
        $fill = array_filter([
            'email' => blank($person->email) ? $email : null,
            'document' => blank($person->document) ? $document : null,
            'phone' => blank($person->phone) ? IdentityNormalizer::phone($attendee['phone'] ?? null) : null,
        ], fn ($value) => $value !== null);

        if ($fill !== []) {
            $person->forceFill($fill)->save();
        }
    }

    /**
     * IDs (nunca apagados), entrada (por nome único conhecido), código, data e valores da origem.
     *
     * @param  array<string, mixed>  $attendee
     * @param  list<array<string, mixed>>  $warnings
     */
    private function applyAttendee(EventRegistration $registration, EventIntegration $integration, array $attendee, ?string $email, ?string $document, array &$warnings): void
    {
        foreach (['external_attendee_id', 'external_registration_id', 'external_person_id'] as $column) {
            if (($attendee[$column] ?? null) !== null && $registration->{$column} === null) {
                $registration->{$column} = (string) $attendee[$column];
            }
        }

        if (($label = $attendee['category_label'] ?? null) !== null) {
            $registration->external_category_label = $label;
            $registration->external_ticket_type_id = $this->ticketFor($integration, $label, $attendee, $warnings);
        }

        foreach ([
            'check_in_code' => $attendee['check_in_code'] ?? null,
            'source_name' => $attendee['name'] ?? null,
            'source_badge_name' => $attendee['badge_name'] ?? null,
            'source_email' => $email,
            'source_phone' => IdentityNormalizer::phone($attendee['phone'] ?? null),
            'source_document' => $document,
        ] as $column => $value) {
            if ($value !== null) {
                $registration->{$column} = $value;
            }
        }

        $registeredAt = $this->registeredAt($attendee, $integration);
        if ($registeredAt !== null && ($registration->registered_at === null || ! $registration->registered_at->equalTo($registeredAt))) {
            $registration->registered_at = $registeredAt;
        }
    }

    /**
     * Texto da categoria → entrada só se corresponder a exatamente uma entrada conhecida. Nunca escolhe.
     *
     * @param  array<string, mixed>  $attendee
     * @param  list<array<string, mixed>>  $warnings
     */
    private function ticketFor(EventIntegration $integration, string $label, array $attendee, array &$warnings): ?int
    {
        $matches = ExternalTicketType::query()
            ->where('event_integration_id', $integration->id)
            ->whereRaw('lower('.self::regexpReplaceAll('trim(external_name)', '\\s+', ' ', mysqlPattern: '[[:space:]]+').') = ?', [IdentityNormalizer::label($label)])
            ->limit(2)
            ->pluck('id');

        if ($matches->count() > 1) {
            $warnings[] = $this->warning('CATEGORY_AMBIGUOUS', $attendee['external_attendee_id'] ?? $attendee['external_registration_id'] ?? null, 'Categoria corresponde a mais de uma entrada: sem entrada associada.');

            return null;
        }

        return $matches->first();
    }

    /**
     * API: confirmado → CONFIRMED; não confirmado → PENDING, preservando CANCELLED explícito.
     * Webhook: status explícito, se não for mais antigo que a última mudança conhecida.
     * Ausência de informação nunca muda o status.
     *
     * @param  array<string, mixed>  $attendee
     */
    private function applyStatus(EventRegistration $registration, bool $isNew, string $source, array $attendee, ?RegistrationStatus $explicitStatus, ?CarbonImmutable $observedAt): void
    {
        $current = $registration->status;
        $target = $current ?? RegistrationStatus::Unknown;
        $changedAt = now();

        if ($source === 'API' && is_bool($attendee['confirmed'] ?? null)) {
            $target = $attendee['confirmed']
                ? RegistrationStatus::Confirmed
                : ($current === RegistrationStatus::Cancelled ? RegistrationStatus::Cancelled : RegistrationStatus::Pending);
        } elseif ($explicitStatus !== null) {
            $stale = ! $isNew && $observedAt !== null && $registration->status_changed_at !== null && $observedAt->lessThan($registration->status_changed_at);
            $target = $stale ? $target : $explicitStatus;
            $changedAt = $observedAt ?? $changedAt;
        }

        if ($isNew || $target !== $current) {
            $registration->status = $target;
            $registration->status_changed_at = $changedAt;
        }
    }

    private function mismatch(Person $person, EventRegistration $registration): bool
    {
        $emailDiffers = $registration->source_email !== null && filled($person->email) && IdentityNormalizer::email($person->email) !== $registration->source_email;
        $documentDiffers = $registration->source_document !== null && filled($person->document) && IdentityNormalizer::document($person->document) !== $registration->source_document;

        return $emailDiffers || $documentDiffers;
    }

    /**
     * Data/hora local da inscrição → UTC, só com o timezone do evento configurado (nunca deduzido).
     *
     * API V2: só o formato documentado "dd/mm/aaaa" + "hh:mm" é aceito. O PoC real (12/10/2026) devolveu
     * "2026-12-10T00:00:00Z" para inscrições feitas em 12/10/2026 (dia e mês aparentemente invertidos):
     * formato fora do documentado não é interpretado e registered_at fica nulo.
     *
     * @param  array<string, mixed>  $attendee
     */
    private function registeredAt(array $attendee, EventIntegration $integration): ?CarbonImmutable
    {
        $timezone = $integration->event?->timezone;

        if ($timezone === null) {
            return null;
        }

        try {
            if (($iso = $attendee['registered_at_iso'] ?? null) !== null) {
                return CarbonImmutable::parse($iso, $timezone)->utc();
            }

            $date = $attendee['registration_date'] ?? null;
            $hour = $attendee['registration_hour'] ?? null;

            if ($date !== null && preg_match('#^\d{2}/\d{2}/\d{4}$#', $date) === 1) {
                $hour = $hour !== null && preg_match('/^\d{2}:\d{2}$/', $hour) === 1 ? $hour : '00:00';

                return CarbonImmutable::createFromFormat('d/m/Y H:i', "{$date} {$hour}", $timezone)?->utc();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{code: string, external_id: ?string, message: string}
     */
    private function warning(string $code, ?string $externalId, string $message): array
    {
        return ['code' => $code, 'external_id' => $externalId, 'message' => $message];
    }

    /**
     * regexp_replace de todas as ocorrências (HACKLAB-DEV-001). PostgreSQL: flag 'g' (SQL de sempre). MySQL: substitui
     * todas por padrão e não tem a flag; e descarta "\" em literais, então classes como \s vão em $mysqlPattern.
     */
    private static function regexpReplaceAll(string $expression, string $pattern, string $replacement, ?string $mysqlPattern = null): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? "regexp_replace({$expression}, '".($mysqlPattern ?? $pattern)."', '{$replacement}')"
            : "regexp_replace({$expression}, '{$pattern}', '{$replacement}', 'g')";
    }
}
