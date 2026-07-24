FROM alpine:3

COPY <<'EOF' /etc/motd
# This comment must survive the transform
line two
EOF

# a later comment that must also survive
RUN echo done
