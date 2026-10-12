<?php

namespace App\Policies;

use App\Domain\Demands\Contracts\Demand;
use App\Domain\Users\Enums\PermissionCode;
use App\Models\Event;
use App\Models\User;

/**
 * Regras comuns de pendências e ocorrências: permissão + escopo setorial.
 *
 * - participa (ver/comentar): alcance global OU setor do usuário ∈ {origem, responsável, envolvidos};
 * - controla (operar/encaminhar): alcance global OU setor do usuário = responsável atual.
 *
 * Níveis de operação (sem testar nome de perfil):
 * - "operate": status, concluir/resolver, reabrir (Editor e Gestor do responsável);
 * - "operate" + "route": também prioridade, prazo, responsável individual, envolvidos, conteúdo
 *   e criação para outro setor (Gestor do responsável e Administrador);
 * - "route": encaminhar.
 */
abstract class DemandPolicy
{
    /**
     * Prefixo das permissões: "tasks" ou "occurrences".
     */
    abstract protected function prefix(): string;

    public function viewAny(User $actor): bool
    {
        return $this->allows($actor, 'view');
    }

    public function view(User $actor, Demand $demand): bool
    {
        return $this->allows($actor, 'view') && $this->participates($actor, $demand);
    }

    public function comment(User $actor, Demand $demand): bool
    {
        return $this->allows($actor, 'comment') && $this->participates($actor, $demand);
    }

    /**
     * Criação: quem tem setor cria com origem no próprio setor; sem "route" (Editor), também
     * só para o próprio setor e, no máximo, atribuindo a si mesmo.
     */
    public function create(User $actor, ?int $originSectorId = null, ?int $responsibleSectorId = null, ?int $assignedUserId = null): bool
    {
        if (! $this->allows($actor, 'create')) {
            return false;
        }

        if ($actor->reachesAllSectors()) {
            return true;
        }

        if ($originSectorId !== $actor->sector_id) {
            return false;
        }

        if ($this->allows($actor, 'route')) {
            return true;
        }

        return $responsibleSectorId === $actor->sector_id
            && ($assignedUserId === null || $assignedUserId === $actor->id);
    }

    /**
     * Status (exceto fechar pela rota própria), concluir/resolver e reabrir.
     */
    public function changeStatus(User $actor, Demand $demand): bool
    {
        return $this->allows($actor, 'operate') && $this->controls($actor, $demand);
    }

    /**
     * Conteúdo, prioridade, prazo, responsável individual e envolvidos.
     */
    public function updateStructure(User $actor, Demand $demand): bool
    {
        return $this->allows($actor, 'operate') && $this->allows($actor, 'route') && $this->controls($actor, $demand);
    }

    public function forward(User $actor, Demand $demand): bool
    {
        return $this->allows($actor, 'route') && $this->controls($actor, $demand);
    }

    /**
     * Setores de destino (encaminhar ou criar para outro setor): só quem tem "route" e, se tiver
     * setor, só no evento do próprio setor. Não altera a visibilidade normal de setores.
     */
    public function routingSectors(User $actor, Event $event): bool
    {
        if (! $this->allows($actor, 'route')) {
            return false;
        }

        return $actor->reachesAllSectors() || $actor->sector?->event_id === $event->id;
    }

    protected function participates(User $actor, Demand $demand): bool
    {
        return $actor->reachesAllSectors() || $demand->involvesSector($actor->sector_id);
    }

    protected function controls(User $actor, Demand $demand): bool
    {
        return $actor->reachesAllSectors() || $actor->sector_id === $demand->responsible_sector_id;
    }

    protected function allows(User $actor, string $action): bool
    {
        return $actor->hasPermission(PermissionCode::from("{$this->prefix()}.{$action}"));
    }
}
