# The app in one container, two processes: FrankenPHP in worker mode as one
# user, and the renderers as another, with nothing root's once both are
# started. FrankenPHP's static binary serves the requests; every other PHP
# process -- serve, a one-off command -- runs PHP's own CLI. Built from the
# app's root, after composer install and npm install have written both
# lockfiles:
#
#   docker build -t my-app .
#
# and run as compose.yaml runs it: a read-only root, a tmpfs for /tmp and
# one for the renderers' pool, a volume for the database, every capability
# dropped but the three the entrypoint needs to start the two users -- and
# drops before it is done -- and the app key given at run time. The image
# holds no secret.
#
# This is the framework's own image (examples/shop/Dockerfile in its
# repository, ADR 0059, 0063, 0068) with the shop's parts taken out: the
# bases, the binaries and their sums, the users, the entrypoint and the
# healthcheck are the same for any app. Every base is pinned by digest and
# every binary fetched is held to its sha256; `bin/tinvar check` refuses a
# Dockerfile whose images are not pinned. To move one, change it and its sum
# together, from the project's own published list.

# --------------------------------------------------------------------- build
# PHP 8.5 with the extensions the app needs, Composer and Node, each at one
# version held to a sha256 (docker/setup). The build happens here and
# nothing of this stage but its output ships.
FROM php:8.5-cli@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663 AS build

COPY docker/setup /usr/local/lib/setup
RUN /usr/local/lib/setup >/dev/null

# FrankenPHP's official static binary, v1.13.3 with PHP 8.5.12, the glibc
# one, whose allocator is glibc's; and tini, v0.19.0, the container's PID 1
# once the entrypoint has started both sides. Sums from each release's
# assets; `tinvar deps:check` in the framework's repository moves them with
# the shop's.
ARG TARGETARCH
RUN set -eu; \
    case "$TARGETARCH" in \
        arm64) \
            frankenphp=frankenphp-linux-aarch64-gnu; \
            frankenphp_sha256=53aca1dfd4496cd1cb1d1954d0e9a5896e60c65dc7a61ebd7a821c69bdcea539; \
            tini=tini-static-arm64; \
            tini_sha256=eae1d3aa50c48fb23b8cbdf4e369d0910dfc538566bfd09df89a774aa84a48b9 ;; \
        amd64) \
            frankenphp=frankenphp-linux-x86_64-gnu; \
            frankenphp_sha256=d210c06bfcd7ed974c05376fc7fa9c300b1db8cd2a7c793bed5ed8d087b3f0ef; \
            tini=tini-static-amd64; \
            tini_sha256=c5b0666b4cb676901f90dfcb37106783c5fe2077b04590973b885950611b30ee ;; \
        *) echo "No FrankenPHP or tini is pinned for $TARGETARCH." >&2; exit 1 ;; \
    esac; \
    mkdir -p /out/bin; \
    curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o /out/bin/frankenphp \
        "https://github.com/php/frankenphp/releases/download/v1.13.3/$frankenphp"; \
    echo "$frankenphp_sha256  /out/bin/frankenphp" | sha256sum --check --strict --quiet -; \
    curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o /out/bin/tini \
        "https://github.com/krallin/tini/releases/download/v0.19.0/$tini"; \
    echo "$tini_sha256  /out/bin/tini" | sha256sum --check --strict --quiet -; \
    install -m 0755 /usr/local/bin/node /out/bin/node; \
    chmod 0755 /out/bin/frankenphp /out/bin/tini

WORKDIR /src
COPY . .

# Both from their lockfiles alone, and neither runs a package's install
# script; npm ci checks every package's sha512. Composer without the dev
# dependencies: no Pest, PHPUnit, codegen or tinvar/testing in the image.
RUN test -f composer.lock && test -f package-lock.json \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && npm ci --ignore-scripts --no-audit --no-fund

# The build, and the fragment manifest derived from it and the routes alone,
# which `bin/serve --prebuilt` then leaves as it is. Then what the renderers
# load at run time, and nothing they would only build with.
RUN npx vite build \
    && php bin/tinvar build:fragments \
    && npm prune --omit=dev --ignore-scripts --no-audit --no-fund

