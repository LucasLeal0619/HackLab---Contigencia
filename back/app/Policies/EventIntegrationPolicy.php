<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\User;

/**
 * Integração Even3: consulta (Administrador, Consultor), configuração/mapeamento e sync (Administrador).
 * Inscrições e presença têm permissões próprias; materializar participantes exige também participants.manage.
 *
 * Reconciliação (Complemento 11.2): integrations.manage não basta — cada fila exige também a permissão real do
 * domínio (jurados, empresas, usuários, evento). O Consultor só consulta o resumo e o histórico.
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

    public function reconcileJurors(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::JurorsManage);
    }

    public function reconcileCompanyGuests(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::CompaniesManage);
    }

    /**
     * Fila Organização: liberar e revogar acesso.
     */
    public function grantAccess(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::UsersManage);
    }

    public function applyEventMetadata(User $actor): bool
    {
        return $this->manageWith($actor, PermissionCode::EventsManage);
    }

    private function manageWith(User $actor, PermissionCode $domain): bool
    {
        return $actor->hasPermission(PermissionCode::IntegrationsManage) && $actor->hasPermission($domain);
    }
}
