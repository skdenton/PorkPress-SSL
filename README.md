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

`bin/porkpress-hook.php` can be used as the manual authentication and cleanup
hooks for Certbot. The script loads WordPress to obtain Porkbun credentials and
log activity via the plugin's `Logger`.

Example certbot invocation:

```
certbot certonly \
  --manual --preferred-challenges dns \
  --manual-auth-hook '/path/to/bin/porkpress-hook.php add' \
  --manual-cleanup-hook '/path/to/bin/porkpress-hook.php del' \
  -d example.com
```

Certbot sets `CERTBOT_DOMAIN`, `CERTBOT_VALIDATION` and `CERTBOT_TOKEN` which
the hook logs. If WordPress is not located automatically, either pass
`--wp-root=/path/to/wordpress` or set the environment variable `WP_ROOT` or
`WP_LOAD_PATH` to the directory containing `wp-load.php`. The hook also reads
configuration from `/etc/default/porkpress-ssl` when present.

## Porkbun API credentials

The plugin requires a Porkbun API key and secret to manage domains. They can be
supplied either through constants in `wp-config.php`:

```
define('PORKPRESS_API_KEY', 'pk_...');
define('PORKPRESS_API_SECRET', 'sk_...');
```

or via the network settings stored in the options `porkpress_ssl_api_key` and
`porkpress_ssl_api_secret`.

When running the stand-alone Certbot hook, credentials may also be provided via
environment variables `PORKBUN_API_KEY` and `PORKBUN_API_SECRET` (aliases
`PORKPRESS_API_KEY` and `PORKPRESS_API_SECRET` are accepted for backward
compatibility).

## DNS records

For each domain attached to the network the plugin splits the fully qualified
name into the zone and any subdomain labels. Domains with only two labels are
treated as apex domains (for example `example.com`), while domains with more
labels are treated as subdomains (for example `blog.example.com`). The records
it manages differ for each case:

* **Apex domain** – `A` and `AAAA` records are created pointing to the
  network's IPv4 and IPv6 addresses. A `www` `CNAME` is also ensured so that
  `www.example.com` resolves to the apex.
* **Subdomain** – `A` and `AAAA` records are created for the subdomain. No
  `www` alias is added by default.

All DNS operations use Porkbun's API v3 endpoints for record
creation, retrieval, editing and deletion.

### Optional `www` CNAMEs for subdomains

If a `www` alias should be created for a subdomain such as
`www.blog.example.com`, enable the filter:

```php
add_filter( 'porkpress_ssl_add_www_cname', '__return_true' );
```

Explicitly disabling the alias can be done with:

```php
add_filter( 'porkpress_ssl_add_www_cname', '__return_false' );
```

The filter receives the current setting and the full domain, allowing
conditional logic:

```php
add_filter( 'porkpress_ssl_add_www_cname', function( $enabled, $domain ) {
    return $domain === 'shop.example.com';
}, 10, 2 );
```

### Editing and deleting records

The bundled Porkbun client provides helpers for modifying or removing
DNS entries through the v3 API:

```php
$client = new PorkPress\SSL\Porkbun_Client( 'pk_...', 'sk_...' );

// Update an A record.
$client->edit_record( 'example.com', 123, 'A', '', '198.51.100.5', 600 );

// Delete the record.
$client->delete_record( 'example.com', 123 );
```

### Validation, errors and troubleshooting

DNS arguments are validated before any API call is made. The record `type` must be one of Porkbun's supported types (`A`, `AAAA`, `CNAME`, `TXT`, `MX`, `SRV`, `NS`, `PTR`, `CAA` or `ALIAS`) and the `ttl` value must be an integer. Requests failing validation or the API itself will return a `WP_Error` or `Porkbun_Client_Error` object describing the issue.

Network administrators can inspect detailed request and response logs from **PorkPress SSL → Logs** in the Network Admin. Logs can be filtered by severity or exported for offline analysis.

Troubleshooting tips:

* Ensure the record type and TTL are valid.
* Verify the domain uses Porkbun nameservers and that API credentials are correct.
* Check the plugin logs for the error message returned by the API.
* Remove conflicting records or wait for DNS propagation before retrying.

## Enabling `sunrise.php`

To enable sunrise functionality, add the following to `wp-config.php`:

```
define('SUNRISE', true);
```

Then copy the plugin's `sunrise.php` file into your WordPress installation:

```
cp /path/to/porkpress-ssl/sunrise.php /path/to/wordpress/wp-content/sunrise.php
```

## Certificate and state locations

Certbot's default directories are used so existing lineages can be discovered.
If customization is required, `PORKPRESS_CERT_ROOT`, `PORKPRESS_WORK_DIR` and
`PORKPRESS_LOGS_DIR` constants (or corresponding network options) are respected
and surfaced in health checks. A JSON manifest describing the active
certificate is written to `${PORKPRESS_STATE_ROOT}/manifest.json` which defaults
to `/var/lib/porkpress-ssl` and can also be overridden in `wp-config.php`:

```
define('PORKPRESS_CERT_ROOT', '/etc/letsencrypt');
define('PORKPRESS_WORK_DIR', '/var/lib/letsencrypt');
define('PORKPRESS_LOGS_DIR', '/var/log/letsencrypt');
define('PORKPRESS_STATE_ROOT', '/var/lib/porkpress-ssl');
define('PORKPRESS_CERT_NAME', 'porkpress-network');
```

## Multisite lifecycle

PorkPress SSL listens for WordPress multisite events and updates its domain
alias table automatically. When a site is created, deleted, archived or
restored the plugin adjusts aliases and queues certificate issuance so SSL
coverage remains in sync with the network.

When assigning domains to sites via the Domain Aliases screen, the domain picker lists only Porkbun domains that are not already mapped.

## Debian 11/Apache example

On Debian 11 with Apache, WordPress is typically installed under `/var/www/html`
and Let's Encrypt stores certificates in `/etc/letsencrypt`. An existing SAN
certificate may already reside in this directory; PorkPress
SSL can renew and expand it as additional domains are added.
