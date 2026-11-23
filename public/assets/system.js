const root = document.querySelector('[data-system-endpoint]');
if (root) {
  const endpoint = root.dataset.systemEndpoint;
  const nodes = new Map(
    [...root.querySelectorAll('[data-system-field]')].map(
      element => [element.dataset.systemField, element]
    )
  );
  const statusNode = root.querySelector('[data-system-status]');
  let active = false;
  async function updateStatus() {
    if (active || document.visibilityState === 'hidden') return;
    active = true;
    try {
      const response = await fetch(endpoint, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('Unable to retrieve system status');
      const report = await response.json();
      if (typeof report.status === 'string' && statusNode) {
        statusNode.textContent = report.status;
      }
      if (report.checks && typeof report.checks === 'object') {
        for (const [key, node] of nodes) {
          const value = report.checks[key];
          node.textContent = value === null || value === undefined
            ? key === 'oldest_queued_at' ? 'None' : 'Never'
            : typeof value === 'object' ? 'Unavailable' : String(value);
        }
      }
    } catch {
      // Keep the last valid values; navigating to /system or refreshing the
      // page will show server-side diagnostics if the API is unavailable.
    } finally {
      active = false;
    }
  }
  window.setInterval(updateStatus, 15000);
}
