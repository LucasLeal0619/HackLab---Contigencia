<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\Event;
use App\Models\User;

/**
 * Evento e seus dias. Gerenciar evento é global: exige events.manage e escopo global.
 */
class EventPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::EventsView);
    }

    public function view(User $actor, Event $event): bool
    {
        return $actor->hasPermission(PermissionCode::EventsView);
    }

    /**
     * Relatórios consolidados do evento (Fase 11): reports.view e alcance global (sem recorte setorial).
     */
    public function viewReports(User $actor, Event $event): bool
    {
        return $actor->reachesAllSectors() && $actor->hasPermission(PermissionCode::ReportsView);
    }

    public function create(User $actor): bool
    {
        return $actor->reachesAllSectors() && $actor->hasPermission(PermissionCode::EventsManage);
    }

    public function update(User $actor, Event $event): bool
    {
        return $actor->reachesAllSectors() && $actor->hasPermission(PermissionCode::EventsManage);
    }
}
