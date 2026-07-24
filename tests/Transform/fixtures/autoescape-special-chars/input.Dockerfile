# syntax=ghcr.io/castor-php/twig-dockerfile:latest
FROM alpine:3

RUN {{ install_cmd }}
