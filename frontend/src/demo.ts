import type { Scan, Page, Finding } from "./types";
const origin = "https://atlas.example";
function row(
  path: string,
  title: string,
  status: number,
  durationMs: number,
  issue?: [Finding["severity"], string, string],
): Page {
  const url = origin + path;
  return {
    url,
    title,
    status,
    durationMs,
    canonical: status === 200 ? url : null,
    indexability:
      issue?.[1] === "noindex"
        ? "noindex"
        : status === 200
          ? "allowed"
          : "unknown",
    findings: issue
      ? [{ severity: issue[0], code: issue[1], url, message: issue[2] }]
      : [],
  };
}
const pages = [
  row("/", "Atlas — outdoor essentials", 200, 184),
  row("/collections/backpacks", "Backpacks | Atlas", 200, 263),
  row("/products/trail-pack", "Trail Pack 28L | Atlas", 200, 312),
  row(
    "/journal/weekend-hikes",
    "Five trails for the weekend | Atlas",
    200,
    218,
  ),
  row("/products/alpine-shell", "", 404, 146, [
    "error",
    "http_error",
    "Page returned HTTP 404. Remove this URL or restore the page.",
  ]),
  row("/old-collection", "", 301, 97, [
    "warning",
    "redirect",
    "This URL redirects. Put the final destination in your sitemap.",
  ]),
  row("/sale", "Sale | Atlas", 200, 352, [
    "error",
    "noindex",
    "A robots directive asks search engines not to index this page.",
  ]),
  row("/journal/packing-list", "The complete packing list | Atlas", 200, 1642, [
    "warning",
    "slow",
    "The response took 1642 ms, above the 1000 ms threshold.",
  ]),
  row("/collections/new?sort=latest", "New arrivals | Atlas", 200, 241, [
    "warning",
    "canonical_mismatch",
    "The canonical points to a different URL. Consider listing that URL instead.",
  ]),
  row("/about", "Our story | Atlas", 200, 175),
];
pages[8].url = origin + "/collections/new?[redacted]";
pages[8].findings[0].url = pages[8].url;
pages[8].canonical = origin + "/collections/new";
export const demo: Scan = {
  id: "example",
  sitemap: origin + "/sitemap.xml",
  phase: "complete",
  complete: true,
  discovered: 10,
  checked: 10,
  sitemapsRead: 2,
  limit: 25,
  startedAt: "2026-09-19T08:00:00Z",
  finishedAt: "2026-09-19T08:00:05Z",
  expiresAt: "2026-09-19T09:00:00Z",
  pages,
  findings: pages.flatMap((p) => p.findings),
  demo: true,
};
