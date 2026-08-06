# syntax=ghcr.io/castor-php/twig-dockerfile:0.1

# The version goes through a Docker build argument rather than through the
# "rust_version" Twig variable: build arguments are JSON-decoded before they
# reach Twig, which would turn "1.90" into the number 1.9 — and pull the wrong
# image. Shell interpolation keeps the string intact.
ARG rust_version=1

{% block rust_base %}
FROM rust:${rust_version} AS rust-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# The official Rust images install the "minimal" rustup profile, which leaves
# out clippy and rustfmt: add them so the "<name>:qa:*" tasks work out of the
# box. Everything else (sources, build artifacts, registry cache) comes from
# the volumes mounted by the compose file.
{% if rust_components|default([]) %}
RUN rustup component add {{ rust_components|join(' ') }}
{% endif %}

{% if rust_targets|default([]) %}
RUN rustup target add {{ rust_targets|join(' ') }}
{% endif %}

{% for toolchain in rust_toolchains|default([]) %}
RUN rustup toolchain install {{ toolchain.name }}{{ toolchain.components|default([]) ? ' --component ' ~ toolchain.components|join(' --component ') : '' }}
{% endfor %}

WORKDIR /app
{% endblock %}

{% block runtime %}
FROM rust-base AS runtime
{% endblock %}
