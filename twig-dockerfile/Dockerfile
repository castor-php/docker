FROM ubuntu:24.04 AS build-cli
ARG TARGETARCH

# fetch castor from github
WORKDIR /app
RUN apt  update && apt install -y git ca-certificates curl
RUN curl -LO https://github.com/jolicode/castor/releases/download/v1.1.0/castor.linux-amd64 && \
    mv castor.linux-amd64 /app/castor && \
    chmod +x /app/castor

COPY castor.php /app/castor.php
COPY castor.composer.json /app/castor.composer.json
COPY castor.composer.lock /app/castor.composer.lock

RUN /app/castor composer install --no-dev --optimize-autoloader

FROM alpine:3 AS certs
RUN apk --no-cache add ca-certificates

FROM golang:1.25 AS build-frontend
ARG TARGETARCH
WORKDIR /app

# Copy modules first for better caching, then the sources
COPY --link go.mod go.sum  ./
COPY --link *.go           ./

RUN CGO_ENABLED=0 GOOS=linux GOARCH=$TARGETARCH \
    go build -trimpath -buildvcs=false \
    -ldflags='-s -w -extldflags "-static"' \
    -o twig-dockerfile-frontend .

FROM ubuntu:24.04

# BuildKit frontend capabilities (mirrors upstream Dockerfile frontend)
# Ref: https://github.com/moby/buildkit/blob/master/frontend/dockerfile/cmd/dockerfile-frontend/Dockerfile
LABEL moby.buildkit.frontend.network.none="true"
LABEL moby.buildkit.frontend.caps="moby.buildkit.frontend.inputs,moby.buildkit.frontend.subrequests,moby.buildkit.frontend.contexts"

# Keep PATH minimal; put binaries at root so ENTRYPOINT can reference them directly
ENV PATH=/ \
    DOCKERFILEX_TMPDIR=/workspace

# Run as non-root and default to a writeable working directory
USER 1000
WORKDIR /workspace

# Copy only the necessary runtime artifacts
COPY --link --from=certs          /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --link --from=build-frontend /app/twig-dockerfile-frontend /
COPY --link --from=build-cli      /app/ /

# The Go frontend is the entrypoint; the CLI binary is also available in PATH
ENTRYPOINT ["/twig-dockerfile-frontend"]