<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\Event;
use App\Models\FinancialItem;
use App\Models\User;

class FinancialItemPolicy
{
    public function viewAny(User $actor, Event $event): bool
    {
        return $actor->hasPermission(PermissionCode::FinanceView) && $this->canReachFinance($actor, $event);
    }

    public function create(User $actor, Event $event): bool
    {
        return $actor->hasPermission(PermissionCode::FinanceManage) && $this->canReachFinance($actor, $event);
    }

    public function update(User $actor, FinancialItem $item): bool
    {
        return $actor->hasPermission(PermissionCode::FinanceManage) && $this->canReachFinance($actor, $item->event);
    }

    public function delete(User $actor, FinancialItem $item): bool
    {
        return $this->update($actor, $item);
    }

    private function canReachFinance(User $actor, Event $event): bool
    {
        if ($actor->reachesAllSectors()) {
            return true;
        }

        $sector = $actor->sector;

        return $sector !== null
            && $sector->event_id === $event->id
            && $sector->active
            && mb_strtolower(trim($sector->name)) === 'setor financeiro';
    }
}
