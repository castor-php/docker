# syntax=ghcr.io/castor-php/twig-dockerfile:latest
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
