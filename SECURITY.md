# Security

For a vulnerability that could expose host resources, use this repository's private vulnerability reporting feature. Please avoid publishing an exploit or real credentials in a public issue.

The public API is intentionally anonymous. Its boundary is public HTTP(S) websites on standard ports, with the same origin for sitemap entries and sitemap redirects. DNS answers are validated against private/reserved ranges and the chosen address is pinned with `CURLOPT_RESOLVE`. Proxy use and automatic redirects are disabled. PHP's XML parser rejects DTDs and does not expand external entities.

Jobs and the rate-limit ledger must remain outside the web document root. The deployment archive does not contain configuration, runtime state, SSH keys, or tokens. Do not place this application's root under another publicly served directory. On reverse-proxy installations, configure the web server to restore the actual client IP only from trusted proxies; the application deliberately ignores client-supplied forwarding headers.

Scope limitations: application quotas do not replace provider-level denial-of-service protection. A compromised hosting account or sibling application running as the same Unix user could read private files. Host access logs/backups are outside the application's retention policy. DNS resolution time is subject to the host resolver; cURL's eight-second timeout covers the subsequent connection and transfer.

Before raising the default scan limits, reassess shared-hosting capacity and abuse controls. Set `enabled` to `false` in the private configuration to stop API work while investigating a problem. Keep PHP and the hosting account patched.

## Abuse controls added in 0.2.0

- Signed, expiring ALTCHA proof of work is required for scan creation. It is bound to the normalized sitemap and visitor network and atomically consumed once. This is computational friction, not an assertion that the visitor is human.
- Failed admission attempts have small per-network/global minute budgets; successful jobs also have per-network, per-target, and global calendar quotas. IPv6 /64 aggregation reduces address-rotation bypasses.
- Private job access requires both the token and originating network. Status/preflight paths avoid storage initialization; completed reads avoid mutations. All shared ledger/job locks are nonblocking.
- Actual outbound slots are held by processes during DNS resolution and HTTP transfers, independent of expiring job leases. Destination IP locks reduce concurrent requests through alias hostnames. Response and XML limits still apply.
- Apache/LiteSpeed receives an 8 KiB request-body cap and rejects unsupported API methods before the application. These controls do not replace a front-end request-rate limiter or DDoS protection.

Distributed clients can still solve challenges, exhaust the shared allowance, or send incoming traffic without starting scans. Hostname quotas do not identify registrable domains or establish ownership; aliases can have separate quotas, although the global cap remains shared. The tool makes bounded public GET requests from its hosting IP. Provider-side firewall/rate limits and account isolation are necessary additional layers for higher-risk deployments. No complete protection or independent audit is claimed.

## Dependency provenance

The official ALTCHA verifier is preserved with its MIT license and upstream hashes; the browser package is pinned by the pnpm lockfile. Do not replace the cryptographic verifier with client-only checks or accept unsigned/expired challenges. Changes to challenge parameters must retain strict bounds before library verification.
