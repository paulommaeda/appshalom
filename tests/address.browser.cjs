const assert = require('node:assert/strict');
const {chromium} = require(process.env.IGREJA_PLAYWRIGHT);
const base = 'http://127.0.0.1:8080';
const publicUrl = base + '/?igreja_cadastro=1';
let checks = 0;
function check(value, message) {assert.ok(value, message);checks++;}
(async () => {
  const browser = await chromium.launch({headless: true});
  try {
    const context = await browser.newContext({viewport: {width: 375, height: 812}});
    let calls = 0;
    await context.route('https://viacep.com.br/ws/*/json/', async route => {
      ++calls;
      const cep = route.request().url().match(/ws\/(\d{8})/)[1];
      const body = cep === '99999999' ? {erro: true} : {cep: '01001-000', logradouro: 'Praça da Sé', bairro: 'Sé', localidade: 'São Paulo', uf: 'SP'};
      if (cep === '80000000') { await route.abort('failed'); return; }
      if (cep === '01001000') await new Promise(resolve => setTimeout(resolve, 200));
      await route.fulfill({status: 200, contentType: 'application/json', headers: {'access-control-allow-origin': '*'}, body: JSON.stringify(body)});
    });
    const page = await context.newPage();
    await page.goto(publicUrl);
    await page.waitForFunction(() => document.querySelector('[data-igreja-address]')?.dataset.addressReady === '1');
    check(await page.getByRole('heading', {name: 'Endereço', exact: true}).count() === 1, 'Endereço no público');
    const input = key => page.locator('[name="membro[' + key + ']"]');
    await input('cep').fill('01001000');
    await page.waitForFunction(() => document.querySelector('[name="membro[logradouro]"]').value === 'Praça da Sé');
    check(await input('cep').inputValue() === '01001-000', 'Máscara de CEP');
    check(await input('bairro').inputValue() === 'Sé' && await input('cidade').inputValue() === 'São Paulo' && await input('uf').inputValue() === 'SP', 'Preenchimento automático');
    await input('numero').fill('250');
    await input('complemento').fill('Apartamento 42');
    await input('logradouro').fill('Praça da Sé corrigida');
    check(await input('logradouro').inputValue() === 'Praça da Sé corrigida', 'Endereço permanece editável');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Formulário com endereço cabe no celular');
    await input('nome_completo').fill('Endereço Navegador');
    await input('email').fill('address-browser@example.org');
    await page.locator('[name="membro[lgpd_aceite]"]').check();
    await Promise.all([page.waitForURL(/enviado=1/), page.getByRole('button', {name: 'Enviar cadastro', exact: true}).click()]);
    check(await page.getByRole('heading', {name: 'Cadastro recebido!'}).count() === 1, 'Endereço enviado no público');
    await page.goto(publicUrl);
    await input('cep').fill('99999999');
    await page.waitForFunction(() => document.querySelector('[data-address-status]').textContent.includes('não encontrado'));
    check(await page.getByRole('button', {name: 'Enviar cadastro'}).isEnabled(), 'CEP não encontrado permite preenchimento manual');
    await input('cep').fill('80000000');
    await page.waitForFunction(() => document.querySelector('[data-address-status]').textContent.includes('manualmente'));
    check(await input('logradouro').isEditable(), 'Falha de rede preserva campos editáveis');
    await input('numero').fill('S/N');
    await input('complemento').fill('Casa');
    await input('cep').fill('01001000');
    await page.waitForFunction(() => document.querySelector('[data-address-status]').textContent.includes('Consultando'));
    await input('logradouro').fill('Correção durante consulta');
    await page.waitForFunction(() => document.querySelector('[data-address-status]').textContent.includes('Endereço preenchido'));
    check(await input('logradouro').inputValue() === 'Correção durante consulta', 'Resposta não sobrescreve edição durante consulta');
    check(await input('numero').inputValue() === 'S/N' && await input('complemento').inputValue() === 'Casa', 'Consulta preserva número e complemento');
    await context.close();

    const admin = await browser.newContext({viewport: {width: 390, height: 844}});
    await admin.route('https://viacep.com.br/ws/*/json/', route => route.fulfill({status: 200, contentType: 'application/json', headers: {'access-control-allow-origin': '*'}, body: JSON.stringify({cep: '01001-000', logradouro: 'Praça da Sé', bairro: 'Sé', localidade: 'São Paulo', uf: 'SP'})}));
    const edit = await admin.newPage();
    await edit.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(base + '/wp-admin/admin.php?page=igreja-erp-membros&busca=address-browser'));
    await edit.locator('#user_login').fill('tests');
    await edit.locator('#user_pass').fill('igreja_erp_disposable_test');
    await Promise.all([edit.waitForURL(u => !u.pathname.endsWith('/wp-login.php')), edit.locator('#wp-submit').click()]);
    await edit.locator('tbody').getByRole('link', {name: 'Editar', exact: true}).click();
    check(await edit.locator('[name="membro[logradouro]"]').inputValue() === 'Praça da Sé corrigida' && await edit.locator('[name="membro[complemento]"]').inputValue() === 'Apartamento 42', 'Endereço público aparece na ficha do membro');
    await edit.waitForFunction(() => document.querySelector('[data-igreja-address]')?.dataset.addressReady === '1');
    await edit.locator('[name="membro[cep]"]').fill('01001000');
    await edit.waitForFunction(() => document.querySelector('[name="membro[logradouro]"]').value === 'Praça da Sé');
    await edit.locator('[name="membro[numero]"]').fill('351');
    await Promise.all([edit.waitForNavigation(), edit.getByRole('button', {name: 'Salvar membro', exact: true}).click()]);
    check(await edit.locator('[name="membro[numero]"]').inputValue() === '351', 'Edição do endereço salva');
    await admin.close();
    check(calls >= 4, 'Consulta realizada na interação com o CEP');
    console.log(checks + ' verificações de consulta de CEP e endereço no navegador passaram.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode = 1;});
