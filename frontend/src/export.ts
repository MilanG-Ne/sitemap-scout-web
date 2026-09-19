import type { Scan } from "./types";

export function csvCell(value: unknown): string {
  let text = String(value ?? "");
  if (/^[\s]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = "'" + text;
  return '"' + text.replaceAll('"', '""') + '"';
}
export function toCsv(scan: Scan): string {
  const rows: unknown[][] = [
    [
      "URL",
      "HTTP status",
      "Response ms",
      "Title",
      "Canonical",
      "Robots signal",
      "Issues",
      "Coverage",
    ],
  ];
  for (const p of scan.pages)
    rows.push([
      p.url,
      p.status,
      p.durationMs,
      p.title,
      p.canonical,
      p.indexability,
      p.findings.map((f) => `${f.code}: ${f.message}`).join(" | "),
      scan.complete ? "Complete" : "Incomplete",
    ]);
  for (const f of scan.findings.filter(
    (f) =>
      !scan.pages.some((p) =>
        p.findings.some((pf) => pf.code === f.code && pf.url === f.url),
      ),
  ))
    rows.push([
      f.url,
      "",
      "",
      "",
      "",
      "",
      `${f.code}: ${f.message}`,
      scan.complete ? "Complete" : "Incomplete",
    ]);
  return "\uFEFF" + rows.map((row) => row.map(csvCell).join(",")).join("\r\n");
}
export function downloadReport(scan: Scan, format: "csv" | "json") {
  const payload =
    format === "csv" ? toCsv(scan) : JSON.stringify(scan, null, 2);
  const blob = new Blob([payload], {
    type: format === "csv" ? "text/csv;charset=utf-8" : "application/json",
  });
  const href = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = href;
  a.download = `sitemap-scout-${scan.demo ? "example" : new Date().toISOString().slice(0, 10)}.${format}`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}
