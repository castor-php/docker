FROM debian:13-slim AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
{% block php_extensions %}
    "php{{ php_version }}-cli" \
{% endblock %}
    && apt-get clean
