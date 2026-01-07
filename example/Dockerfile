# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends 'Dockerfile' %}

{% block php_extensions %}
    {{ parent() }}
    "php{{ php_version }}-amqp" \
    "php{{ php_version }}-redis" \
    "php{{ php_version }}-mysql" \
{% endblock %}