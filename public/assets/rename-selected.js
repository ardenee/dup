// Compact manual rename data avoids PHP max_input_vars truncation.
const form = document.querySelector('[data-rename-selected-form]');
form?.addEventListener('submit', () => {
  const fields = [...form.querySelectorAll('[data-rename-selected-id]')];
  const names = {};
  fields.forEach(field => { names[field.dataset.renameSelectedId] = field.value; });
  form.querySelector('[name="names_json"]').value = JSON.stringify(names);
  fields.forEach(field => { field.disabled = true; });
});
