# The shop's Dockerfile as deps:check reads it, cut to its pins.

FROM php:8.5-cli@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663 AS php

FROM php:8.5-cli@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663 AS build

# FrankenPHP's official static binary, v1.13.3 with PHP 8.5.12, the build the
# shop runs everywhere (ADR 0053): the glibc one, whose allocator is glibc's.
# And tini, v0.19.0, the container's PID 1 once the entrypoint has started
# both sides. Sums from each release's assets.
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
    echo "$tini_sha256  /out/bin/tini" | sha256sum --check --strict --quiet -

FROM debian:trixie-slim@sha256:62bd423aeee912639ca8ee681c2b762cb3e25137e0a92b20be455898a9f034ad AS pod-renderer
