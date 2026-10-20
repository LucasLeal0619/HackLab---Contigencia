#!/bin/sh
# Passo de release do target prod: Pre-Deploy Command do Render ("/app/docker/prod/release.sh"),
# ou no start com HACKLAB_MIGRATE_ON_START=true. Repetível: migrations pendentes + perfis/permissões do código.
set -e

php artisan migrate --force --no-interaction
php artisan db:seed --class=RolePermissionSeeder --force --no-interaction
