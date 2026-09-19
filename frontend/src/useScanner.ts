import { useEffect, useRef, useState } from "react";
import type { Scan } from "./types";
import { demo } from "./demo";
import { solveChallenge, type Challenge } from "altcha-lib";
import { deriveKey } from "altcha-lib/algorithms/web/pbkdf2";

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
async function request<T = { scan: Scan; token?: string }>(
  action: string,
  body: object,
  token?: string,
): Promise<T> {
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
  const [verifying, setVerifying] = useState(false);
  const verification = useRef<AbortController | null>(null);
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
      verification.current?.abort();
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
      setVerifying(true);
      const controller = new AbortController();
      verification.current = controller;
      const { challenge } = await request<{ challenge: Challenge }>(
        "challenge",
        { sitemap },
      );
      if (stop.current) return;
      const solution = await solveChallenge({
        challenge,
        deriveKey,
        controller,
        timeout: 30000,
      });
      if (stop.current) return;
      if (!solution)
        throw new Error("Browser verification timed out. Please try again.");
      const proof = btoa(JSON.stringify({ challenge, solution }));
      setVerifying(false);
      const created = await request("create", { sitemap, proof });
      current = created.scan;
      const token = created.token;
      if (!token) throw new Error("The server did not start a scan.");
      if (mounted.current) setScan(current);
      let conflicts = 0;
      while (current.phase === "discovering" || current.phase === "checking") {
        await pause(1100);
        try {
          const next: { scan: Scan; token?: string } = await request(
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
        setVerifying(false);
        setStopping(false);
      }
    }
  }
  function cancel() {
    stop.current = true;
    verification.current?.abort();
    setStopping(true);
  }
  function loadDemo() {
    if (active.current) return;
    setScan(demo);
    setError("");
  }
  return {
    scan,
    busy,
    stopping,
    verifying,
    error,
    available,
    run,
    cancel,
    loadDemo,
  };
}
