/*
 * Configuration-table controls: sort/filter all rendered rows, and page long
 * tables locally. Database-backed result pages use server keyset pagination.
 * This is deliberately limited to tables marked data-local-table.
 */
const collator = new Intl.Collator(undefined,{numeric:true,sensitivity:"base"});
for (const table of document.querySelectorAll("table[data-local-table]")) {
  const tbody=table.tBodies[0];
  if (!tbody) continue;
  const rows=[...tbody.rows].filter(row=>row.cells.length>1);
  if (!rows.length) continue;
  rows.forEach((row,index)=>{row.dataset.originalIndex=String(index);});
  const label=table.dataset.localTable || "table rows";
  const id="local-filter-"+Math.random().toString(36).slice(2,10);
  const controls=document.createElement("div");
  controls.className="local-table-controls";
  const filterLabel=document.createElement("label");
  filterLabel.htmlFor=id;
  filterLabel.textContent="Filter "+label;
  const search=document.createElement("input");
  search.type="search";
  search.id=id;
  search.maxLength=200;
  search.autocomplete="off";
  search.placeholder="Filter rows";
  const status=document.createElement("span");
  status.className="muted";
  status.setAttribute("aria-live","polite");
  controls.append(filterLabel,search,status);
  const wrap=table.closest(".table-wrap");
  (wrap || table).before(controls);

  const showPager=rows.length>25;
  let pageSize=25, page=0;
  let pager=null, previous=null, next=null, pageStatus=null;
  if (showPager) {
    pager=document.createElement("nav");
    pager.className="local-table-pager";
    pager.setAttribute("aria-label",label+" pagination");
    const sizeLabel=document.createElement("label");
    sizeLabel.textContent="Rows per page ";
    const sizeSelect=document.createElement("select");
    sizeSelect.setAttribute("aria-label","Rows per page for "+label);
    for (const n of [25,50,100,0]) {
      const option=document.createElement("option");
      option.value=String(n);
      option.textContent=n?String(n):"All";
      sizeSelect.append(option);
    }
    sizeLabel.append(sizeSelect);
    previous=document.createElement("button");
    previous.type="button";
    previous.textContent="Previous";
    next=document.createElement("button");
    next.type="button";
    next.textContent="Next";
    pageStatus=document.createElement("span");
    pageStatus.setAttribute("aria-live","polite");
    pager.append(sizeLabel,previous,pageStatus,next);
    (wrap || table).after(pager);
    sizeSelect.addEventListener("change",()=>{
      pageSize=Number(sizeSelect.value);page=0;render();
    });
    previous.addEventListener("click",()=>{page=Math.max(0,page-1);render();});
    next.addEventListener("click",()=>{page++;render();});
  }

  function render(reset=false) {
    if (reset) page=0;
    const query=search.value.trim().toLocaleLowerCase();
    const matched=rows.filter(row=>!query || row.textContent.toLocaleLowerCase().includes(query));
    const totalPages=pageSize===0 ? 1 : Math.max(1,Math.ceil(matched.length/pageSize));
    page=Math.min(page,totalPages-1);
    const from=pageSize===0?0:page*pageSize;
    const until=pageSize===0?matched.length:Math.min(from+pageSize,matched.length);
    const visible=new Set(matched.slice(from,until));
    const matchedSet=new Set(matched);
    for (const row of rows) {
      row.hidden=!visible.has(row);
      row.classList.toggle("local-row-filtered",!matchedSet.has(row));
    }
    status.textContent=matched.length.toLocaleString()+" of "+rows.length.toLocaleString()+" matching";
    if (pager) {
      previous.disabled=page===0;
      next.disabled=page>=totalPages-1;
      pageStatus.textContent=(matched.length?(from+1)+"–"+until:"0")+
        " of "+matched.length.toLocaleString()+" · page "+(page+1)+"/"+totalPages;
    }
  }
  search.addEventListener("input",()=>render(true));
  let sorted=-1,ascending=true;
  for (const [column,th] of [...(table.tHead?.rows?.[0]?.cells??[])].entries()) {
    if (th.hasAttribute("data-no-sort") ||
       th.textContent.trim().toLowerCase()==="actions" ||
       th.querySelector("input,select,button") || column>=rows[0].cells.length) continue;
    const name=th.textContent.trim();
    if (!name) continue;
    const button=document.createElement("button");
    button.type="button";button.className="local-sort-button";
    button.title="Sort "+name+" (all displayed records)";
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
      ascending=sorted===column?!ascending:true;
      sorted=column;
      const type=th.dataset.sortType||"text";
      const value=row=>{
        const cell=row.cells[column]||null;
        const string=cell?.dataset.sortValue??cell?.textContent.trim()??"";
        if (type==="number") {
          const match=string.replaceAll(",","").match(/-?\d+(?:\.\d+)?/);
          return match?Number(match[0]):Number.NEGATIVE_INFINITY;
        }
        return string;
      };
      rows.sort((a,b)=>{
        const x=value(a),y=value(b);
        const diff=type==="number"?x-y:collator.compare(String(x),String(y));
        if (diff!==0) return ascending?diff:-diff;
        return Number(a.dataset.originalIndex)-Number(b.dataset.originalIndex);
      });
      tbody.append(...rows);
      for (const cell of table.tHead.rows[0].cells) {
        cell.setAttribute("aria-sort",cell===th?(ascending?"ascending":"descending"):"none");
        const arrow=cell.querySelector(".local-sort-indicator");
        if (arrow) arrow.textContent=cell===th?(ascending?"↑":"↓"):"↕";
      }
      render();
    });
  }
  render();
}
