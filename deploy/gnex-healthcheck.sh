#!/usr/bin/env bash
set -euo pipefail

if ! systemctl is-active --quiet mariadb || ! mysqladmin ping --silent; then
  systemctl restart mariadb
  logger -t gnex-healthcheck "MariaDB restarted after failed health check"
fi

if ! systemctl is-active --quiet apache2; then
  systemctl restart apache2
  logger -t gnex-healthcheck "Apache restarted because service was inactive"
fi

if ! curl --fail --silent --show-error --max-time 12 \
  --resolve gnexcenter.com:443:127.0.0.1 \
  https://gnexcenter.com/health-check.html >/dev/null; then
  apache2ctl configtest
  systemctl restart apache2
  sleep 2
  curl --fail --silent --show-error --max-time 12 \
    --resolve gnexcenter.com:443:127.0.0.1 \
    https://gnexcenter.com/health-check.html >/dev/null
  logger -t gnex-healthcheck "Apache restarted after failed HTTPS health check"
fi
