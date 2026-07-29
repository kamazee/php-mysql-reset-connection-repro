#!/bin/sh
set -eu

selected="${PHP_VERSION:-8.6.0alpha3}"
case "$selected" in
  8.6.0alpha3|feat-mysqlnd-com-reset-connection) ;;
  *)
    echo "PHP_VERSION must be 8.6.0alpha3 or feat-mysqlnd-com-reset-connection; got: $selected" >&2
    exit 64
    ;;
esac

prefix="/opt/php/$selected"
if [ ! -x "$prefix/bin/php" ]; then
  echo "Selected PHP binary is missing: $prefix/bin/php" >&2
  exit 70
fi
export PATH="$prefix/bin:$PATH"
exec "$@"
