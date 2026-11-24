const assert = require('node:assert/strict');
const {chromium, request} = require(process.env.IGREJA_PLAYWRIGHT);
const base = 'http://127.0.0.1:8080';
const plugin = 'igreja-erp-membros/igreja-erp-membros.php';
const network = process.env.IGREJA_MULTISITE === '1';
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
(async () => {
  const browser = await chromium.launch({headless: true});
  const http = await request.newContext();
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(base + '/wp-admin/admin.php?page=igreja-erp-addons'));
    await page.locator('#user_login').fill('tests');
    await page.locator('#user_pass').fill('igreja_erp_disposable_test');
    await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
    check(await page.getByRole('heading', {name: 'Addons da igreja'}).count() === 1, 'Área de addons disponível');
    check(await page.getByRole('heading', {name: 'Membros', exact: true}).count() === 1, 'Addon Membros no catálogo');
    async function deactivate() {
      if (network) {
        await page.goto(base + '/wp-admin/network/plugins.php');
        await page.locator(`tr[data-plugin="${plugin}"] .deactivate a`).click();
      } else {
        await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-addons');
        await page.getByRole('link', {name: 'Desativar', exact: true}).click();
      }
      await page.waitForLoadState('load');
    }
    await deactivate();
    check((await http.get(base + '/?igreja_cadastro=1', {maxRedirects: 0})).status() === 302, 'Desativar addon remove exceção pública');
    check((await context.request.get(base + '/wp-admin/admin.php?page=igreja-erp-membros')).status() === 403, 'Tela do addon indisponível quando desativado');
    await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-core');
    check(await page.getByRole('heading', {name: 'Configurações da igreja', exact: true}).count() === 1, 'Core permanece funcional sem addon');
    await page.goto(base + (network ? '/wp-admin/network/plugins.php' : '/wp-admin/plugins.php'));
    await page.locator(`tr[data-plugin="${plugin}"] .activate a`).click();
    await page.waitForLoadState('load');
    check((await http.get(base + '/?igreja_cadastro=1', {maxRedirects: 0})).status() === 200, 'Reativar restaura formulário');
    await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-membros&busca=Visitante+Navegador');
    check((await page.locator('tbody').textContent()).includes('Visitante Navegador'), 'Cadastros preservados ao reativar');
    await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-cadastro');
    check(!await page.locator('[name="fields[profissao]"]').isChecked(), 'Configuração dos campos preservada ao reativar');
    check(!await page.locator('[name="captcha_enabled"]').isChecked(), 'Opção do CAPTCHA preservada');
    // Fresh-process checks in the next CI step verify Core isolation once inactive.
    await deactivate();
    await context.close();
    console.log(checks + ' verificações de addons no navegador passaram.');
  } finally { await browser.close(); await http.dispose(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
