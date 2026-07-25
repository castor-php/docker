ARG rust_version=1

FROM rust:${rust_version}

# The official Rust images install the "minimal" rustup profile, which leaves
# out clippy and rustfmt: add them so the "<name>:qa:*" tasks work out of the
# box. Everything else (sources, build artifacts, registry cache) comes from
# the volumes mounted by the compose file.
RUN rustup component add clippy rustfmt

WORKDIR /app
