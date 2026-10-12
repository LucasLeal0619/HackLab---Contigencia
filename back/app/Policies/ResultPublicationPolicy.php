<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Models\User;

/**
 * Prévia e publicação: só Administrador. Resultado PUBLISHED: qualquer perfil autenticado
 * (só o snapshot oficial, sem acesso às telas administrativas).
 */
class ResultPublicationPolicy
{
    public function preview(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::ResultsPreview);
    }

    public function publish(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::ResultsPublish);
    }

    public function viewPublished(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::ResultsViewPublished);
    }
}
