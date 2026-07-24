FROM alpine:3

COPY <<'EOF' /etc/nginx/nginx.conf
worker_processes 4;
# built for php 8.4

EOF

