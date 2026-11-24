const table = document.querySelector("#jobs-table");
const rows = new Map([...table?.querySelectorAll("[data-job-id]") ?? []].map(row => [Number(row.dataset.jobId), row]));
const workerPanel = document.querySelector("[data-worker-status]");
const refreshButton = workerPanel?.querySelector("[data-worker-refresh]");
const checked = workerPanel?.querySelector("[data-worker-last-checked]");
const recovery = workerPanel?.querySelector("[data-worker-recovery]");
const guidance = workerPanel?.querySelector("[data-worker-guidance]");
const doneStates = new Set(["completed","cancelled","failed"]);

function setText(row, field, value) {
  const element = row.querySelector(`[data-field="${field}"]`);
  if (element) element.textContent = value ?? "—";
}
function formatCount(value) { return Number(value ?? 0).toLocaleString(); }
function formatBytes(value) {
  const b=Math.max(0,Number(value ?? 0));
  const units=["B","KiB","MiB","GiB","TiB"];
  let n=b,u=0;
  while(n>=1024&&u<units.length-1){n/=1024;u++;}
  return new Intl.NumberFormat(undefined,{maximumFractionDigits:u===0?0:1}).format(n)+" "+units[u];
}
function setWorkerField(field, value) {
  const node = workerPanel?.querySelector(`[data-worker-field="${field}"]`);
  if (node) node.textContent = value ?? "—";
}
const workerStates = {
  working:["Worker responding","A recent job heartbeat confirms a worker check-in, but does not prove hashing is advancing. Check byte progress and the latest job event before taking action."],
  ready:["Ready","The worker process is responding and ready for new jobs."],
  stalled:["Job heartbeat overdue","An unfinished job has not reported recently. Check its last progress before recovery; a large file can take time."],
  waiting:["Waiting for worker","Jobs are queued but no worker is currently reporting. Check the DUP service if the queue does not advance."],
  not_reporting:["No recent worker activity","No active job or process heartbeat is currently available. An empty queue can mean the worker is idle or scheduled."]
};
function updateWorker(data) {
  if (!workerPanel || !data || typeof data !== "object") return;
  const [label,description] = workerStates[data.state] ?? workerStates.not_reporting;
  setWorkerField("state",label);
  const badge = workerPanel.querySelector('[data-worker-field="state"]');
  if (badge) badge.dataset.state = data.state ?? "not_reporting";
  for (const key of ["active","jobs_responding","queued_jobs","jobs_running","recovery_candidates"])
    setWorkerField(key,formatCount(data[key]));
  setWorkerField("last_heartbeat",data.last_heartbeat ? `${data.last_heartbeat} UTC` : "None recorded");
  setWorkerField("last_job_heartbeat",data.last_job_heartbeat ? `${data.last_job_heartbeat} UTC` : "None recorded");
  if (guidance) guidance.textContent=description;
  if (recovery) recovery.hidden=!(Number(data.recovery_candidates ?? 0)>0 &&
    Number(data.active ?? 0)===0 && Number(data.jobs_responding ?? 0)===0);
}
let fetching=false;
async function refresh(manual=false) {
  if (fetching || (document.hidden && !manual)) return;
  fetching=true;
  if (refreshButton && manual) { refreshButton.disabled=true;refreshButton.textContent="Checking…"; }
  try {
    const response=await fetch(workerPanel?.dataset.apiUrl ?? "api/jobs",{
      cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload=await response.json();
    if (!Array.isArray(payload.jobs)) throw new Error("Invalid job status response");
    updateWorker(payload.worker);
    for (const job of payload.jobs) {
      const row=rows.get(Number(job.id));
      if (!row) continue;
      // A changed state can reveal different actions (cancel / retry / delete).
      // Refresh the server-rendered controls instead of retaining stale buttons.
      if (row.dataset.renderedState !== job.state &&
          (doneStates.has(job.state) || job.state === "cancelling" ||
           row.dataset.renderedState === "cancelling")) {
        window.location.reload();
        return;
      }
      setText(row,"phase",String(job.phase ?? "").replaceAll("_"," "));
      setText(row,"state",job.state);
      setText(row,"heartbeat",job.heartbeat_at ? `${job.heartbeat_at} UTC` : "—");
      setText(row,"error",job.error_code);
      const current=Number(job.progress_current ?? 0);
      const total=job.progress_total===null ? null : Number(job.progress_total);
      setText(row,"progress-text",job.kind==="rebuild-duplicates" && job.state==="completed" ?
        `${formatCount(current)} duplicate groups` :
        total===null ? formatCount(current) : `${formatCount(current)} / ${formatCount(total)}`);
      const bytesTotal=job.progress_bytes_total===null ? null : Number(job.progress_bytes_total);
      const bytesCurrent=Number(job.progress_bytes_current ?? 0);
      const bytesText=row.querySelector('[data-field="bytes-text"]');
      const detailText=row.querySelector('[data-field="progress-detail"]');
      const byteBar=row.querySelector('[data-field="bytes-bar"]');
      if(bytesText) {
        bytesText.hidden=bytesTotal===null;
        if(bytesTotal!==null)bytesText.textContent=formatBytes(bytesCurrent)+" / "+formatBytes(bytesTotal)+" read";
      }
      if(detailText) {
        detailText.hidden=bytesTotal===null;
        detailText.textContent=job.progress_detail??"";
        detailText.title=job.progress_detail??"";
      }
      if(byteBar) {
        byteBar.hidden=bytesTotal===null;
        if(bytesTotal!==null) {
          byteBar.max=Math.max(1,bytesTotal);
          byteBar.value=Math.min(bytesCurrent,byteBar.max);
        }
      }
      const progress=row.querySelector('[data-field="progress-bar"]');
      if (progress) {
        if (total===null && !doneStates.has(job.state)) progress.removeAttribute("value");
        else {
          progress.max=Math.max(1,total ?? 1);
          progress.value=job.state==="completed" ? progress.max : Math.min(current,progress.max);
        }
      }
      if (doneStates.has(job.state) || job.state==="cancelling") {
        const button=row.querySelector('form[action$="/cancel"] button');
        if (button) button.disabled=true;
      }
    }
    if (checked) checked.textContent=`${new Date().toLocaleTimeString()} (local)`;
  } catch {
    if (checked && manual) checked.textContent="Refresh failed — check connection";
  } finally {
    fetching=false;
    if (refreshButton) {refreshButton.disabled=false;refreshButton.textContent="Refresh status";}
  }
}
refreshButton?.addEventListener("click",()=>{void refresh(true);});
if (workerPanel || rows.size>0) {
  void refresh();
  setInterval(()=>{void refresh();},5000);
}
