# syntax=castor/twig-dockerfile:latest
{% extends '@original/Dockerfile' %}

{% block php_extensions %}
    {{ parent() }}
    "php{{ php_version }}-amqp" \
    "php{{ php_version }}-redis" \
{% endblock %}