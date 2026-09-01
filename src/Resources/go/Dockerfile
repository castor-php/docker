{#
    No "# syntax=" line: the frontend is pinned by the BUILDKIT_SYNTAX build
    argument the plugin sets on every service it generates, and BuildKit honours
    that over the directive. Carrying it too would cost this file the one thing
    a shipped template is for — a template that extends another one cannot hold
    text outside its blocks, and the directive is text, so the day this file
    extends anything, every project extending it breaks on line 1.
#}

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
