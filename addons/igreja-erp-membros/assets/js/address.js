/* Optional ViaCEP lookup. No member details, credentials or form contents are sent. */
(() => {
  'use strict';
  function initialize() {
    document.querySelectorAll('[data-igreja-address]').forEach((group, index) => {
      const cep = group.querySelector('[data-address-field="cep"]');
      const button = group.querySelector('[data-address-lookup]');
      const status = group.querySelector('[data-address-status]');
      if (!cep || !button || !status || group.dataset.addressReady) return;
      group.dataset.addressReady = '1';
      status.id ||= 'igreja-address-status-' + index;
      cep.setAttribute('aria-describedby', status.id);
      const digits = value => String(value).replace(/\D/g, '').slice(0, 8);
      let timer, controller, sequence = 0, last = '';
      function invalidate() {
        ++sequence;
        clearTimeout(timer);
        if (controller) controller.abort();
        button.disabled = false;
        cep.removeAttribute('aria-busy');
      }
      async function lookup(force = false) {
        const code = digits(cep.value);
        if (code.length !== 8) {
          status.textContent = 'Informe os oito números do CEP ou preencha o endereço manualmente.';
          return;
        }
        if (!force && last === code) return;
        invalidate();
        const current = sequence;
        last = code;
        const requestController = new AbortController();
        controller = requestController;
        const timeout = setTimeout(() => requestController.abort(), 8000);
        const fields = Object.fromEntries(['logradouro', 'bairro', 'cidade', 'uf'].map(key => [key, group.querySelector('[data-address-field="' + key + '"]')]));
        const snapshot = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field?.value]));
        button.disabled = true;
        cep.setAttribute('aria-busy', 'true');
        status.textContent = 'Consultando CEP…';
        try {
          const response = await fetch('https://viacep.com.br/ws/' + code + '/json/', {signal: requestController.signal, credentials: 'omit', referrerPolicy: 'no-referrer'});
          if (!response.ok) throw new Error('lookup');
          const data = await response.json();
          if (current !== sequence || digits(cep.value) !== code) return;
          if (data.erro === true || data.erro === 'true') {
            last = '';
            status.textContent = 'CEP não encontrado. Confira o CEP ou preencha o endereço manualmente.';
            return;
          }
          if (digits(data.cep) !== code || typeof data.localidade !== 'string' || typeof data.uf !== 'string' || !/^[A-Z]{2}$/.test(data.uf)) throw new Error('invalid');
          const values = {logradouro: data.logradouro, bairro: data.bairro, cidade: data.localidade, uf: data.uf};
          for (const [key, field] of Object.entries(fields)) {
            // Preserve edits made by the person while the request was in flight.
            if (!field || field.value !== snapshot[key] || typeof values[key] !== 'string' || !values[key].trim()) continue;
            field.value = values[key].slice(0, field.maxLength > 0 ? field.maxLength : 200);
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
          }
          status.textContent = 'Endereço preenchido. Confira os dados e informe o número e o complemento.';
        } catch (error) {
          if (current === sequence && digits(cep.value) === code) {
            last = '';
            status.textContent = 'Não foi possível consultar o CEP agora. Você pode preencher o endereço manualmente.';
          }
        } finally {
          clearTimeout(timeout);
          if (current === sequence) {
            button.disabled = false;
            cep.removeAttribute('aria-busy');
          }
        }
      }
      cep.addEventListener('input', () => {
        invalidate();
        last = '';
        const code = digits(cep.value);
        cep.value = code.length > 5 ? code.slice(0, 5) + '-' + code.slice(5) : code;
        status.textContent = '';
        if (code.length === 8) timer = setTimeout(() => lookup(), 350);
      });
      cep.addEventListener('blur', () => { clearTimeout(timer); if (cep.value) lookup(); });
      button.addEventListener('click', () => lookup(true));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
  else initialize();
})();
