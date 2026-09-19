import { describe, expect, it } from "vitest";
import { csvCell, toCsv } from "../frontend/src/export";
import { demo } from "../frontend/src/demo";

describe("spreadsheet exports", () => {
  it.each([
    '=HYPERLINK("bad")',
    "+SUM(1)",
    "-2+3",
    "@SUM(1)",
    "  =1",
    "\tformula",
  ])("neutralizes formula cell %s", (text) => {
    expect(csvCell(text).startsWith("\"'")).toBe(true);
  });
  it("escapes quotes and preserves embedded newlines", () => {
    expect(csvCell('A "title"\nline')).toBe('"A ""title""\nline"');
  });
  it("includes sitemap-level findings and incomplete coverage", () => {
    const csv = toCsv({
      ...demo,
      complete: false,
      findings: [
        ...demo.findings,
        {
          severity: "warning",
          code: "url_limit",
          url: demo.sitemap,
          message: "Only the first 25 URLs.",
        },
      ],
    });
    expect(csv).toContain("url_limit: Only the first 25 URLs.");
    expect(csv).toContain("Incomplete");
    expect(csv.charCodeAt(0)).toBe(0xfeff);
  });
});
