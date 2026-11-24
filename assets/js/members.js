/* Search current-site families through an authenticated, nonce-protected endpoint. */
(() => {
  'use strict';
  const search = document.getElementById('igreja-family-search');
  if (!search) return;
  const results = document.getElementById('igreja-family-results');
  const chosen = document.getElementById('igreja-family-selected');
  const id = document.getElementById('igreja-family-id');
  let timer, controller, generation = 0;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    if (controller) controller.abort();
    const current = ++generation;
    results.replaceChildren();
    if (search.value.trim().length < 2) return;
    timer = setTimeout(async () => {
      controller = new AbortController();
      const url = new URL(igrejaMembers.ajaxUrl);
      url.search = new URLSearchParams({action: 'igreja_erp_buscar_familias', _ajax_nonce: igrejaMembers.nonce, busca: search.value.trim()});
      try {
        const response = await fetch(url, {credentials: 'same-origin', signal: controller.signal});
        const json = await response.json();
        if (current !== generation) return;
        if (!response.ok || !json.success || !Array.isArray(json.data)) throw new Error('search');
        results.replaceChildren();
        if (!json.data.length) results.textContent = 'Nenhuma família encontrada.';
        for (const family of json.data) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'button igreja-family-result';
          button.textContent = family.nome;
          button.addEventListener('click', () => {
            id.value = String(family.id);
            chosen.textContent = family.nome;
            search.value = '';
            results.replaceChildren();
            ++generation;
          });
          results.append(button);
        }
      } catch (error) {
        if (error.name !== 'AbortError' && current === generation) results.textContent = 'Não foi possível buscar. Tente novamente.';
      }
    }, 250);
  });
  document.getElementById('igreja-family-clear').addEventListener('click', () => {
    id.value = '0';
    chosen.textContent = 'Nenhuma família vinculada';
    search.value = '';
    results.replaceChildren();
    ++generation;
    if (controller) controller.abort();
  });
})();
