FROM lucaslorentz/caddy-docker-proxy:2.13.1-alpine

# Base Caddyfile merged with the configuration generated from the Docker labels.
COPY Caddyfile /etc/caddy/Caddyfile

ENV CADDY_DOCKER_CADDYFILE_PATH=/etc/caddy/Caddyfile
