Test Procedures for PorkPress‑SSL
1. Activation, Deactivation, and Initialization

Activation hook porkpress_ssl_activate
Install plugin and activate network‑wide.
Confirm log and alias tables are created and reconciliation/renewal cron jobs scheduled.
Ensure certbot/dig executables and writable directories are verified; missing items trigger Notifier.
Verify capability request_domain is granted to administrators on all sites

Deactivation hook porkpress_ssl_deactivate
Deactivate plugin.
Confirm capability removal and unscheduling of cron events

Initialization porkpress_ssl_init
After activation, load plugin.
Verify tables exist, admin pages registered, notifier hook registered, and renewal schedule updated

Meta‑cap mapping porkpress_ssl_map_meta_cap
Verify that manage_network_domains maps to manage_network and other caps pass through

Multisite event handlers
Create, delete, archive, and unarchive sites and verify alias table updates and status changes via porkpress_ssl_handle_* callbacks

2. Admin Interface (includes/class-admin.php)

Initialization and Notices
Call Admin::init and confirm hooks for menus, AJAX handlers, notices, and nav link filter
Confirm sunrise_notice displays only when SUNRISE constant missing

Menus and Pages
Validate registration of network and site menus via register_network_menu and register_site_menu
On network page render_network_page, verify tab navigation and tab-specific rendering for Dashboard, Sites, Domains, Requests, Settings, Logs

Dashboard Tab
Ensure domain statistics, drift detection, and warnings render correctly

Sites Tab
render_sites_tab lists sites, primary domains, allows site creation, and respects domain availability list
Column management via add_primary_domain_column and manage_primary_domain_column shows primary domain links

Domains Tab
render_domains_tab supports export, search, refresh, simulation, and listings of aliases with bulk actions and DNS health notices
Private helpers render_domain_details and get_domain_info display domain status and DNS records

DNS AJAX Handlers
handle_dns_retrieve, handle_dns_add, handle_dns_edit, handle_dns_delete perform CRUD on DNS records, returning updated lists

Bulk Actions
handle_bulk_action handles attach/detach/create operations; verify validation, log entries, and success/failure responses

Requests Tab
render_requests_tab shows pending requests and processes approve/deny actions updating alias table

Settings Tab
render_settings_tab saves credentials, certbot options, renew window, TXT wait settings, IP overrides, etc., respecting constants that lock options

Logs Tab
render_logs_tab filters and displays log entries with severity controls (lines ≈1370 onward)

Site Alias Management
register_site_alias_page adds submenu; add_site_nav_link links from network site editor; render_site_alias_page lists aliases and handles add/remove/primary actions

Site Request Page
render_site_page lets site admins request domains and sends notifications via Notifier

3. Domain Service (includes/class-domain-service.php)

Construction and Status
Verify __construct selects dry‑run client, records credentials and dry‑run state; is_dry_run, get_plan, has_credentials return expected values

DNS Health and Lookup
Test check_dns_health with matching/mismatched records, ensuring A/AAAA/CNAME logic, propagation recording, and WP_Error outputs
has_dns_get_record, fetch_dns_records, and dig_dns_records provide fallbacks for DNS resolution
Propagation tracking via record_dns_propagation_issue and clear_dns_propagation_issue updates site option

Cache and Issuance Queue
clear_domain_cache clears option; queue_wildcard_aware_issuance queues certificate issuance while accounting for wildcard network setting

Domain and Site Operations
is_internal_subdomain checks if domain is internal to network domain base
list_domains, refresh_domains, get_last_refresh maintain cached Porkbun domain inventory and expiry data
check_domain uses API to validate availability
attach_to_site, create_site, detach_from_site manage alias linkage and site creation/removal
split_domain separates zone/subdomain for record building

DNS Record Management
create_a_record, ensure_dns_record, ensure_www_cname, delete_a_record manage A/AAAA/CNAME records with TTL and optional www aliasing
get_dns_records, add_dns_record, update_dns_record, delete_dns_record expose CRUD operations for arbitrary DNS records
touch_aliases_for_domain refreshes alias status after DNS changes; get_network_ip and get_network_ipv6 discover network addresses; site_has_content checks for existing posts before mapping

Validation and Alias Table
validate_fqdn validates domain syntax before insertion
add_alias, get_aliases, update_alias, delete_alias, set_primary_alias, is_domain_active manage alias records and verify Porkbun domain status

4. SSL Service (includes/class-ssl-service.php)
Queue Management: queue_issuance, get_queue, clear_queue store site IDs for certificate issuance and schedule WP‑Cron events when available
Issuance Execution: run_queue builds domain list, shards, runs certbot, writes manifest, deploys to Apache, logs successes/failures, and notifies admins
Sharding: shard_domains deterministically groups domains into ≤90‑name shards

5. Renewal Service (includes/class-renewal-service.php)
Scheduling: maybe_schedule sets cron event based on manifest expiry and sends expiry warnings
Execution: run retries certbot renewals with backoff, writes manifest, deploys to Apache, and notifies on success/failure
Helpers: get_apache_reload_cmd, deploy_to_apache, write_manifest, build_certbot_command, get_manifest, calculate_backoff each handle detection, file writing, command construction, manifest parsing, and exponential backoff

6. Certbot Helper (includes/class-certbot-helper.php)
Verify build_command constructs manual certbot command with hooks, directories, staging/renewal flags, and sanitized domains
list_certificates invokes certbot and parses output; parse_certificates_output splits lineages/domains into arrays

7. Runner (includes/class-runner.php)
Confirm method detection between proc_open and WP‑CLI wrapper via method and raw_run
run executes commands with optional sudo; command_exists checks PATH; describe summarizes execution mode and sudo usage

8. Command‑Line Interface (includes/class-cli.php)
Issue & Renew Commands: Test CLI::issue and CLI::renew_all for domain parsing, cert name resolution, staging flags, manifest writing, and Apache deployment
Health Check: CLI::health validates certbot availability, directory permissions, existing certificates, Apache reload command, DNS tools, and PHP extensions
Exporting Data: export_logs and export_mapping output logs and alias mapping in CSV/JSON formats

9. Logging and Notifications
Logger: verify table creation, logging with context redaction via log, info, warn, error, get_logs, and sanitize_context removing secrets/emails
Notifier: notify stores admin notices and emails admins; register/display render and clear notices in admin area

10. TXT Propagation Waiter (includes/class-txt-propagation-waiter.php)
wait repeatedly queries resolvers for _acme-challenge TXT records, respecting timeout and interval; query_txt executes dig via Runner and parses results

11. Reconciler (includes/class-reconciler.php)
reconcile_site archives site and deletes aliases when primary domain inactive; reconcile_all compares Porkbun domain list with local aliases and sites, logging/remediating drift

12. Porkbun API Clients
Porkbun_Client: confirm domain listing, record operations (create/edit/delete), URL forwarding, DNSSEC, glue records, and backoff logic with structured error handling
Porkbun_Client_DryRun: ensure requests are recorded in plan without external calls

13. Sunrise Loader
Install sunrise.php drop‑in and verify pre_get_site_by_path maps request domains to site IDs or returns 410 for archived sites

14. Unit Tests Overview
Existing PHPUnit tests cover logger redaction, DNS propagation waiting, certbot helper parsing, reconciling logic, CLI exports, runner backoff, renewal scheduling, and admin rendering. Replicate these tests to validate behavior under diverse scenarios.