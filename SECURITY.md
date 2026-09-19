# Security

For a vulnerability that could expose host resources, use this repository's private vulnerability reporting feature. Please avoid publishing an exploit or real credentials in a public issue.

The public API is intentionally anonymous. Its boundary is public HTTP(S) websites on standard ports, with the same origin for sitemap entries and sitemap redirects. DNS answers are validated against private/reserved ranges and the chosen address is pinned with `CURLOPT_RESOLVE`. Proxy use and automatic redirects are disabled. PHP's XML parser rejects DTDs and does not expand external entities.

Jobs and the rate-limit ledger must remain outside the web document root. The deployment archive does not contain configuration, runtime state, SSH keys, or tokens. Do not place this application's root under another publicly served directory. On reverse-proxy installations, configure the web server to restore the actual client IP only from trusted proxies; the application deliberately ignores client-supplied forwarding headers.

Scope limitations: application quotas do not replace provider-level denial-of-service protection. A compromised hosting account or sibling application running as the same Unix user could read private files. Host access logs/backups are outside the application's retention policy. DNS resolution time is subject to the host resolver; cURL's eight-second timeout covers the subsequent connection and transfer.

Before raising the default scan limits, reassess shared-hosting capacity and abuse controls. Set `enabled` to `false` in the private configuration to stop API work while investigating a problem. Keep PHP and the hosting account patched.
