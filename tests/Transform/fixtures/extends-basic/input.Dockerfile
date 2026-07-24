# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends 'Dockerfile' %}

{% block php_extensions %}
    {{ parent() }}
    "php{{ php_version }}-redis" \
{% endblock %}
