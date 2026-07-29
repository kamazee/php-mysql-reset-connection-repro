# syntax=docker/dockerfile:1
ARG DEBIAN_VERSION=bookworm

FROM docker.io/library/debian:${DEBIAN_VERSION} AS build
ARG PHP_BASE_REF=php-8.6.0alpha3
ARG PHP_FEATURE_REF=feat-mysqlnd-com-reset-connection

RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      autoconf bison build-essential ca-certificates curl git libargon2-dev libcurl4-openssl-dev \
      libedit-dev libonig-dev libsqlite3-dev libssl-dev libxml2-dev libzip-dev pkg-config re2c \
      zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src
# The feature repository is a fork, so one clone plus a second remote gives both
# exact source histories without maintaining two unrelated clones.
RUN git clone --filter=blob:none https://github.com/php/php-src.git php-src \
    && cd php-src \
    && git remote add feature https://github.com/ericnorris/php-src.git \
    && git fetch --depth=1 origin "refs/tags/${PHP_BASE_REF}:refs/tags/${PHP_BASE_REF}" \
    && git fetch --depth=1 feature "${PHP_FEATURE_REF}:refs/remotes/feature/${PHP_FEATURE_REF}"

WORKDIR /usr/src/php-src
RUN git worktree add --detach /usr/src/php-base "${PHP_BASE_REF}" \
    && git worktree add --detach /usr/src/php-feature "feature/${PHP_FEATURE_REF}"

RUN set -eux; \
    for pair in \
      "/usr/src/php-base /opt/php/8.6.0alpha3" \
      "/usr/src/php-feature /opt/php/feat-mysqlnd-com-reset-connection"; do \
      set -- $pair; src="$1"; prefix="$2"; \
      cd "$src"; \
      ./buildconf --force; \
      ./configure --prefix="$prefix" --disable-all --enable-cli --enable-mysqlnd \
        --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --enable-pdo --enable-phar \
        --with-libxml --with-openssl --with-zlib; \
      make -j"$(nproc)"; \
      make install; \
      "$prefix/bin/php" -m | grep -Ex '(mysqli|mysqlnd|PDO|pdo_mysql)'; \
    done

RUN set -eux; \
    for prefix in /opt/php/8.6.0alpha3 /opt/php/feat-mysqlnd-com-reset-connection; do \
      for module in mysqli mysqlnd PDO pdo_mysql; do \
        "$prefix/bin/php" -m | grep -qx "$module"; \
      done; \
    done; \
    { \
      printf '8.6.0alpha3 '; \
      git -C /usr/src/php-base rev-parse HEAD; \
      printf 'feat-mysqlnd-com-reset-connection '; \
      git -C /usr/src/php-feature rev-parse HEAD; \
    } > /opt/php/BUILD_REVISIONS

FROM docker.io/library/debian:${DEBIAN_VERSION}-slim
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates libargon2-1 libcurl4 libedit2 libonig5 libsqlite3-0 libssl3 libxml2 libzip4 zlib1g \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /opt/php /opt/php
COPY docker/php/entrypoint.sh /usr/local/bin/php-entrypoint
COPY repro.php /app/repro.php
COPY repro-clickhouse.php /app/repro-clickhouse.php
RUN chmod 0755 /usr/local/bin/php-entrypoint
ENTRYPOINT ["php-entrypoint"]
CMD ["php", "/app/repro.php"]
