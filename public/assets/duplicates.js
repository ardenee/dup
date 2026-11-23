// Compact, single-table duplicate browser. Never load all file rows at once.
const browser = document.querySelector('#duplicate-groups');

if (browser) {
  const groups = [...browser.querySelectorAll('[data-dup-set]')];
  const pending = new WeakMap();
  const selected = new Map();
  const message = browser.querySelector('[data-dup-message]');
  const copyButton = browser.querySelector('[data-dup-copy]');
  const selectedCount = browser.querySelector('[data-dup-selected-count]');

  function tell(text) {
    if (message) message.textContent = text;
  }

  function syncSelected() {
    if (selectedCount) selectedCount.textContent = selected.size.toLocaleString();
    if (copyButton) copyButton.disabled = selected.size === 0;
  }

  function restoreSelections(group) {
    group.querySelectorAll('[data-dup-select]').forEach(input => {
      input.checked = selected.has(input.value);
    });
    syncSelected();
  }

  function expanded(group) {
    return group.dataset.expanded === 'true';
  }

  function show(group, open) {
    group.dataset.expanded = open ? 'true' : 'false';
    group.querySelectorAll('[data-dup-toggle]').forEach(button => {
      button.setAttribute('aria-expanded', String(open));
    });
    group.querySelectorAll('[data-dup-child]').forEach(row => {
      row.hidden = !open;
    });
    if (!open) {
      const loading = group.querySelector('[data-dup-loading]');
      if (loading) loading.hidden = true;
    }
  }

  function parseMemberRows(html) {
    // Parsing inside a table keeps real <tr> elements in the parent table,
    // not a second mismatched table nested inside a cell.
    const doc = new DOMParser().parseFromString(
      '<table><tbody>' + html + '</tbody></table>', 'text/html');
    const body = doc.querySelector('table > tbody');
    if (!body) throw new Error('The server returned an invalid file list.');
    return [...body.querySelectorAll(':scope > tr[data-dup-child]')];
  }

  async function load(group, url) {
    if (pending.has(group)) return pending.get(group);
    const loading = group.querySelector('[data-dup-loading]');
    if (loading) {
      loading.hidden = false;
      const cell = loading.querySelector('td');
      if (cell) cell.textContent = 'Loading matching files…';
    }
    const request = (async () => {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok || response.redirected ||
          !response.headers.get('Content-Type')?.includes('text/html')) {
        throw new Error('Unable to load these files. Try again or refresh the page.');
      }
      const rows = parseMemberRows(await response.text());
      // Keep selected paths even if their current member page is replaced.
      group.querySelectorAll('[data-dup-child]').forEach(row => row.remove());
      group.append(...rows);
      group.dataset.loaded = 'true';
      rows.forEach(row => { row.hidden = !expanded(group); });
      restoreSelections(group);
      return rows.length;
    })().catch(error => {
      tell(error.message || 'Unable to load matching files.');
      throw error;
    }).finally(() => {
      if (loading) loading.hidden = true;
      pending.delete(group);
    });
    pending.set(group, request);
    return request;
  }

  async function expand(group) {
    if (!group) return;
    show(group, true);
    if (group.dataset.loaded === 'true') return;
    const button = group.querySelector('[data-dup-toggle][data-members-url]');
    try {
      await load(group, button.dataset.membersUrl);
    } catch {
      show(group, false);
    }
  }

  function collapse(group) {
    if (group) show(group, false);
  }

  browser.addEventListener('click', async event => {
    const link = event.target.closest('[data-dup-page]');
    if (link) {
      event.preventDefault();
      const group = link.closest('[data-dup-set]');
      if (group && !pending.has(group)) {
        try {
          await load(group, link.dataset.ajaxUrl);
          tell('Loaded the next file page in this group.');
        } catch {
          // The previous page remains if this request fails.
        }
      }
      return;
    }
    const trigger = event.target.closest('[data-dup-toggle]');
    if (trigger) {
      event.preventDefault();
      const group = trigger.closest('[data-dup-set]');
      if (expanded(group)) collapse(group);
      else await expand(group);
      return;
    }
    const row = event.target.closest('[data-dup-primary]');
    if (row && !event.target.closest('button,input,a,form,label,select')) {
      const group = row.closest('[data-dup-set]');
      if (expanded(group)) collapse(group);
      else await expand(group);
    }
  });

  browser.addEventListener('change', event => {
    const check = event.target.closest('[data-dup-select]');
    if (!check) return;
    if (check.checked) selected.set(check.value, check.dataset.filePath);
    else selected.delete(check.value);
    syncSelected();
  });

  browser.querySelector('[data-dup-collapse-all]')?.addEventListener('click', () => {
    groups.forEach(collapse);
    tell('All matching file rows are collapsed.');
  });

  browser.querySelector('[data-dup-expand-all]')?.addEventListener('click', async () => {
    const remaining = groups.filter(group => !expanded(group));
    let index = 0;
    // Bounded concurrency limits PHP/DB activity on larger scan pages.
    await Promise.all(Array.from({ length: Math.min(4, remaining.length) }, async () => {
      while (index < remaining.length) await expand(remaining[index++]);
    }));
    tell('Visible groups expanded. Files within large groups remain paginated.');
  });

  browser.querySelector('[data-dup-invert]')?.addEventListener('click', () => {
    const checkboxes = [...browser.querySelectorAll('[data-dup-select]')]
      .filter(box => !box.closest('tr').hidden);
    checkboxes.forEach(check => {
      check.checked = !check.checked;
      if (check.checked) selected.set(check.value, check.dataset.filePath);
      else selected.delete(check.value);
    });
    syncSelected();
    tell('Selection inverted for the files currently visible.');
  });

  copyButton?.addEventListener('click', async () => {
    if (!selected.size) return;
    const paths = [...selected.values()].join('\n');
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(paths);
      } else {
        const field = document.createElement('textarea');
        field.value = paths;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.left = '-9999px';
        document.body.appendChild(field);
        field.select();
        const copied = document.execCommand('copy');
        field.remove();
        if (!copied) throw new Error('Clipboard access is unavailable.');
      }
      tell('Copied ' + selected.size + ' full file paths.');
    } catch {
      tell('Clipboard access was blocked. Check the browser permissions and try again.');
    }
  });

  syncSelected();
}
