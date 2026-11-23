const table = document.querySelector("#jobs-table");
const rows = new Map(
  [...(table?.querySelectorAll("[data-job-id]") ?? [])]
    .map((row) => [Number(row.dataset.jobId), row])
);
const doneStates = new Set(["completed", "cancelled", "failed"]);

function setText(row, field, value) {
  const element = row.querySelector(`[data-field="${field}"]`);
  if (element) element.textContent = value ?? "—";
}

function formatCount(value) {
  return Number(value ?? 0).toLocaleString();
}

async function refresh() {
  if (document.hidden || rows.size === 0) return;
  try {
    const response = await fetch("api/jobs", {
      cache: "no-store",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    if (!response.ok) return;
    const payload = await response.json();
    if (!Array.isArray(payload.jobs)) return;
    for (const job of payload.jobs) {
      const row = rows.get(Number(job.id));
      if (!row) continue;
      setText(row, "phase", String(job.phase ?? "").replaceAll("_", " "));
      setText(row, "state", job.state);
      setText(row, "heartbeat", job.heartbeat_at ? `${job.heartbeat_at} UTC` : "—");
      setText(row, "error", job.error_code);
      const current = Number(job.progress_current ?? 0);
      const total = job.progress_total === null ? null : Number(job.progress_total);
      setText(row, "progress-text", total === null
        ? formatCount(current)
        : `${formatCount(current)} / ${formatCount(total)}`);
      const progress = row.querySelector('[data-field="progress-bar"]');
      if (progress) {
        if (total === null && !doneStates.has(job.state)) {
          progress.removeAttribute("value");
        } else {
          progress.max = Math.max(1, total ?? 1);
          progress.value = doneStates.has(job.state) ? progress.max : Math.min(current, progress.max);
        }
      }
      if (doneStates.has(job.state) || job.state === "cancelling") {
        const cancel = row.querySelector('form[action$="/cancel"] button');
        if (cancel) cancel.disabled = true;
      }
    }
  } catch {
    // A temporary loss of connection should not disturb the job page.
  }
}
if (rows.size > 0) {
  void refresh();
  setInterval(refresh, 5000);
}
