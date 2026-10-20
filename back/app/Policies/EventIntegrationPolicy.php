<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\User;

/**
 * Integração Even3: consulta (Administrador, Consultor), configuração/mapeamento e sync (Administrador).
 * Inscrições e presença têm permissões próprias; materializar participantes exige também participants.manage.
 *
 * Vincular representante sem empresa (única pendência com ação): integrations.manage + companies.manage.
 * O Consultor só consulta.
 */
class EventIntegrationPolicy
{
    public function view(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsView);
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsManage);
    }

    public function sync(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsSync);
    }

    /**
     * Avisos e mensagens de erro detalhados do sync (ids externos): só quem administra.
     */
    public function viewSyncDetails(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsManage);
    }

    public function viewRegistrations(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::EventRegistrationsView);
    }

    public function viewAttendance(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::AttendanceView);
    }

    public function materializeParticipants(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::ParticipantsManage);
    }

    public function linkCompanyGuests(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::CompaniesManage);
    }

    /**
     * "Precisa de atenção" lista nomes e e-mails: quem vê inscrições.
     */
    public function viewAttention(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsView) && $actor->hasPermission(PermissionCode::EventRegistrationsView);
    }

    private function manageWith(User $actor, PermissionCode $domain): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsManage) && $actor->hasPermission($domain);
    }
}
