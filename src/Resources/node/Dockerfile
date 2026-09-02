{#
    No "# syntax=" line: the frontend is pinned by the BUILDKIT_SYNTAX build
    argument the plugin sets on every service it generates, and BuildKit honours
    that over the directive. Carrying it too would cost this file the one thing
    a shipped template is for — a template that extends another one cannot hold
    text outside its blocks, and the directive is text, so the day this file
    extends anything, every project extending it breaks on line 1.
#}

# The version goes through a Docker build argument rather than through the
# "node_version" Twig variable: build arguments are JSON-decoded before they
# reach Twig, which would turn "20.10" into the number 20.1 — and pull the wrong
# image. Shell interpolation keeps the string intact.
ARG node_version=24

{% block node_base %}
FROM node:${node_version} AS node-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Corepack lays down a shim for npm, yarn and pnpm alike, so the manager a
# project pins in the "packageManager" field of its package.json is the one that
# runs — whatever withPackageManager() says. It downloads that manager on first
# use, into the COREPACK_HOME the compose file points at the shared home
# directory: the download is therefore made once for the whole project, and
# survives a rebuild of this image.
#
# Node 25 dropped corepack from the distribution, the earlier majors ship it.
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
RUN { command -v corepack > /dev/null || npm install --global corepack; } \
    && corepack enable

# The sources, node_modules and the manager caches all come from the volumes
# mounted by the compose file: nothing is copied into the image.
WORKDIR /app
{% endblock %}

{% block runtime %}
FROM node-base AS runtime
{% endblock %}
