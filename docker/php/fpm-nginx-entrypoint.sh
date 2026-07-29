#!/bin/sh
set -eu

php-fpm --fpm-config /etc/php-fpm.conf --daemonize
exec nginx -g 'daemon off;'
