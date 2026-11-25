// Safe literal highlighting for filename, directory and search terms.
// Text is split into DOM text/mark nodes; never interpreted as HTML.
for (const root of document.querySelectorAll("[data-match-terms]")) {
  const terms = [...new Set(String(root.dataset.matchTerms || "")
    .split(",").map(value => value.trim()).filter(Boolean))]
    .sort((a,b) => b.length - a.length).slice(0,24);
  if (!terms.length) continue;
  const sensitive = root.dataset.matchCase === "1";
  for (const element of root.querySelectorAll("[data-highlight-text]")) {
    const text = element.textContent || "";
    if (!text) continue;
    const findText = sensitive ? text : text.toLocaleLowerCase();
    const compare = terms.map(term => sensitive ? term : term.toLocaleLowerCase());
    const replacement = document.createDocumentFragment();
    let offset = 0, any = false;
    while (offset < text.length) {
      let earliest = -1, matched = -1;
      for (let index=0; index<compare.length; index++) {
        const found = findText.indexOf(compare[index],offset);
        if (found !== -1 && (earliest === -1 || found < earliest ||
           (found === earliest && terms[index].length > terms[matched].length))) {
          earliest = found; matched = index;
        }
      }
      if (earliest === -1) {
        replacement.append(document.createTextNode(text.slice(offset)));
        break;
      }
      if (earliest > offset) replacement.append(document.createTextNode(text.slice(offset,earliest)));
      const marker = document.createElement("mark");
      marker.className = "match-highlight";
      marker.textContent = text.slice(earliest,earliest+terms[matched].length);
      replacement.append(marker);
      offset = earliest + terms[matched].length;
      any = true;
    }
    if (any) element.replaceChildren(replacement);
  }
}
