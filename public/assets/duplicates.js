// Compact, single-table duplicate browser. Never load all file rows at once.
// A user can switch to any retained profile scan without visiting Reports.
// Changing profile clears a stale scan ID before submitting the selector.
const scanPicker = document.querySelector('[data-scan-picker-form]');
if (scanPicker) {
  const profile = scanPicker.querySelector('[data-scan-profile]');
  const run = scanPicker.querySelector('[data-scan-run]');
  profile?.addEventListener('change', () => {
    if (run) run.value = '';
    // A paging cursor belongs to the previous profile's scan history.
    scanPicker.querySelector('input[name="scan_before"]')?.remove();
    scanPicker.requestSubmit();
  });
  run?.addEventListener('change', () => {
    // The "latest" choice must always return to current results, even after
    // navigating into older scan-choice pages.
    if (!run.value) scanPicker.querySelector('input[name="scan_before"]')?.remove();
    scanPicker.requestSubmit();
  });
}

const browser = document.querySelector('#duplicate-groups');

if (browser) {
  const groups = [...browser.querySelectorAll('[data-dup-set]')];
  const pending = new WeakMap();
  const selected = new Map();
  const message = browser.querySelector('[data-dup-message]');
  const copyButton = browser.querySelector('[data-dup-copy]');
  const renameButton = browser.querySelector('[data-dup-rename]');
  const moveButton = browser.querySelector('[data-dup-move]');
  const deleteButton = browser.querySelector('[data-dup-delete]');
  const deleteForm = browser.querySelector('[data-dup-delete-form]');
  const actionIds = [...browser.querySelectorAll('[data-dup-action-ids]')];
  const linkButton = browser.querySelector('[data-dup-link]');
  const clearButton = browser.querySelector('[data-dup-clear-selection]');
  const selectedCount = browser.querySelector('[data-dup-selected-count]');

  function tell(text) {
    if (message) message.textContent = text;
  }

  function syncSelected() {
    if (selectedCount) selectedCount.textContent = selected.size.toLocaleString();
    if (copyButton) copyButton.disabled = selected.size === 0;
    if (renameButton) renameButton.disabled = selected.size === 0;
    if (moveButton) moveButton.disabled = selected.size === 0;
    if (deleteButton) deleteButton.disabled = selected.size === 0;
    // Single compact field per form: prevents max_input_vars from silently
    // truncating large batches, and uses native browser form submission.
    const csv = [...selected.keys()].join(',');
    actionIds.forEach(field => { field.value = csv; });
    if (linkButton) linkButton.disabled = selected.size === 0;
    if (clearButton) clearButton.disabled = selected.size === 0;
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
    const groupSelection = event.target.closest('[data-dup-select-group]');
    if (groupSelection) {
      event.preventDefault();
      const group = groupSelection.closest('[data-dup-set]');
      if (!group) return;
      const total = Number(group.dataset.fileCount);
      const primary = group.querySelector('[data-dup-primary] [data-dup-select]');
      if (primary && selected.has(primary.value)) {
        tell('Unselect the first file in this group to preserve it as a survivor.');
        return;
      }
      try {
        // Obtain all eligible matches, even when members span multiple
        // server-paginated pages. The first file remains unselected.
        const response = await fetch(group.dataset.selectUrl, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Could not select every matching file. Refresh and try again.');
        const result = await response.json();
        if (!Array.isArray(result.files) || result.files.length !== total - 1)
          throw new Error('Duplicate group changed. Refresh before selecting its matches.');
        let added = 0;
        for (const item of result.files) {
          if (!selected.has(String(item.id))) added++;
          selected.set(String(item.id), item.path);
        }
        restoreSelections(group);
        tell('Selected ' + added + ' matching files; the first file remains unselected as the survivor.');
      } catch (error) {
        tell(error.message || 'Unable to select matching files.');
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

  browser.querySelector('[data-dup-select-visible]')?.addEventListener('click', () => {
    const boxes = [...browser.querySelectorAll('[data-dup-select]')].filter(box => !box.closest('tr').hidden);
    let added = 0;
    for (const box of boxes) {
      if (selected.has(box.value)) continue;
      box.checked = true;
      selected.set(box.value, box.dataset.filePath);
      added += 1;
    }
    syncSelected();
    tell('Selected ' + added + ' additional visible files.');
  });
  clearButton?.addEventListener('click', () => {
    selected.clear();
    browser.querySelectorAll('[data-dup-select]').forEach(box => { box.checked = false; });
    syncSelected();
    tell('File selection cleared.');
  });
  browser.querySelector('[data-dup-invert]')?.addEventListener('click', () => {
    const checkboxes = [...browser.querySelectorAll('[data-dup-select]')]
      .filter(box => !box.closest('tr').hidden);
    checkboxes.forEach(check => {
      check.checked = !check.checked;
      if (check.checked) selected.set(check.value, check.dataset.filePath);
      else selected.delete(check.value);
    });
    tell('Selection inverted for the files currently visible.');
    syncSelected();
  });

  // Native forms share the same selected IDs. Every action still opens its
  // corresponding preview before any file is changed.
  for (const form of browser.querySelectorAll(
      '#dup-bulk-delete-form,#dup-bulk-rename-form,#dup-bulk-move-form,#dup-bulk-link-form')) {
    form.addEventListener('submit', event => {
      const ids = form.querySelector('[data-dup-action-ids]');
      if (!selected.size || !ids || ids.value.split(',').length !== selected.size) {
        event.preventDefault();
        tell('Select files before opening an action preview.');
      }
    });
  }


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
