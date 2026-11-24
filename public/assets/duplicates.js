// Compact, single-table duplicate browser. Never load all file rows at once.
const browser = document.querySelector('#duplicate-groups');

if (browser) {
  const groups = [...browser.querySelectorAll('[data-dup-set]')];
  const pending = new WeakMap();
  const selected = new Map();
  const message = browser.querySelector('[data-dup-message]');
  const copyButton = browser.querySelector('[data-dup-copy]');
  const renameButton = browser.querySelector('[data-dup-rename]');
  const moveButton = browser.querySelector('[data-dup-move]');
  const quarantineButton = browser.querySelector('[data-dup-quarantine]');
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
    if (quarantineButton) quarantineButton.disabled = selected.size === 0;
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
      const visibleMatches = [...group.querySelectorAll('[data-dup-child] [data-dup-select]')];
      const already = visibleMatches.filter(input => selected.has(input.value)).length;
      if (total - 1 > 50 || selected.size + total - 1 - already > 50) {
        tell('Select up to 50 duplicates. Clear other selections or choose files individually.');
        return;
      }
      await expand(group);
      if (!expanded(group)) return;
      const inputs = [...group.querySelectorAll('[data-dup-child] [data-dup-select]')];
      if (inputs.length !== total - 1) {
        tell('Not all matches were loaded. Use the group pager to select files individually.');
        return;
      }
      if (selected.size + inputs.filter(input => !selected.has(input.value)).length > 50) {
        tell('Your current selection exceeds the 50-file limit.');
        return;
      }
      for (const input of inputs) {
        input.checked = true;
        selected.set(input.value, input.dataset.filePath);
      }
      syncSelected();
      tell('Selected ' + inputs.length + ' matching files; the first file remains unselected as the survivor.');
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
    const capacity = Math.max(0, 50 - selected.size);
    let added = 0;
    for (const box of boxes) {
      if (selected.has(box.value) || added >= capacity) continue;
      box.checked = true;
      selected.set(box.value, box.dataset.filePath);
      added += 1;
    }
    syncSelected();
    tell('Selected ' + added + ' additional visible files (maximum 50 per batch).');
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
    if (selected.size > 50) {
      // Copy can remain large; mutation routes still reject batches over 50.
      tell('Selection contains more than 50 files; choose no more than 50 for a bulk action.');
    } else {
      tell('Selection inverted for the files currently visible.');
    }
    syncSelected();
  });

  renameButton?.addEventListener('click', () => {
    if (!selected.size) return;
    if (selected.size > 50) {
      tell('Select at most 50 files for a rename batch.');
      return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = browser.dataset.bulkRenameUrl;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = browser.dataset.bulkRenameToken;
    form.appendChild(token);
    for (const id of selected.keys()) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'files[]';
      input.value = id;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  });

  moveButton?.addEventListener('click', () => {
    if (!selected.size) return;
    if (selected.size > 50) {
      tell('Select at most 50 files for a move batch.');
      return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = browser.dataset.bulkMoveUrl;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = browser.dataset.bulkMoveToken;
    form.appendChild(token);
    for (const id of selected.keys()) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'files[]';
      input.value = id;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  });

  function submitBulkDuplicate(kind) {
    if (!selected.size || selected.size > 50) {
      tell('Select between 1 and 50 files for a bulk action.');
      return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = browser.dataset.bulkDuplicateUrl;
    for (const [name, value] of [
      ['_token', browser.dataset.bulkDuplicateToken],
      ['action_kind', kind]
    ]) {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      field.value = value;
      form.appendChild(field);
    }
    for (const id of selected.keys()) {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'files[]';
      field.value = id;
      form.appendChild(field);
    }
    document.body.appendChild(form);
    form.submit();
  }
  quarantineButton?.addEventListener('click', () => submitBulkDuplicate('bulk_quarantine'));
  linkButton?.addEventListener('click', () => submitBulkDuplicate('bulk_link'));

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
