# PorkPress-SSL
A wordpress multisite plugin for porkbun that can optionally update certbot for SSL.

## WP-CLI

The plugin exposes commands for managing certificates using Certbot's DNS-01
challenge. TXT records are created and removed through bundled hook scripts
for Porkbun.

Issue a certificate for one or more domains:

```
wp porkpress ssl:issue --domains="a.com,b.com" [--staging] [--cert-name="porkpress-network"]
```

Renew the certificate for all domains recorded in the manifest:

```
wp porkpress ssl:renew-all [--staging] [--cert-name="porkpress-network"]
```

## Porkbun API credentials

The plugin requires a Porkbun API key and secret to manage domains. They can be
supplied either through constants in `wp-config.php`:

```
define('PORKPRESS_API_KEY', 'pk_...');
define('PORKPRESS_API_SECRET', 'sk_...');
```

or via the network settings stored in the options `porkpress_ssl_api_key` and
`porkpress_ssl_api_secret`.

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
