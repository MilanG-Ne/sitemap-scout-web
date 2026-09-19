# Deploy on PHP shared hosting

1. Build with `pnpm install --frozen-lockfile && pnpm build && pnpm package`.
2. Create a dedicated subdomain and an application directory **outside every other site's document root**. Point the subdomain's document root to the application's `public/` child directory. For example, application `/home/your-user/scout-app`, document root `/home/your-user/scout-app/public`.
3. Select PHP 8.2 or newer for this subdomain. Enable curl, dom, filter, json, and zlib. Install a valid HTTPS certificate using the provider's free certificate facility.
4. Upload the release archive to a private staging directory, verify its SHA256 checksum, and extract it into the new application directory. Preserve provider-created folders such as `cgi-bin` and PHP handler directives.
5. Copy `config.example.php` to `config.php`, set the exact HTTPS origin without a trailing slash, and leave storage outside `public/`. The PHP process must be able to create/write `var/`. Give configuration mode `0600`, runtime storage `0700`, and ordinary public files `0644` where the host supports these permissions.
6. If deploying under another domain, update the canonical URL in `frontend/index.html`, `public/robots.txt`, `public/sitemap.xml`, and the user-agent information in `CurlTransport.php`, then rebuild. This application expects a subdomain root, not a URL subdirectory.
7. Load `/api.php?action=status` over HTTPS. It should return `ready: true`. Run a scan of a small sitemap you own, check a download, and verify `/config.php`, `/backend/src/JobStore.php`, and `/var/.secret` are inaccessible.
8. Enable the hosting panel's force-HTTPS setting after the certificate works. Keep the provided CSP and other headers. Apache/LiteSpeed reads `public/.htaccess`; another server needs equivalent header and directory-listing rules.

Node, pnpm, Python, tests, and development dependencies are not needed on the web host. No database or scheduled background job is required. Existing hosting resource allowances apply; this project does not create a paid service or API subscription.

## Updates and rollback

Set `enabled` to `false`, wait for any current request to finish, and make a private backup of the existing application code and built assets. Upload the next archive without overwriting `config.php`, `var/`, provider folders, or hosting-generated PHP directives in `.htaccess`. Check PHP syntax and the build before re-enabling. Remove obsolete hashed assets only after the new index is deployed.

To roll back, restore that previous code/assets backup and keep the private configuration. Release archives are attached to GitHub releases. A version that changes the private job format should expire old jobs explicitly rather than attempt to reuse incompatible state.

## Operational checks

- Keep TLS certificates renewing and PHP maintained.
- `enabled: false` (PHP boolean syntax: `'enabled' => false`) disables API processing; the static example remains available.
- Jobs expire for API access after one hour; cleanup happens on subsequent scan/step activity. For strict disk deletion on idle hosts, add your own retention job outside the public directory after reviewing provider backup policy.
- Calendar quotas and active scan leases are durable files protected by locks. Don't delete the ledger or rotate its secret casually, as this resets anonymous quotas.
- If the API returns 503, check PHP extensions, origin configuration, filesystem permissions, and available disk space. Production responses intentionally omit filesystem paths and raw exception messages.

## Security upgrade from 0.1.x

Deploy backend, vendored verifier, API entry point, and built frontend together during a short API maintenance window. Existing 0.1.x jobs intentionally become inaccessible because they lack the new network binding; ask users to start a new scan. Preserve the existing `.secret` and quota ledger so an update does not reset the daily allowance. No new configuration key, service account, CAPTCHA secret, or paid integration is required.

Do not omit `backend/vendor/altcha/` from the release. The archive includes it and its license. Copy the new `.htaccess` body while retaining provider-generated handlers. Check `LimitRequestBody 8192`, the explicit Content-Length rewrite guard, and API method restrictions on the actual hosting server; they are not enforced by PHP's development server. The CSP remains restricted to this origin; the browser solver needs no external script, iframe, inline code, or WASM permission.

During smoke testing, confirm that `status` does not change the quota ledger and `create` without proof returns 403. A legitimate scan must solve a challenge and finish successfully. Replaying its proof must not create another job. Avoid load tests on shared hosting.

### Provider protection to request

If the hosting account does not expose a per-domain firewall or request-throttling control, ask the provider to apply one specifically to this subdomain. Suggested starting policy: rate-limit `POST /api.php?action=challenge` and `create` before PHP, allow paced `step`/`cancel` calls (roughly one per second for one scan), and limit excessive concurrent connections. Confirm the policy does not change the main site's rules. Review shared-account CPU, entry-process, bandwidth, and billing limits with the provider. The application cannot configure root-level network filtering from a shared-hosting account.

Some shared hosts pass JSON bodies through despite `LimitRequestBody`. The explicit rewrite guard can reject oversized declared Content-Length before PHP when the host exposes that header to rewrite rules; the API independently rejects oversized bodies and limits reads to 8,193 bytes. Streaming/chunked request buffering and connection/traffic limits must still be bounded by the provider. A PHP JSON error proves the application rejected a request, not that the web server blocked it first.

On the current Hostilica deployment, unsupported methods are rejected by the web server, while the tested oversized JSON request reaches the PHP preflight check and receives 413 there. The account does not expose ModSecurity controls. Provider-side body buffering, connection limits, and request throttling still require hosting support; do not treat the application limits as perimeter protection.
