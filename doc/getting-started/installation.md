---
title: Installation
description: Add the Castor Docker plugin to your project.
---

# Installation

## Requirements

* [Castor](https://castor.jolicode.com/) 1.1 or later
* PHP 8.3 or later
* Docker with Compose v2.23 or later (the plugin generates inline `configs`)
* [mkcert](https://github.com/FiloSottile/mkcert) — optional, for locally-trusted HTTPS

## Install the plugin

```bash
castor composer require castor-php/docker
```

That is enough for the plugin to register itself: the `docker:*` tasks appear in
`castor list` as soon as a `castor.php` exists in your project.

## Next step

Head to [your first environment](first-environment.md) to describe a stack, or
let the plugin write it for you with
[`castor docker:service:install`](installing-services.md).
