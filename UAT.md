# User Acceptance Testing Plan

## 1. Setup
1. Ensure a WordPress multisite installation with access to network admin.
2. Install the PorkPress SSL plugin zip in the network `plugins` directory.
3. Activate the plugin network-wide.
4. Configure Porkbun API credentials under **Settings → PorkPress SSL**.

## 2. Requesting a Certificate
1. Navigate to **Tools → PorkPress SSL** in the network admin.
2. Enter a test domain owned at Porkbun and click **Request Certificate**.
3. Confirm that the DNS TXT record is created automatically.
4. Wait for issuance and verify that the certificate and key files exist in `/etc/letsencrypt/`.
5. Visit the test domain in a browser to confirm HTTPS loads without warnings.

## 3. Renewal
1. Change the system date to within 30 days of certificate expiration.
2. Run the WP‑CLI command `wp porkpress ssl renew`.
3. Ensure the certificate is renewed and log entries note the renewal.

## 4. Revocation
1. From the plugin page, choose **Revoke Certificate** for the test domain.
2. Confirm the certificate files are removed and HTTPS no longer works for that domain.

## 5. Notifications
1. Trigger a renewal failure by providing invalid Porkbun credentials.
2. Check that an admin notice and log entry are created describing the failure.

## 6. Cleanup
1. Remove test domains and deactivate the plugin.
2. Delete any residual certificate or state files from the server.
