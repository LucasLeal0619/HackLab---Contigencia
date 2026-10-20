<?php

namespace Tests\Concerns;

use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;

/**
 * Cenário do Centro Even3 (Complemento 11.2): o cenário do primeiro acesso + entradas Organização e
 * Empresa/Convidado. O nome da entrada nunca decide nada: só a categoria mapeada.
 */
trait ReconciliationScenario
{
    use ActivationScenario;

    protected function buildReconciliationScenario(): void
    {
        $this->buildActivationScenario();
        $this->tickets['organization'] = $this->ticketType($this->integration, '50', 'Organização', TicketCategory::Organization);
        $this->tickets['company'] = $this->ticketType($this->integration, '60', 'Empresa / Convidado', TicketCategory::CompanyGuest);
        // "Jurado" no nome, mas sem mapeamento: não gera candidato.
        $this->tickets['juror_unmapped'] = $this->ticketType($this->integration, '70', 'Jurado convidado');
    }

    protected function candidate(string $email, string $ticket, array $attributes = []): Person
    {
        $person = $this->person($email, $attributes);
        $this->inscribe($person, $ticket);

        return $person;
    }

    protected function base(): string
    {
        return "/api/v1/events/{$this->event->id}/integrations/even3";
    }

    /**
     * Administrador sem uma permissão (retirada do perfil só nesta transação de teste): prova que
     * integrations.manage sozinho não basta para reconciliar.
     */
    protected function adminWithout(PermissionCode $permission): User
    {
        $role = Role::forCode(RoleCode::Administrator);
        $role->permissions()->detach(Permission::query()->where('code', $permission->value)->pluck('id'));

        return $this->userWithRole(RoleCode::Administrator);
    }
}
