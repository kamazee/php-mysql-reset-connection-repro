# PHP MySQL connection reset example

This compares PHP `8.6.0alpha3` with
`feat-mysqlnd-com-reset-connection`.

## MySQL

```sh
docker compose up -d --wait database
PHP_VERSION=8.6.0alpha3 docker compose run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection docker compose run --rm php
docker compose down
```

## ClickHouse

```sh
docker compose -f compose.yaml -f compose.clickhouse.yaml up -d --wait database
PHP_VERSION=8.6.0alpha3 \
  docker compose -f compose.yaml -f compose.clickhouse.yaml run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection \
  docker compose -f compose.yaml -f compose.clickhouse.yaml run --rm php
docker compose -f compose.yaml -f compose.clickhouse.yaml down
```

The first PHP version reuses the connection. The feature branch sends a reset.
ClickHouse does not support that reset command.

## SingleStore

Running this example accepts the
[SingleStore Free License Agreement](https://www.singlestore.com/legal/).
It permits one Dev Image for non-production development and testing.

```sh
docker compose -f compose.yaml -f compose.singlestore.yaml up -d --wait database
PHP_VERSION=8.6.0alpha3 \
  docker compose -f compose.yaml -f compose.singlestore.yaml run --rm php
PHP_VERSION=feat-mysqlnd-com-reset-connection \
  docker compose -f compose.yaml -f compose.singlestore.yaml run --rm php
docker compose -f compose.yaml -f compose.singlestore.yaml down
```

SingleStore accepts the reset, keeps the connection, and clears its saved
session value. So, it's here just for the sake of completeness and in case anyone wants to play with it.

## Build locally

If you don't trust a prebuilt image (not sure I would!), just add `-f compose.build.yaml`, and it will not use the prebuilt one.

For MySQL:

```sh
docker compose -f compose.yaml -f compose.build.yaml build php
```

For ClickHouse, use:

```sh
docker compose \
  -f compose.yaml \
  -f compose.clickhouse.yaml \
  -f compose.build.yaml \
  build php
```

Replace `compose.clickhouse.yaml` with `compose.singlestore.yaml` to build the
SingleStore example. Both use `repro-mysql-protocol.php`.

Use the same `-f` options when running the locally built image.
