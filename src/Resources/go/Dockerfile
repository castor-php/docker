# syntax=ghcr.io/castor-php/twig-dockerfile:0.1

# The version goes through a Docker build argument rather than through the
# "go_version" Twig variable: build arguments are JSON-decoded before they
# reach Twig, which would turn "1.20" into the number 1.2 — and pull the wrong
# image. Shell interpolation keeps the string intact.
ARG go_version=1

{% block go_base %}
FROM golang:${go_version} AS go-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# The sources, the build artifacts and the module cache all come from the
# volumes mounted by the compose file: nothing is copied into the image.
WORKDIR /app
{% endblock %}

{% block runtime %}
FROM go-base AS runtime
{% endblock %}
