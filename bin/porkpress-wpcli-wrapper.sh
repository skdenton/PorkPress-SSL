#!/bin/sh
# Simple wrapper to trigger PorkPress SSL renewal via WP-CLI.
# This can be invoked by cron or systemd timers.
WP=${WP:-wp}
exec "$WP" porkpress ssl:renew-all "$@"
