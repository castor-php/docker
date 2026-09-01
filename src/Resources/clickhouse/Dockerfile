{#
    No "# syntax=" line: the frontend is pinned by the BUILDKIT_SYNTAX build
    argument the plugin sets on every service it generates, and BuildKit honours
    that over the directive. Carrying it too would cost this file the one thing
    a shipped template is for — a template that extends another one cannot hold
    text outside its blocks, and the directive is text, so the day this file
    extends anything, every project extending it breaks on line 1.
#}
# hadolint global ignore=DL3008

{% block Dockerfile %}
FROM clickhouse/clickhouse-server:{{ clickhouse_version }}

{% if backup %}
RUN wget https://github.com/Altinity/clickhouse-backup/releases/download/v2.4.12/clickhouse-backup-linux-amd64.tar.gz && \
    tar -xzf clickhouse-backup-linux-amd64.tar.gz && \
    mv build/linux/amd64/clickhouse-backup /usr/bin/clickhouse-backup && \
    rm clickhouse-backup-linux-amd64.tar.gz
{% endif %}

COPY ./config.xml /etc/clickhouse-server/config.d/app.xml

EXPOSE 8123
{% endblock %}
