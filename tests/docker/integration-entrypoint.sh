#!/bin/sh
# Lets the test driver flip variables_order (EGPCS vs GPCS, for the boot-guard test) and the
# Rapira config (dispatcher vs classic) per container without rebuilding the image.
set -e

echo "variables_order = \"${VARIABLES_ORDER:-EGPCS}\"" > /usr/local/etc/php/conf.d/zz-variables-order.ini

exec /usr/local/bin/rapira serve --config "${RAPIRA_CONFIG:-/app/rapira.toml}"
