{#
    No "# syntax=" line: the frontend is pinned by the BUILDKIT_SYNTAX build
    argument the plugin sets on every service it generates, and BuildKit honours
    that over the directive. Carrying it too would cost this file the one thing
    a shipped template is for — a template that extends another one cannot hold
    text outside its blocks, and the directive is text, so the day this file
    extends anything, every project extending it breaks on line 1.
#}
# hadolint global ignore=DL3008

{% block php_base %}
FROM debian:13.5-slim AS php-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
        gnupg \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ trixie main" > /etc/apt/sources.list.d/sury.list \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash-completion \
        procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "php{{ php_version }}-cli" \
        "php{{ php_version }}-common" \
{% for extension in php_extensions %}
        "php{{ php_version }}-{{ extension }}" \
{% endfor %}
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Configuration
COPY base/php-configuration /etc/php/{{ php_version }}

ENV HOME=/home/app
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www
{% endblock %}

{% block frontend %}
FROM php-base AS frontend

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        "php{{ php_version }}-fpm" \
        runit \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && rm -r "/etc/php/{{ php_version }}/fpm/pool.d/"

RUN useradd -s /bin/false nginx

COPY frontend/php-configuration /etc/php/{{ php_version }}
COPY frontend/etc/nginx/environments /etc/nginx/environments
{{ copy('frontend/etc/nginx/nginx.conf.twig', '/etc/nginx/nginx.conf') }}

RUN rm -rf /etc/service/
COPY frontend/etc/service/. /etc/service/
RUN chmod 777 /etc/service/*/supervise/

RUN phpenmod app-default \
    && phpenmod app-fpm

ENV PHP_VERSION={{ php_version }}

EXPOSE 80

CMD ["runsvdir", "-P", "/etc/service"]
{% endblock %}

{% block worker %}
FROM php-base AS worker

# php-base carries the configuration but enables nothing, and a worker runs the
# same commands the builder does: without this it ran on the Debian defaults,
# which is where its "memory_limit = -1" came from.
RUN phpenmod app-default
{% endblock %}

{% block builder %}
FROM php-base AS builder

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# The key is used armoured, as it is published: dearmouring it needs gnupg,
# which the FrankenPHP image this block also builds on does not carry. apt reads
# an armoured keyring as long as the file is named ".asc".
RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key -o /etc/apt/keyrings/nodesource.asc \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.asc] https://deb.nodesource.com/node_{{ node_version }} nodistro main" > /etc/apt/sources.list.d/nodesource.list

# Default toys
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "git" \
        "make" \
        "nodejs" \
{% block builder_php_dev %}
        "php{{ php_version }}-dev" \
{% endblock %}
        "sudo" \
        "unzip" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Node 25 dropped corepack from the distribution, the earlier majors ship it.
# Enabling it lays down the shim of every manager, so a "packageManager" field
# in a package.json is honoured whichever one is prepared below.
RUN { command -v corepack > /dev/null || npm install --global corepack; } \
    && corepack enable
{% if package_manager == 'yarn' %}
RUN yarn set version stable
{% elseif package_manager == 'pnpm' %}
RUN corepack prepare pnpm@latest --activate
{% endif %}

{% if sudo|default('false') == 'true' %}
# A passwordless sudo, asked for with withSudo(). It is a two line script around
# gosu, so whoever reaches this container is root in it without knowing
# anything: convenient while developing, a hole anywhere else. Never in an image
# that leaves a developer machine.
ARG TARGETARCH
COPY base/sudo.sh /usr/local/bin/sudo
RUN curl -fL "https://github.com/tianon/gosu/releases/download/1.16/gosu-${TARGETARCH}" -o /usr/local/bin/gosu \
    && chmod u+s /usr/local/bin/gosu \
    && chmod +x /usr/local/bin/gosu \
    && chmod +x /usr/local/bin/sudo
{% endif %}

# Config
{% block builder_php_configuration %}
COPY builder/php-configuration /etc/php/{{ php_version }}
RUN phpenmod app-default \
    && phpenmod app-builder
{% endblock %}

# Composer
COPY --from=composer/composer:2.9.2 /usr/bin/composer /usr/bin/composer

# Pie
RUN curl -L --output /usr/local/bin/pie https://github.com/php/pie/releases/download/1.0.0/pie.phar \
    && chmod +x /usr/local/bin/pie

# Autocompletion
ADD https://raw.githubusercontent.com/symfony/symfony/refs/heads/7.3/src/Symfony/Component/Console/Resources/completion.bash /tmp/completion.bash

# Composer symfony/console version is too old, and doest not support "API version feature", so we remove it
# Hey, while we are at it, let's add some more completion
RUN sed /tmp/completion.bash \
        -e "s/{% verbatim %}{{ COMMAND_NAME }}{% endverbatim %}/composer/g" \
        -e 's/"-a{% verbatim %}{{ VERSION }}{% endverbatim %}"//g' \
        -e "s/{% verbatim %}{{ VERSION }}{% endverbatim %}/1/g"  \
        > /etc/bash_completion.d/composer \
    && sed /tmp/completion.bash \
        -e "s/{% verbatim %}{{ COMMAND_NAME }}{% endverbatim %}/console/g" \
        -e "s/{% verbatim %}{{ VERSION }}{% endverbatim %}/1/g"  \
        > /etc/bash_completion.d/console

# Third party tools
ENV PATH="$PATH:/var/www/tools/bin"

# Good default customization
RUN cat >> /etc/bash.bashrc <<EOF
. /etc/bash_completion

PS1='\[\e[01;33m\]\u \[\e[00;32m\]\w\[\e[0m\] '
EOF

WORKDIR /var/www
{% endblock %}
