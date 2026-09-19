# Changelog

## 0.2.0 — 2026-09-19

- Require self-hosted ALTCHA browser verification before starting a scan. Challenges expire after two minutes, are tied to the visitor and sitemap, and can only be used once.
- Limit challenge/create attempts and target-host scans; allow only one active scan per visitor network and target. Group IPv6 privacy addresses by /64.
- Enforce outbound process and destination locks independently of scan leases. Pace scan steps on the server.
- Make status/preflight checks and completed report reads free of storage writes. Reject invalid tokens before acquiring locks and fail quickly on contention.
- Bind scan tokens to the visitor network and remove pending raw URLs when a job finishes or is cancelled.
- Cap HTTP request bodies at 8 KiB, restrict API methods, and add HSTS and same-origin resource headers.
- Add challenge, replay, contention, network-binding, admission, and browser cancellation regressions. Preserve the verifier's upstream license and source hashes.

Upgrades require backend and frontend deployment together. Existing 0.1.x scans expire for access; refresh the page and start a new scan. No new account, paid service, or external CAPTCHA request is required. Provider-level request throttling and DDoS controls are still separate requirements.

## 0.1.0 — 2026-09-19

Initial public React/PHP sitemap checker, with live progress, metadata checks, private reports, and CSV/JSON exports.
