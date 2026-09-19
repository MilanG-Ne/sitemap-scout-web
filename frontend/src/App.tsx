import { useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowRight,
  ArrowUpRight,
  Check,
  CircleAlert,
  Clock3,
  Download,
  ExternalLink,
  FileCode2,
  Code2,
  Globe2,
  ListFilter,
  Network,
  Search,
  ShieldCheck,
  X,
} from "lucide-react";
import { useScanner } from "./useScanner";
import { downloadReport } from "./export";
import { severity, type Filter, type Page } from "./types";

export function App() {
  const {
    scan,
    busy,
    stopping,
    verifying,
    error,
    available,
    run,
    cancel,
    loadDemo: resetDemo,
  } = useScanner();
  const dialogRef = useRef<HTMLDialogElement>(null);
  const opener = useRef<HTMLElement | null>(null);
  const [input, setInput] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState<Page | null>(null);
  const [notice, setNotice] = useState("");
  const counts = {
    error: scan.pages.filter((p) => severity(p) === "error").length,
    warning: scan.pages.filter((p) => severity(p) === "warning").length,
    healthy: scan.pages.filter((p) => severity(p) === "healthy").length,
  };
  const visible = useMemo(
    () =>
      scan.pages.filter(
        (p) =>
          (filter === "all" || severity(p) === filter) &&
          (p.url + " " + p.title).toLowerCase().includes(query.toLowerCase()),
      ),
    [scan, filter, query],
  );
  useEffect(() => {
    if (selected) {
      opener.current = document.activeElement as HTMLElement;
      dialogRef.current?.showModal();
    } else if (dialogRef.current?.open) {
      dialogRef.current.close();
      opener.current?.focus();
    }
  }, [selected]);
  const mapFindings = scan.findings.filter(
    (f) =>
      !scan.pages.some((p) =>
        p.findings.some((pf) => pf.code === f.code && pf.url === f.url),
      ),
  );
  const errors = scan.findings.filter((f) => f.severity === "error").length;
  const warnings = scan.findings.filter((f) => f.severity === "warning").length;
  function loadDemo() {
    resetDemo();
    setFilter("all");
    setQuery("");
    setSelected(null);
    setNotice("Example report loaded. No website was contacted.");
  }

  return (
    <>
      <header className="topbar">
        <div className="topbar-inner">
          <a className="brand" href="/" aria-label="Sitemap Scout home">
            <span className="brand-icon">
              <Network size={23} />
            </span>
            <strong>Sitemap Scout</strong>
            <span className="web-tag">WEB</span>
          </a>
          <div className="header-links">
            <a href="https://ivig.dev" target="_blank" rel="noreferrer">
              by <strong>iviG</strong>
              <ArrowUpRight size={14} />
            </a>
            <a
              href="https://github.com/MilanG-Ne/sitemap-scout-web"
              target="_blank"
              rel="noreferrer"
            >
              <Code2 size={18} />
              <span>Source code</span>
            </a>
          </div>
        </div>
      </header>
      <main className="workspace">
        <div className="page-heading">
          <div>
            <p className="eyebrow">TECHNICAL SEO TOOLKIT</p>
            <h1>Give your sitemap a health check.</h1>
            <p>Find the URLs that shouldn’t be in your sitemap—and see why.</p>
          </div>
          <span className="open-label">
            <FileCode2 size={16} /> Open source
          </span>
        </div>
        <section className="scan-panel" aria-labelledby="scan-heading">
          <div className="scan-main">
            <label id="scan-heading" htmlFor="sitemap-url">
              Sitemap URL
            </label>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                setNotice("");
                setFilter("all");
                setQuery("");
                setSelected(null);
                void run(input);
              }}
            >
              <div className="url-field">
                <Globe2 size={20} />
                <input
                  id="sitemap-url"
                  type="url"
                  required
                  disabled={busy}
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  placeholder="https://your-website.com/sitemap.xml"
                  autoComplete="url"
                  spellCheck={false}
                />
              </div>
              <button
                className="primary"
                type="submit"
                disabled={busy || available !== true}
              >
                {busy ? "Checking…" : "Run health check"}{" "}
                <ArrowRight size={18} />
              </button>
            </form>
            <div className="scan-meta">
              <span>
                <ShieldCheck size={14} /> Public websites only · up to 25 URLs
                per scan
              </span>
              <button
                type="button"
                className="text-button"
                disabled={busy}
                onClick={loadDemo}
              >
                Try an example <ArrowUpRight size={14} />
              </button>
            </div>
          </div>
          <div className="scan-aside">
            <span className="aside-label">WHAT WE CHECK</span>
            <div>
              <Check size={15} /> Broken links & redirects
            </div>
            <div>
              <Check size={15} /> Canonicals & noindex
            </div>
            <div>
              <Check size={15} /> Sitemap structure & speed
            </div>
          </div>
        </section>
        {available === false && (
          <div className="notice" role="status">
            Live scans are temporarily unavailable. You can still explore the
            example report.
          </div>
        )}
        {error && (
          <div className="notice error-notice" role="alert">
            {error}
          </div>
        )}
        {busy && (
          <section className="scan-progress" aria-label="Scan progress">
            <div>
              <strong>
                {stopping
                  ? "Stopping after the current request…"
                  : verifying
                    ? "Verifying your browser…"
                    : scan.demo
                      ? "Starting scan…"
                      : scan.phase === "discovering"
                        ? "Reading your sitemaps…"
                        : `Checking URL ${Math.min(scan.checked + 1, Math.min(scan.discovered, scan.limit))} of ${Math.min(scan.discovered, scan.limit)}`}
              </strong>
              <p>
                Keep this tab open. Requests are paced to be considerate to your
                website.
              </p>
            </div>
            <button className="secondary" onClick={cancel} disabled={stopping}>
              Stop scan
            </button>
            <progress
              aria-label="URLs checked"
              value={scan.checked}
              max={Math.max(1, Math.min(scan.discovered, scan.limit))}
            />
          </section>
        )}
        {!busy && !scan.demo && !scan.complete && (
          <div className="notice incomplete" role="status">
            <div>
              <strong>
                {scan.phase === "cancelled" ? "Scan stopped" : "Partial scan"}
              </strong>
              <p>
                {scan.checked} URLs checked. This report does not represent a
                complete sitemap check.
              </p>
            </div>
          </div>
        )}
        {scan.findingsTruncated && (
          <div className="notice" role="status">
            Only the first 100 findings are listed. More issues may be present.
          </div>
        )}
        {notice && (
          <div className="notice" role="status">
            {notice}
            <button aria-label="Dismiss message" onClick={() => setNotice("")}>
              <X size={16} />
            </button>
          </div>
        )}
        <div className="report-heading">
          <div className="report-title">
            <h2>Scan overview</h2>
            <span className="demo-tag">
              {scan.demo ? "EXAMPLE REPORT" : "LIVE SCAN"}
            </span>
          </div>
          <span className="report-domain">
            {new URL(scan.sitemap).hostname}
            <span className="separator">/</span>
            {scan.sitemapsRead} sitemaps
          </span>
        </div>
        <section className="metrics" aria-label="Scan statistics">
          <Metric
            label="URLs checked"
            value={scan.checked}
            icon={<Globe2 size={19} />}
            detail={`of ${scan.discovered} discovered`}
            tone="blue"
          />
          <Metric
            label="Healthy URLs"
            value={counts.healthy}
            icon={<Check size={19} />}
            detail="No issues detected"
            tone="green"
          />
          <Metric
            label="Errors"
            value={errors}
            icon={<CircleAlert size={19} />}
            detail="Worth fixing first"
            tone="red"
          />
          <Metric
            label="Warnings"
            value={warnings}
            icon={<Clock3 size={19} />}
            detail="Take a closer look"
            tone="amber"
          />
        </section>
        {mapFindings.length > 0 && (
          <details className="sitemap-findings" open>
            <summary>
              Sitemap findings <span>{mapFindings.length}</span>
            </summary>
            {mapFindings.map((f, i) => (
              <div key={i} className={f.severity}>
                <CircleAlert size={17} />
                <div>
                  <strong>{issueLabel(f.code)}</strong>
                  <p>{f.message}</p>
                  <small>{f.url}</small>
                </div>
              </div>
            ))}
          </details>
        )}
        <section className="results-panel" aria-labelledby="results-heading">
          <div className="results-top">
            <div>
              <h2 id="results-heading">URL results</h2>
              <p>
                {scan.demo
                  ? "Fictional data, real examples of common sitemap issues."
                  : "Response and indexing signals for each checked URL."}
              </p>
            </div>
            <div className="export-buttons">
              <button
                className="secondary"
                disabled={busy}
                onClick={() => downloadReport(scan, "csv")}
              >
                <Download size={16} /> CSV
              </button>
              <button
                className="secondary"
                disabled={busy}
                onClick={() => downloadReport(scan, "json")}
              >
                JSON
              </button>
            </div>
          </div>
          <div className="toolbar">
            <div className="filters" aria-label="Filter results">
              {(
                [
                  ["all", "All URLs", scan.checked],
                  ["error", "Errors", counts.error],
                  ["warning", "Warnings", counts.warning],
                  ["healthy", "Healthy", counts.healthy],
                ] as const
              ).map(([value, label, count]) => (
                <button
                  key={value}
                  className={filter === value ? "filter active" : "filter"}
                  aria-pressed={filter === value}
                  onClick={() => setFilter(value)}
                >
                  {label}
                  <span>{count}</span>
                </button>
              ))}
            </div>
            <label className="search">
              <Search size={16} />
              <input
                aria-label="Search URLs"
                placeholder="Find a URL…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </label>
          </div>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>URL / PAGE TITLE</th>
                  <th>HTTP STATUS</th>
                  <th>RESPONSE</th>
                  <th>RESULT</th>
                  <th>
                    <span className="sr-only">Details</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {visible.map((page, i) => (
                  <tr key={page.url + i}>
                    <td>
                      <button
                        className="page-link"
                        onClick={() => setSelected(page)}
                      >
                        {new URL(page.url).pathname +
                          (new URL(page.url).search || "")}
                      </button>
                      <span className="page-title">
                        {page.title || "No page title available"}
                      </span>
                    </td>
                    <td>
                      <span
                        className={`http-status http-${Math.floor(page.status / 100)}`}
                      >
                        {page.status || "ERR"}
                      </span>
                    </td>
                    <td>
                      <span
                        className={
                          page.durationMs >= 1000
                            ? "slow-time"
                            : "response-time"
                        }
                      >
                        {page.durationMs.toLocaleString()} <small>ms</small>
                      </span>
                    </td>
                    <td>
                      <span className={`result-badge ${severity(page)}`}>
                        {severity(page) === "healthy" ? (
                          <Check size={13} />
                        ) : (
                          <CircleAlert size={13} />
                        )}{" "}
                        {page.findings[0]
                          ? issueLabel(page.findings[0].code)
                          : "Healthy"}
                        {page.findings.length > 1
                          ? ` +${page.findings.length - 1}`
                          : ""}
                      </span>
                    </td>
                    <td>
                      <button
                        className="detail-button"
                        aria-label={`Details for ${new URL(page.url).pathname}`}
                        onClick={() => setSelected(page)}
                      >
                        <ArrowUpRight size={17} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {visible.length === 0 && (
              <div className="empty">
                <ListFilter size={24} />
                <h3>No matching URLs</h3>
                <p>Try a different filter or search term.</p>
                <button
                  className="text-button"
                  onClick={() => {
                    setFilter("all");
                    setQuery("");
                  }}
                >
                  Clear filters
                </button>
              </div>
            )}
          </div>
          <div className="table-footer">
            <span>
              Showing {visible.length} of {scan.checked} checked URLs
            </span>
            <span>
              Query values are hidden in reports <ShieldCheck size={13} />
            </span>
          </div>
        </section>
        <section className="footnotes">
          <p>
            <strong>A focused check, not a full crawl.</strong> We check URLs
            listed in your sitemap. Results reflect one request—not a guarantee
            of search-engine indexing.
          </p>
          <a
            href="https://ivig.dev/seo-guides/"
            target="_blank"
            rel="noreferrer"
          >
            Explore the SEO guides <ArrowUpRight size={16} />
          </a>
        </section>
        <details className="usage-notes">
          <summary>Scan limits &amp; privacy</summary>
          <p>
            Use public URLs only. Scans check up to 25 URLs across 3 sitemap
            documents. The public tool allows 5 scans per IP per hour, 20 per
            day, and 150 per day overall. Each website is limited to 3 scans per
            hour and 10 per day. Only one scan per visitor and website can run
            at a time. IPv6 limits apply per network.
          </p>
          <p>
            Query values are hidden in reports. Full URLs are temporarily stored
            on our server to perform the scan; URL paths and page titles remain
            visible in results. Reports require a private access token from the
            same visitor network and expire after one hour. Expired files are
            removed on later scan activity. Hosting logs and backups may have
            separate retention.
          </p>
          <p>
            A self-hosted ALTCHA browser check adds friction for automated
            scans. No accounts, analytics, cookies, or paid API integrations.
            Download your report before closing this tab. Please scan websites
            responsibly.
          </p>
        </details>
      </main>
      <footer className="site-footer">
        <span>
          <Network size={15} /> Sitemap Scout by iviG
        </span>
        <span>Up to 5 scans per hour · reports expire after 1 hour</span>
        <a
          href="https://github.com/MilanG-Ne/sitemap-scout-web"
          target="_blank"
          rel="noreferrer"
        >
          MIT licensed <ExternalLink size={12} />
        </a>
      </footer>
      <dialog
        ref={dialogRef}
        className="detail-dialog"
        aria-labelledby="detail-title"
        onCancel={() => setSelected(null)}
        onClick={(e) => {
          if (e.target === e.currentTarget) {
            const box = e.currentTarget.getBoundingClientRect();
            if (
              e.clientX < box.left ||
              e.clientX > box.right ||
              e.clientY < box.top ||
              e.clientY > box.bottom
            )
              setSelected(null);
          }
        }}
      >
        {selected && (
          <>
            <button
              className="close-dialog"
              aria-label="Close details"
              onClick={() => setSelected(null)}
            >
              <X size={20} />
            </button>
            <p className="eyebrow">URL INSPECTION</p>
            <h2 id="detail-title">{selected.title || "Page details"}</h2>
            <p className="detail-url">{selected.url}</p>
            <dl>
              <dt>HTTP status</dt>
              <dd>{selected.status || "No response"}</dd>
              <dt>Response time</dt>
              <dd>{selected.durationMs} ms</dd>
              <dt>Canonical</dt>
              <dd>{selected.canonical || "Not found or not checked"}</dd>
              <dt>Robots signal</dt>
              <dd>
                {selected.indexability === "allowed"
                  ? "No noindex directive found"
                  : selected.indexability === "noindex"
                    ? "Noindex detected"
                    : "Not determined"}
              </dd>
            </dl>
            {selected.findings.length ? (
              selected.findings.map((finding, i) => (
                <div className={`detail-finding ${finding.severity}`} key={i}>
                  <strong>{issueLabel(finding.code)}</strong>
                  <p>{finding.message}</p>
                </div>
              ))
            ) : (
              <div className="detail-finding healthy">
                <strong>No issues detected</strong>
                <p>This URL passed the checks in this scan.</p>
              </div>
            )}
          </>
        )}
      </dialog>
    </>
  );
}
function Metric({
  label,
  value,
  icon,
  detail,
  tone,
}: {
  label: string;
  value: number;
  icon: React.ReactNode;
  detail: string;
  tone: string;
}) {
  return (
    <div className={`metric ${tone}`}>
      <div className="metric-top">
        <span>{label}</span>
        <span className="metric-icon">{icon}</span>
      </div>
      <strong>{value}</strong>
      <p>{detail}</p>
    </div>
  );
}
export function issueLabel(code: string): string {
  return (
    (
      {
        http_error: "Broken link",
        redirect: "Redirect",
        noindex: "Noindex",
        canonical_mismatch: "Canonical mismatch",
        slow: "Slow response",
        title_missing: "Missing title",
        request_failed: "Request failed",
        metadata_incomplete: "Partial HTML",
      } as Record<string, string>
    )[code] ?? code.replaceAll("_", " ")
  );
}
