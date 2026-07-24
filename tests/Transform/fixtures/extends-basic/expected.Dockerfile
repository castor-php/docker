FROM debian:13-slim AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "php8.4-cli" \

    "php8.4-redis" \
    && apt-get clean
