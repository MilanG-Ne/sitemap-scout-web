import { expect, test } from "@playwright/test";
import { demo } from "../../frontend/src/demo";

test.beforeEach(async ({ page }) => {
  await page.route("**/api.php?action=status", (route) =>
    route.fulfill({ json: { ready: true } }),
  );
});
test("example, filters, search, keyboard dialog and download", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto("/");
  await expect(page.getByText("EXAMPLE REPORT", { exact: true })).toBeVisible();
  await expect(page.locator("tbody tr")).toHaveCount(10);
  await page.getByRole("button", { name: /^Errors/ }).click();
  await expect(page.locator("tbody tr")).toHaveCount(2);
  await page.getByRole("button", { name: /^All URLs/ }).click();
  await page.getByRole("textbox", { name: "Search URLs" }).fill("no-such-page");
  await expect(page.getByText("No matching URLs")).toBeVisible();
  await page.getByRole("button", { name: "Clear filters" }).click();
  const opener = page.getByRole("button", {
    name: "Details for /",
    exact: true,
  });
  await opener.click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).not.toBeVisible();
  await expect(opener).toBeFocused();
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "CSV", exact: true }).click();
  expect((await download).suggestedFilename()).toBe(
    "sitemap-scout-example.csv",
  );
  expect(errors).toEqual([]);
});
test("mobile layout stays within the viewport", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await expect(
    page.getByRole("textbox", { name: "Sitemap URL", exact: true }),
  ).toBeVisible();
});
test("live scan progresses and marks limited coverage", async ({ page }) => {
  const starting = {
    ...demo,
    id: "abc",
    demo: false,
    phase: "discovering",
    checked: 0,
    pages: [],
    findings: [],
    complete: true,
  };
  await page.route("**/api.php?action=create", (route) =>
    route.fulfill({
      status: 201,
      json: { scan: starting, token: "private-test-token" },
    }),
  );
  await page.route("**/api.php?action=step", async (route) => {
    expect(route.request().headers()["x-scan-token"]).toBe(
      "private-test-token",
    );
    await route.fulfill({
      json: { scan: { ...demo, demo: false, complete: false } },
    });
  });
  await page.goto("/");
  await page
    .getByRole("textbox", { name: "Sitemap URL", exact: true })
    .fill("https://example.com/sitemap.xml");
  await page.getByRole("button", { name: "Run health check" }).click();
  await expect(page.getByText("LIVE SCAN", { exact: true })).toBeVisible();
  await expect(page.getByText("Partial scan", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Run health check" }),
  ).toBeEnabled();
});
test("stop ends the scan and does not issue another step", async ({ page }) => {
  const scan = {
    ...demo,
    id: "abc",
    demo: false,
    phase: "discovering",
    checked: 0,
    pages: [],
    findings: [],
  };
  let steps = 0;
  await page.route("**/api.php?action=create", (route) =>
    route.fulfill({ status: 201, json: { scan, token: "private-test-token" } }),
  );
  await page.route("**/api.php?action=step", async (route) => {
    steps++;
    await new Promise((resolve) => setTimeout(resolve, 350));
    await route.fulfill({ json: { scan } });
  });
  await page.route("**/api.php?action=cancel", (route) =>
    route.fulfill({
      json: { scan: { ...scan, phase: "cancelled", complete: false } },
    }),
  );
  await page.goto("/");
  await page
    .getByRole("textbox", { name: "Sitemap URL", exact: true })
    .fill("https://example.com/sitemap.xml");
  await page.getByRole("button", { name: "Run health check" }).click();
  await page.getByRole("button", { name: "Stop scan" }).click();
  await expect(page.getByText("Scan stopped", { exact: true })).toBeVisible();
  expect(steps).toBeLessThanOrEqual(1);
});
test("server error preserves input and explains failure", async ({ page }) => {
  await page.route("**/api.php?action=create", (route) =>
    route.fulfill({
      status: 429,
      json: { error: "Your scan allowance has been reached." },
    }),
  );
  await page.goto("/");
  await page
    .getByRole("textbox", { name: "Sitemap URL", exact: true })
    .fill("https://example.com/sitemap.xml");
  await page.getByRole("button", { name: "Run health check" }).click();
  await expect(page.getByRole("alert")).toContainText("allowance");
  await expect(
    page.getByRole("textbox", { name: "Sitemap URL", exact: true }),
  ).toHaveValue("https://example.com/sitemap.xml");
});
