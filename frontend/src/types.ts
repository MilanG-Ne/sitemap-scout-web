export type Finding = {
  severity: "error" | "warning";
  code: string;
  url: string;
  message: string;
};
export type Page = {
  url: string;
  status: number;
  durationMs: number;
  title: string | null;
  canonical: string | null;
  indexability: "allowed" | "noindex" | "unknown";
  findings: Finding[];
};
export type Scan = {
  id: string;
  sitemap: string;
  phase: "discovering" | "checking" | "complete" | "cancelled";
  complete: boolean;
  discovered: number;
  checked: number;
  sitemapsRead: number;
  limit: number;
  startedAt: string;
  finishedAt: string | null;
  expiresAt: string;
  pages: Page[];
  findings: Finding[];
  demo?: boolean;
  findingsTruncated?: boolean;
};
export type Filter = "all" | "error" | "warning" | "healthy";
export function severity(page: Page): "error" | "warning" | "healthy" {
  if (page.findings.some((f) => f.severity === "error")) return "error";
  if (page.findings.length) return "warning";
  return "healthy";
}
