import { useEffect, useRef, useState } from "react";
import type { Scan } from "./types";
import { demo } from "./demo";

const pause = (ms: number) =>
  new Promise<void>((resolve) => setTimeout(resolve, ms));
class ApiFailure extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
  }
}
async function request(
  action: string,
  body: object,
  token?: string,
): Promise<{ scan: Scan; token?: string }> {
  const response = await fetch(`/api.php?action=${action}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(token ? { "X-Scan-Token": token } : {}),
    },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(25000),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new ApiFailure(
      result.error || "The server is unavailable. Please try again.",
      response.status,
    );
  return result;
}
export function useScanner() {
  const [scan, setScan] = useState<Scan>(demo);
  const [busy, setBusy] = useState(false);
  const [stopping, setStopping] = useState(false);
  const [error, setError] = useState("");
  const [available, setAvailable] = useState<boolean | null>(null);
  const active = useRef(false);
  const stop = useRef(false);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    const controller = new AbortController();
    fetch("/api.php?action=status", { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => setAvailable(data.ready === true))
      .catch(() => {
        if (!controller.signal.aborted) setAvailable(false);
      });
    return () => {
      mounted.current = false;
      stop.current = true;
      controller.abort();
    };
  }, []);
  async function run(sitemap: string) {
    if (active.current) return;
    active.current = true;
    stop.current = false;
    setBusy(true);
    setStopping(false);
    setError("");
    let current: Scan | undefined;
    try {
      const created = await request("create", { sitemap });
      current = created.scan;
      const token = created.token;
      if (!token) throw new Error("The server did not start a scan.");
      if (mounted.current) setScan(current);
      let conflicts = 0;
      while (current.phase === "discovering" || current.phase === "checking") {
        await pause(550);
        try {
          const next = await request(
            stop.current ? "cancel" : "step",
            { id: current.id },
            token,
          );
          current = next.scan;
          conflicts = 0;
          if (mounted.current) setScan(current);
        } catch (error) {
          if (
            error instanceof ApiFailure &&
            error.status === 409 &&
            conflicts++ < 4
          ) {
            await pause(750);
            continue;
          }
          throw error;
        }
      }
    } catch (error) {
      if (mounted.current) {
        setError(
          error instanceof Error
            ? error.name === "TimeoutError"
              ? "The server took too long to respond. Try a new scan."
              : error.message
            : "The scan could not be completed.",
        );
        if (current)
          setScan({ ...current, complete: false, phase: "cancelled" });
      }
    } finally {
      active.current = false;
      if (mounted.current) {
        setBusy(false);
        setStopping(false);
      }
    }
  }
  function cancel() {
    stop.current = true;
    setStopping(true);
  }
  function loadDemo() {
    if (active.current) return;
    setScan(demo);
    setError("");
  }
  return { scan, busy, stopping, error, available, run, cancel, loadDemo };
}
