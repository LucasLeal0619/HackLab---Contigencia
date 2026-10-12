<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Auditoria global: só leitura e só com audit_logs.view (Administrador). Sem escopo setorial:
 * os demais perfis não acessam o módulo. Não existem habilidades de criar/alterar/apagar.
 */
class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::AuditLogsView);
    }

    public function view(User $actor, AuditLog $log): bool
    {
        return $actor->hasPermission(PermissionCode::AuditLogsView);
    }
}
