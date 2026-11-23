const endpoint = document.querySelector("[data-folder-browser-endpoint]")?.dataset.folderBrowserEndpoint;
const inputs = [...document.querySelectorAll("[data-directory-input]")];

if (endpoint && inputs.length) {
  const dialog = document.createElement("dialog");
  dialog.className = "folder-dialog";
  dialog.setAttribute("aria-label", "Select a NAS folder");
  dialog.innerHTML = `
    <div class="folder-dialog-heading"><h2>Select a folder</h2>
      <button type="button" class="secondary" data-close>Close</button></div>
    <div class="folder-dialog-path" data-current aria-live="polite"></div>
    <div class="folder-dialog-list" data-folder-list aria-live="polite"></div>
    <div class="folder-dialog-actions">
      <button type="button" class="secondary" data-parent>Up one level</button>
      <button type="button" class="primary" data-select disabled>Select this folder</button>
    </div>`;
  document.body.append(dialog);
  const list = dialog.querySelector("[data-folder-list]");
  const currentLabel = dialog.querySelector("[data-current]");
  const up = dialog.querySelector("[data-parent]");
  const select = dialog.querySelector("[data-select]");
  const close = dialog.querySelector("[data-close]");
  let activeInput;
  let currentPath = null;
  let parentPath = null;
  let generation = 0;
  let controller = null;

  function notice(message) {
    list.replaceChildren();
    const paragraph = document.createElement("p");
    paragraph.className = "muted";
    paragraph.textContent = message;
    list.append(paragraph);
  }
  async function browse(path = "") {
    controller?.abort();
    controller = new AbortController();
    const myGeneration = ++generation;
    select.disabled = true;
    currentPath = null;
    parentPath = null;
    up.disabled = true;
    currentLabel.textContent = "Loading directories...";
    notice("Loading...");
    try {
      const url = new URL(endpoint, window.location.href);
      if (path) url.searchParams.set("path", path);
      const response = await fetch(url, {
        signal: controller.signal, credentials: "same-origin",
        headers: { Accept: "application/json" }, cache: "no-store"
      });
      if (!response.ok) throw new Error("Folder listing was denied or unavailable.");
      const result = await response.json();
      if (generation !== myGeneration || !dialog.open) return;
      currentPath = typeof result.path === "string" ? result.path : null;
      parentPath = result.parent;
      currentLabel.textContent = currentPath || "Allowed scan roots";
      up.disabled = parentPath === null;
      select.disabled = !result.can_select;
      list.replaceChildren();
      const directories = Array.isArray(result.folders) ? result.folders : [];
      if (directories.length === 0) {
        notice(currentPath
          ? "No readable subfolders. You can select this directory."
          : "No scan roots configured. Set DUP_ALLOWED_SCAN_ROOTS in the private application configuration.");
      }
      for (const item of directories) {
        if (typeof item.path !== "string" || typeof item.name !== "string") continue;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "folder-dialog-item";
        button.textContent = "▸  " + item.name;
        button.addEventListener("click", () => browse(item.path));
        list.append(button);
      }
    } catch (error) {
      if (error.name === "AbortError" || generation !== myGeneration) return;
      currentLabel.textContent = "Folder unavailable";
      notice(error.message || "Unable to list folders.");
    }
  }
  up.addEventListener("click", () => browse(parentPath || ""));
  select.addEventListener("click", () => {
    if (!currentPath || !activeInput) return;
    activeInput.value = currentPath;
    activeInput.dispatchEvent(new Event("change", { bubbles: true }));
    dialog.close();
  });
  close.addEventListener("click", () => dialog.close());
  dialog.addEventListener("close", () => {
    controller?.abort();
    generation++;
  });
  for (const input of inputs) {
    const browseButton = document.createElement("button");
    browseButton.type = "button";
    browseButton.className = "secondary";
    browseButton.textContent = "Browse...";
    input.after(browseButton);
    browseButton.addEventListener("click", () => {
      activeInput = input;
      dialog.showModal();
      browse(input.value.trim());
    });
  }
}