# The tree as it ships: root's, so neither process can change a file; what
# only PHP reads is in PHP's group and closed to others; what the renderers
# read is open to them; a directory on the way only lets them pass.
RUN set -eu; \
    app=10001; \
    out=/out/srv/app; \
    mkdir -p "$out"; \
    cp -a app bin database public resources vendor node_modules dist Caddyfile \
        composer.json composer.lock package.json package-lock.json "$out/"; \
    rm -rf "$out/node_modules/.vite" "$out/database"/*.sqlite*; \
    chown -R 0:"$app" "$out"; \
    find "$out" -type d -exec chmod 0750 {} +; \
    find "$out" -type f -exec chmod 0640 {} +; \
    find "$out" -type l -exec chown -h 0:"$app" {} +; \
    chmod 0750 "$out"/bin/*; \
    for read in node_modules dist; do \
        find "$out/$read" -type d -exec chmod 0755 {} +; \
        find "$out/$read" -type f -exec chmod 0644 {} +; \
    done; \
    chmod 0644 "$out/package.json"; \
    chmod 0751 "$out"

# ----------------------------------------------------------------------- php
# The runtime's base: the same PHP image, with the two extensions the
# console processes need compiled from the source it carries -- pcntl and
# sockets -- and then everything that builds or fetches taken out again:
# the compilers and headers, PHP's source, phpize, PEAR and pecl, curl's
# command and xz, and /var/www, which anyone may write.
FROM php:8.5-cli@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663 AS php

COPY --from=build --chmod=0644 /src/vendor/tinvar/framework/resources/container/php.ini /usr/local/etc/php/php.ini
RUN set -eu; \
    docker-php-ext-install -j"$(nproc)" pcntl sockets >/dev/null; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get purge -y -qq --auto-remove $PHPIZE_DEPS libc6-dev curl xz-utils >/dev/null; \
    rm -rf /usr/src/php.tar.xz /usr/src/php.tar.xz.asc /usr/local/include /usr/local/php \
        /usr/local/bin/docker-php-* /usr/local/bin/pear /usr/local/bin/peardev /usr/local/bin/pecl \
        /usr/local/bin/phar /usr/local/bin/phar.phar /usr/local/bin/php-cgi /usr/local/bin/php-config \
        /usr/local/bin/phpdbg /usr/local/bin/phpize /var/lib/apt/lists/* \
        /var/www; \
    find /usr/local/lib/php -mindepth 1 -maxdepth 1 ! -name extensions -exec rm -rf {} +; \
    for extension in pcntl posix sockets pdo_sqlite sodium mbstring openssl curl; do \
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension" \
            || { echo "PHP has no $extension extension." >&2; exit 1; }; \
    done; \
    for tool in cc gcc g++ ld make curl phpize pecl pear; do \
        if command -v "$tool" >/dev/null; then echo "$tool is still in the runtime." >&2; exit 1; fi; \
    done

# ------------------------------------------------------------------- runtime
FROM scratch AS runtime
COPY --from=php / /
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# PHP's user, the renderers' user, and the socket group of exactly the two:
# the renderers' primary group, and PHP's only other one. Fixed ids, so a
# volume or a tmpfs can name them.
RUN set -eu; \
    groupadd --gid 10001 tinvar-app; \
    useradd --uid 10001 --gid tinvar-app --no-create-home --home-dir /nonexistent \
        --shell /usr/sbin/nologin tinvar-app; \
    groupadd --gid 10003 tinvar-render; \
    useradd --uid 10002 --gid tinvar-render --no-create-home --home-dir /nonexistent \
        --shell /usr/sbin/nologin tinvar-renderer; \
    usermod --append --groups tinvar-render tinvar-app; \
    install -d -o 0 -g 0 -m 0755 /var/lib/app; \
    install -d -o tinvar-app -g tinvar-app -m 0700 /var/lib/app/data; \
    install -d -o tinvar-renderer -g tinvar-render -m 0750 /run/tinvar-renderer

COPY --from=build /out/bin/ /usr/local/bin/
COPY --from=build --chmod=0755 /src/vendor/tinvar/framework/resources/container/entrypoint \
    /src/vendor/tinvar/framework/resources/container/supervise \
    /src/vendor/tinvar/framework/resources/container/renderers \
    /src/vendor/tinvar/framework/resources/container/healthcheck \
    /usr/local/lib/tinvar/
# Copied, not COPYed: COPY makes every file root's, and the modes and the
# group above are the point. And the two PHPs held to one minor version: the
# CLI and the server's workers run the same code.
RUN --mount=type=bind,from=build,source=/out/srv,target=/mnt/srv set -eu; \
    cp -a /mnt/srv/app /srv/; \
    version='echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'; \
    cli=$(php -r "$version"); \
    server=$(frankenphp php-cli -r "$version"); \
    [ "$cli" = "$server" ] \
        || { echo "PHP's CLI is $cli and FrankenPHP's PHP is $server: they must be one minor version." >&2; exit 1; }

# What the entrypoint is told, and what the app reads. No secret: the app
# key and the probe token are given at run time.
ENV TINVAR_ENV=production \
    TINVAR_CONTAINER_APP_USER=tinvar-app \
    TINVAR_RENDERER_USER=tinvar-renderer \
    TINVAR_CONTAINER_SOCKET_GROUP=tinvar-render \
    TINVAR_SOCKET=/run/tinvar-renderer \
    TINVAR_CONTAINER_PORT=8080 \
    TINVAR_PROBE_TRUST_LOOPBACK=0 \
    TINVAR_FRANKENPHP=/usr/local/bin/frankenphp \
    SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data
# Where the app is, how it serves, what renders it, and what is PHP's alone,
# which the entrypoint checks the renderers' user cannot read.
ENV TINVAR_CONTAINER_CWD=/srv/app \
    TINVAR_CONTAINER_TINVAR=/srv/app/bin/tinvar \
    TINVAR_CONTAINER_SERVE="/srv/app/bin/serve --prebuilt" \
    TINVAR_CONTAINER_RENDERER=/srv/app/node_modules/@tinvar/renderer/bin/renderer.js \
    TINVAR_CONTAINER_RENDER_ENTRY=/srv/app/dist/rsc/index.js \
    TINVAR_CONTAINER_PRIVATE=/var/lib/app/data:/srv/app/vendor:/srv/app/app:/srv/app/Caddyfile \
    APP_DATABASE=/var/lib/app/data/app.sqlite

# The only place either process writes, beside the two tmpfs mounts.
VOLUME ["/var/lib/app/data"]
EXPOSE 8080

# As nobody, over loopback, with the probe token: the renderers share this
# container's loopback, so it is not trusted without one
# (TINVAR_PROBE_TRUST_LOOPBACK=0 above), and the entrypoint refuses to serve
# with none given.
HEALTHCHECK --interval=15s --timeout=6s --start-period=60s --retries=3 \
    CMD ["/usr/local/lib/tinvar/healthcheck"]

# Root, for the entrypoint alone, and only until both sides are started: it
# starts each process as its user with CAP_SETUID and CAP_SETGID, empties
# each one's bounding set with CAP_SETPCAP, and then becomes tini, as PHP's
# user. From then on no process in the container is root or holds a
# capability.
ENTRYPOINT ["/usr/local/lib/tinvar/entrypoint"]
