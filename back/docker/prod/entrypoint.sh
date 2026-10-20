#!/bin/sh
# Entrypoint do target prod. Roda antes do servidor (e antes de qualquer comando passado no lugar do CMD,
# como o "php artisan even3:sync" de um Cron Job).
set -e

# Caches de config, rotas, eventos e views só agora: as variáveis do Render existem em runtime, não no build.
php artisan optimize --no-interaction

# Migrations + perfis/permissões (RolePermissionSeeder é idempotente; sem ele não há perfis). O caminho normal é o
# Pre-Deploy Command do Render (docker/prod/release.sh). Sem pre-deploy (plano Free), HACKLAB_MIGRATE_ON_START=true
# roda o mesmo passo aqui, antes de aceitar requests. Nunca migrate:fresh/db:wipe: o DestructiveCommandGuard bloqueia.
if [ "${HACKLAB_MIGRATE_ON_START:-false}" = "true" ]; then
    /app/docker/prod/release.sh
fi

exec "$@"
