FROM alpine:3

RUN apk update && apk add "php-cli" '<php>'
