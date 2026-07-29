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

The first PHP version keeps the connection settings. The feature branch resets
them, which breaks the stored `café` text. With ClickHouse, it opens a new
connection because ClickHouse does not support the reset command.

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

Use the same `-f` options when running the locally built image.
