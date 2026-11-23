// File-group browsing stays on the results page. Load only expanded groups.
const panel = document.getElementById('duplicate-groups');
if (panel) {
  const pending = new WeakMap();
  const toggles = [...panel.querySelectorAll('[data-dup-toggle]')];

  async function loadInto(target, url) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!response.ok || response.redirected) {
      throw new Error('The group could not be loaded. Refresh the results page.');
    }
    target.innerHTML = await response.text();
    target.dataset.loaded = '1';
  }

  async function expand(button) {
    const detailRow = button.closest('tr')?.nextElementSibling;
    const target = detailRow?.querySelector('[data-dup-detail]');
    if (!detailRow || !target) return;
    detailRow.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    button.setAttribute('aria-label', 'Collapse duplicate group');
    if (target.dataset.loaded === '1') return;
    if (!pending.has(target)) {
      target.textContent = 'Loading files…';
      const task = loadInto(target, button.dataset.membersUrl)
        .catch(error => {
          target.dataset.loaded = '0';
          target.textContent = error.message || 'Unable to load the files.';
        })
        .finally(() => pending.delete(target));
      pending.set(target, task);
    }
    await pending.get(target);
  }

  function collapse(button) {
    const detailRow = button.closest('tr')?.nextElementSibling;
    if (detailRow) detailRow.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', 'Expand duplicate group');
  }

  function toggle(button) {
    return button.getAttribute('aria-expanded') === 'true'
      ? collapse(button)
      : expand(button);
  }

  panel.addEventListener('click', async event => {
    const button = event.target.closest('[data-dup-toggle]');
    if (button) {
      event.preventDefault();
      await toggle(button);
      return;
    }
    const memberPage = event.target.closest('a[data-dup-page]');
    if (memberPage) {
      event.preventDefault();
      const target = memberPage.closest('[data-dup-detail]');
      if (!target || pending.has(target)) return;
      const currentUrl = memberPage.dataset.ajaxUrl;
      const task = loadInto(target, currentUrl)
        .catch(error => {
          const message = document.createElement('p');
          message.className = 'notice notice-error';
          message.textContent = error.message || 'Unable to load this page.';
          target.prepend(message);
        })
        .finally(() => pending.delete(target));
      pending.set(target, task);
      await task;
      return;
    }
    // Rows themselves can be clicked, without hijacking links or forms.
    const row = event.target.closest('[data-dup-group-row]');
    if (row && !event.target.closest('a,button,input,select,form,label')) {
      await toggle(row.querySelector('[data-dup-toggle]'));
    }
  });

  panel.querySelector('[data-dup-collapse-all]')?.addEventListener('click', () => {
    toggles.forEach(collapse);
  });

  panel.querySelector('[data-dup-expand-all]')?.addEventListener('click', async () => {
    const remaining = toggles.filter(button =>
      button.getAttribute('aria-expanded') !== 'true');
    let index = 0;
    // Four concurrent requests, not 50 simultaneous database queries.
    await Promise.all(Array.from({ length: Math.min(4, remaining.length) },
      async () => {
        while (index < remaining.length) {
          const button = remaining[index++];
          await expand(button);
        }
      }));
  });
}
