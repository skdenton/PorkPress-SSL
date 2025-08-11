# PorkPress-SSL
A wordpress multisite plugin for porkbun that can optionally update certbot for SSL.

## WP-CLI

The plugin exposes commands for managing certificates using Certbot's DNS-01
challenge. TXT records are created and removed through a bundled PHP hook
script that talks to Porkbun via their API.

Issue a certificate for one or more domains:

```
wp porkpress ssl:issue --domains="a.com,b.com" [--staging] [--cert-name="porkpress-network"]
```

Renew the certificate for all domains recorded in the manifest:

```
wp porkpress ssl:renew-all [--staging] [--cert-name="porkpress-network"]
```

## Stand‑alone certbot hook

`bin/porkbun-hook.php` can be used as the manual authentication and cleanup
hooks for Certbot. The script loads WordPress to obtain Porkbun credentials and
log activity via the plugin's `Logger`.

Example certbot invocation:

```
certbot certonly \
  --manual --preferred-challenges dns \
  --manual-auth-hook '/path/to/bin/porkbun-hook.php add' \
  --manual-cleanup-hook '/path/to/bin/porkbun-hook.php del' \
  -d example.com
```

Certbot sets `CERTBOT_DOMAIN` and `CERTBOT_VALIDATION` which the hook consumes.
If WordPress is not located automatically, set the environment variable
`WP_LOAD_PATH` to the directory containing `wp-load.php`.

## Certificate and state locations

Certificates are stored under `${PORKPRESS_CERT_ROOT}/live/<cert-name>/` and a
JSON manifest is written to `${PORKPRESS_STATE_ROOT}/manifest.json` describing
the active certificate. By default these roots are `/etc/letsencrypt` and
`/var/lib/porkpress-ssl` respectively. They can be customized in `wp-config.php`:

```
define('PORKPRESS_CERT_ROOT', '/etc/letsencrypt');
define('PORKPRESS_STATE_ROOT', '/var/lib/porkpress-ssl');
```

## Multisite lifecycle

PorkPress SSL listens for WordPress multisite events and updates its domain
alias table automatically. When a site is created, deleted, archived or
restored the plugin adjusts aliases and queues certificate issuance so SSL
coverage remains in sync with the network.
