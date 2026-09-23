# Always passed by RabbitMQService, from withVersion().
ARG rabbitmq_version=4.3

FROM rabbitmq:${rabbitmq_version}-management-alpine

COPY etc/. /etc/
