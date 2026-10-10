FROM dunglas/frankenphp:1-php8.5

# PHP extensions Laravel needs here: Postgres, Redis, queues (pcntl), intl, zip, GD (thumbnails).
RUN install-php-extensions pdo_pgsql redis pcntl intl zip opcache gd @composer

# Run as the host user so files created in the bind mount stay editable.
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app \
    && chown -R app:app /data/caddy /config/caddy

USER app
WORKDIR /app

# Port 8000 so the server can start without root. Serves /app/public.
ENV SERVER_NAME=:8000
EXPOSE 8000
