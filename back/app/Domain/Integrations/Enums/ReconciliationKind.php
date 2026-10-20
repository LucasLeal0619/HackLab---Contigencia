<?php

namespace App\Domain\Integrations\Enums;

/**
 * Filas de reconciliação: categoria interna da entrada que gera o candidato e o identificador da fila na URL.
 */
enum ReconciliationKind: string
{
    case Juror = 'JUROR';
    case Organization = 'ORGANIZATION';
    case CompanyGuest = 'COMPANY_GUEST';

    public function category(): TicketCategory
    {
        return match ($this) {
            self::Juror => TicketCategory::Juror,
            self::Organization => TicketCategory::Organization,
            self::CompanyGuest => TicketCategory::CompanyGuest,
        };
    }

    public function queue(): string
    {
        return match ($this) {
            self::Juror => 'jurors',
            self::Organization => 'organization',
            self::CompanyGuest => 'company-guests',
        };
    }

    public static function fromQueue(string $queue): self
    {
        foreach (self::cases() as $kind) {
            if ($kind->queue() === $queue) {
                return $kind;
            }
        }

        throw new \ValueError("Fila de reconciliação desconhecida: {$queue}");
    }

    /**
     * @return list<string>
     */
    public static function queues(): array
    {
        return array_map(fn (self $kind) => $kind->queue(), self::cases());
    }
}
