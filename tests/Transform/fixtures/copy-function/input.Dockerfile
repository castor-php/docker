# syntax=ghcr.io/castor-php/twig-dockerfile:latest
FROM alpine:3

{{ copy('nginx.conf.twig', '/etc/nginx/nginx.conf') }}
