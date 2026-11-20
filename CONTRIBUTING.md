# Contributing

## Setup

```bash
composer install
composer test
```

`composer test` runs PHPUnit, which boots Laravel through Orchestra Testbench, and PHPStan at level max with Larastan over `src/` and the usage examples in `tests/Typecheck/`.

## What lives where

The API client, its types and webhook verification are in [inboundr/inboundr](https://github.com/inboundr-net/inboundr-php). This package only wires them into Laravel: the service provider, config, facade, webhook route, middleware and event. Changes to how signatures are checked belong in `inboundr/inboundr`.

## Releasing

1. Merge to `main`.
2. Tag `v<version>` and push the tag.

Packagist reads versions from the repository's tags, so the tag push is the release. CI runs the tests on Laravel 12 (PHP 8.2 to 8.5) and Laravel 13 (PHP 8.3 to 8.5), and with the lowest allowed dependencies.
