(function(){
function up(n,t){t=t.toUpperCase();while(n&&n.nodeType===1){if(n.tagName===t)return n;n=n.parentNode;}return null;}
function act(n){while(n&&n.nodeType===1){if(/^(A|INPUT|BUTTON|SELECT|OPTION|TEXTAREA|LABEL)$/i.test(n.tagName))return true;n=n.parentNode;}return false;}
function txt(n){return n?(n.textContent||n.innerText||'').replace(/^\s+|\s+$/g,''):'';}
function title(n){var a=n?n.getElementsByTagName('*'):[];for(var i=0;i<a.length;i++){var v=a[i].getAttribute('title');if(v)return v;}return '';}
function val(r,c){var x=r.cells[c],v='',q='';if(!x)return '';q=title(x);if(c===3&&/ bytes$/.test(q))v=q.replace(/[^0-9]/g,'');else if(c===4&&/^[0-9]+$/.test(q))v=q;else v=txt(x);return v.replace(/^[^0-9A-Za-z]+/,'');}
function norm(v){var n=String(v).replace(/,/g,'');if(n!==''&&/^-?[0-9]+(\.[0-9]+)?$/.test(n))return parseFloat(n);return String(v).toLowerCase();}
function cmp(a,b,d){var x=norm(a.v),y=norm(b.v),r=0;if(typeof x==='number'&&typeof y==='number')r=x-y;else if(x<y)r=-1;else if(x>y)r=1;if(r===0)r=a.i-b.i;return d?-r:r;}
function group(r){var g=[r],n=r.nextSibling,id='';while(n&&n.nodeType!==1)n=n.nextSibling;if(n)id=n.getAttribute('data-dupgroup')||'';while(id&&n&&n.nodeType===1&&(n.getAttribute('data-dupgroup')||'')===id){g.push(n);n=n.nextSibling;while(n&&n.nodeType!==1)n=n.nextSibling;}return g;}
function sec(){return {head:null,rows:[]};}
function addRow(s,r,i,c){var g=group(r);s.rows.push({i:i,v:val(r,c),g:g});return g.length;}
function sort(t,c,d){var b=t.tBodies&&t.tBodies.length?t.tBodies[0]:null,h=t.tHead&&t.tHead.rows.length?t.tHead.rows[0].cells.length:0,sections=[],s=sec();if(!b||h<=0)return;for(var i=0;i<b.rows.length;){var r=b.rows[i];if(r.getAttribute('data-dupgroup')){i++;continue;}if(r.cells.length!==h){if(s.head||s.rows.length)sections.push(s);s=sec();s.head=r;i++;continue;}i+=addRow(s,r,i,c);}if(s.head||s.rows.length)sections.push(s);while(b.firstChild)b.removeChild(b.firstChild);for(var x=0;x<sections.length;x++){s=sections[x];if(s.head)b.appendChild(s.head);s.rows.sort(function(a,b){return cmp(a,b,d);});for(var y=0;y<s.rows.length;y++){for(var z=0;z<s.rows[y].g.length;z++)b.appendChild(s.rows[y].g[z]);}}}
document.addEventListener('click',function(e){var th=up(e.target,'th'),t,c,d;if(!th||act(e.target))return;t=up(th,'table');if(!t||t.id!=='showListTable')return;if(t.tFoot&&t.tFoot.contains(th))return;c=th.cellIndex;if(c<0||c===6)return;e.preventDefault();e.stopPropagation();if(e.stopImmediatePropagation)e.stopImmediatePropagation();d=th.getAttribute('data-dup-sort-dir')==='asc';if(t.tHead&&t.tHead.rows.length){for(var i=0;i<t.tHead.rows[0].cells.length;i++)t.tHead.rows[0].cells[i].removeAttribute('data-dup-sort-dir');}th.setAttribute('data-dup-sort-dir',d?'desc':'asc');sort(t,c,d);},true);
})();
