# PHP MySQL connection reset example

This compares PHP `8.6.0alpha3` with `feat-mysqlnd-com-reset-connection`.

## MySQL

```sh
PHP_VERSION=8.6.0alpha3 docker compose run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection docker compose run --rm php
docker compose down
```

## Podman

Do not use `podman compose run`: it starts `database` but does not wait for its health check. Use the `php` service instead:

```sh
PHP_VERSION=8.6.0alpha3 podman compose up --exit-code-from php php
PHP_VERSION=feat-mysqlnd-com-reset-connection podman compose up --exit-code-from php php
podman compose down
```

## ClickHouse

```sh
PHP_VERSION=8.6.0alpha3 docker compose -f compose.yaml -f compose.clickhouse.yaml run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection docker compose -f compose.yaml -f compose.clickhouse.yaml run --rm php
docker compose -f compose.yaml -f compose.clickhouse.yaml down
```

The first PHP version reuses the connection. The feature branch sends a reset. ClickHouse does not support that reset command.

## SingleStore

SingleStore accepts the reset, keeps the connection, and clears its saved session value. It is included for completeness.

Running this example accepts the [SingleStore Free License Agreement](https://www.singlestore.com/legal/). It permits one Dev Image for non-production development and testing.

```sh
PHP_VERSION=8.6.0alpha3 docker compose -f compose.yaml -f compose.singlestore.yaml run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection docker compose -f compose.yaml -f compose.singlestore.yaml run --rm php
docker compose -f compose.yaml -f compose.singlestore.yaml down
```

## PHP-FPM

This uses a persistent PDO connection. A shutdown function drops a temporary table, then releases the connection after each request. The image includes `curl`.

```sh
PHP_VERSION=8.6.0alpha3 docker compose -f compose.yaml -f compose.fpm.yaml -f compose.build.yaml up --build -d --wait
docker compose -f compose.yaml -f compose.fpm.yaml -f compose.build.yaml exec php curl --fail --silent http://127.0.0.1:8080/repro-fpm.php
docker compose -f compose.yaml -f compose.fpm.yaml -f compose.build.yaml exec php curl --fail --silent http://127.0.0.1:8080/repro-fpm.php
docker compose -f compose.yaml -f compose.fpm.yaml -f compose.build.yaml down
```

Run it again with `PHP_VERSION=feat-mysqlnd-com-reset-connection`. The first build keeps the marker on the second request. The feature build clears it and restores the MySQL defaults.

## Build locally

If you don't trust a prebuilt image (not sure I would!), just add `-f compose.build.yaml`, and it will not use the prebuilt one.
