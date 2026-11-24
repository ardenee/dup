/*
 * Progressive sorting/filtering for fully rendered configuration tables.
 * Paginated datasets use server-side controls instead; never imply that
 * sorting 25-50 visible rows changes the complete database result set.
 */
const collator = new Intl.Collator(undefined,{numeric:true,sensitivity:"base"});
for (const table of document.querySelectorAll("table[data-local-table]")) {
  const tbody=table.tBodies[0];
  if (!tbody) continue;
  const rows=[...tbody.rows].filter(row=>row.cells.length>1);
  if (rows.length===0) continue;

  const id="filter-table-"+Math.random().toString(36).slice(2,10);
  const label=table.dataset.localTable || "table rows";
  const controls=document.createElement("div");
  controls.className="local-table-controls";
  controls.innerHTML='<label></label><input type="search" autocomplete="off" placeholder="Filter rows…" maxlength="200"><span class="muted" aria-live="polite"></span>';
  const labelNode=controls.querySelector("label");
  const input=controls.querySelector("input");
  const count=controls.querySelector("span");
  input.id=id;
  labelNode.htmlFor=id;
  labelNode.textContent="Filter "+label;
  const wrap=table.closest(".table-wrap");
  (wrap || table).before(controls);

  function updateFilter(){
    const query=input.value.trim().toLocaleLowerCase();
    let visible=0;
    for (const row of rows) {
      const matches=!query || row.textContent.toLocaleLowerCase().includes(query);
      row.hidden=!matches;
      row.classList.toggle("local-row-filtered",!matches);
      if(matches) visible++;
    }
    count.textContent=visible.toLocaleString()+" / "+rows.length.toLocaleString()+" shown";
  }
  input.addEventListener("input",updateFilter);
  updateFilter();

  let selected=-1;
  let ascending=true;
  for (const [column,th] of [...(table.tHead?.rows?.[0]?.cells ?? [])].entries()) {
    if(th.hasAttribute("data-no-sort") ||
       th.textContent.trim().toLowerCase()==="actions" ||
       th.querySelector("input,select,button") || column>=rows[0].cells.length)
      continue;
    const name=th.textContent.trim();
    if(name==="")continue;
    const button=document.createElement("button");
    button.type="button";
    button.className="local-sort-button";
    button.title="Sort "+name+" (all rows in this table)";
    const indicator=document.createElement("span");
    indicator.className="local-sort-indicator";
    indicator.setAttribute("aria-hidden","true");
    indicator.textContent="↕";
    th.textContent="";
    button.textContent=name+" ";
    button.append(indicator);
    th.append(button);
    th.setAttribute("aria-sort","none");

    button.addEventListener("click",()=>{
      ascending=selected===column?!ascending:true;
      selected=column;
      const type=th.dataset.sortType || "text";
      const value=row=>{
        const cell=row.cells[column]??null;
        const explicit=cell?.dataset.sortValue;
        const text=explicit??cell?.textContent.trim()??"";
        if(type==="number") {
          const m=text.replaceAll(",","").match(/-?\d+(?:\.\d+)?/);
          return m?Number(m[0]):Number.NEGATIVE_INFINITY;
        }
        if(type==="date")return text; // ISO timestamps sort lexically
        return text;
      };
      rows.sort((a,b)=>{
        const va=value(a),vb=value(b);
        const difference=type==="number"?va-vb:collator.compare(String(va),String(vb));
        if(difference!==0)return ascending?difference:-difference;
        return (Number(a.dataset.originalIndex)-Number(b.dataset.originalIndex));
      });
      tbody.append(...rows);
      for (const other of table.tHead.rows[0].cells) {
        other.setAttribute("aria-sort",other===th?(ascending?"ascending":"descending"):"none");
        const arrow=other.querySelector(".local-sort-indicator");
        if(arrow)arrow.textContent=other===th?(ascending?"↑":"↓"):"↕";
      }
      updateFilter();
    });
  }
  rows.forEach((row,index)=>{row.dataset.originalIndex=String(index);});
}
