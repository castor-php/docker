# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends '@base/Dockerfile' %}

{% block packages %}curl git{% endblock %}
