const toggle=document.getElementById('rename-select-all');
const selected=Array.from(document.querySelectorAll('.rename-selection'));
const submit=document.getElementById('rename-queue');
const count=document.getElementById('rename-selected-count');
function refresh(){
  const checked=selected.filter(box=>box.checked).length;
  if(submit) submit.disabled=checked===0;
  if(count) count.textContent=`${checked} selected`;
  if(toggle) {toggle.checked=selected.length>0 && checked===selected.length;toggle.indeterminate=checked>0 && checked<selected.length;}
}
toggle?.addEventListener('change',()=>{selected.forEach(box=>{box.checked=toggle.checked});refresh()});
selected.forEach(box=>box.addEventListener('change',refresh));
refresh();
