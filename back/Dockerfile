# Imagem do backend Laravel. Imagens oficiais multi-arch (amd64 e arm64).
#
# Alvos:
#   dev  — desenvolvimento: código montado por volume, servidor embutido do PHP na porta 8000
#          (docker-compose.yml usa target: dev).
#   prod — produção (Render): código e vendor dentro da imagem, FrankenPHP (Caddy + PHP) na porta $PORT.
#          É o último estágio, então um build sem --target também gera a imagem de produção.

FROM php:8.3-fpm-bookworm AS base

# Instalador de extensões PHP (resolve dependências do sistema em amd64 e arm64).
COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql mbstring intl zip bcmath opcache pcntl

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

FROM base AS dev

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

EXPOSE 8000

# Servidor embutido do PHP com o roteador do Laravel (o mesmo que "artisan serve" usa).
# Chamado direto para herdar as variáveis do compose (DB_HOST=postgres); "artisan serve" não repassa todas.
# O roteador espera o diretório atual em public/.
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]

# ---------------------------------------------------------------------------------------------------------
# Produção. FrankenPHP = servidor HTTP (Caddy) com PHP embutido, num processo só: sem php-fpm + nginx +
# supervisor. Roda no modo clássico (um request por vez por thread, sem estado entre requests; sem Octane).
# Nenhum segredo entra na imagem: tudo vem das variáveis de ambiente do Render em runtime.
FROM dunglas/frankenphp:1.13.1-php8.3-bookworm AS prod

RUN install-php-extensions pdo_pgsql pgsql intl zip bcmath opcache pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# Padrões não secretos de produção (o Render pode sobrescrever). PORT é definido pelo Render.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    PORT=8080 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Dependências primeiro (camada reaproveitada enquanto composer.lock não muda).
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-autoloader

# Código (.env, vendor local, logs e caches ficam de fora: ver .dockerignore).
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm /usr/local/bin/composer \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data /config \
    && chmod 755 docker/prod/entrypoint.sh docker/prod/release.sh

COPY docker/prod/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/prod/php.ini $PHP_INI_DIR/conf.d/zz-hacklab-prod.ini

# Código só leitura para o processo; escrita só em storage/ e bootstrap/cache/.
USER www-data

EXPOSE 8080

# O Render (e o docker stop) encerram com SIGTERM; o Caddy termina os requests em andamento e sai.
STOPSIGNAL SIGTERM

ENTRYPOINT ["/app/docker/prod/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
