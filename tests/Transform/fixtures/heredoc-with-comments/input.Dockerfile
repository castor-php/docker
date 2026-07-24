# syntax=ghcr.io/castor-php/twig-dockerfile:latest
# hadolint global ignore=DL3008
FROM alpine:3

COPY <<'EOF' /etc/motd
# This comment must survive the transform
line two
EOF

# a later comment that must also survive
RUN echo done
