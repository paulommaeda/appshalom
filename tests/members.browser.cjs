const assert = require('node:assert/strict');
const {chromium, request} = require(process.env.IGREJA_PLAYWRIGHT);
const base = 'http://127.0.0.1:8080';
const publicUrl = base + '/?igreja_cadastro=1';
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
async function login(browser, user, path) {
  const context = await browser.newContext({viewport: {width: 390, height: 844}});
  const page = await context.newPage();
  await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(base + path));
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill('IgErp-CI-123!x');
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
  return {context, page};
}
(async () => {
  const http = await request.newContext();
  const browser = await chromium.launch({headless: true});
  try {
    const response = await http.get(publicUrl, {maxRedirects: 0});
    check(response.status() === 200, 'Formulário público acessível sem login');
    const html = await response.text();
    check(html.includes('cf-turnstile') && html.includes('member_registration'), 'CAPTCHA fica no formulário');
    check(!html.includes('fixture-secret') && !html.includes('Membro Integração'), 'Página pública não expõe segredo ou membros');
    check((response.headers()['cache-control'] || '').includes('no-store'), 'Formulário público sem cache');
    check((response.headers()['x-robots-tag'] || '').includes('noindex'), 'Formulário não indexável');
    check((await http.post(publicUrl, {form: {'membro[nome_completo]': 'CSRF'}})).status() === 403, 'POST público exige nonce');
    for (const extra of ['s=teste', 'feed=rss2', 'author=1', 'rest_route=/wp/v2/users']) {
      const blocked = await http.get(publicUrl + '&' + extra, {maxRedirects: 0});
      check([302, 401].includes(blocked.status()), 'Exceção não abre ' + extra + ': ' + blocked.status());
    }
    check((await http.get(base + '/', {maxRedirects: 0})).status() === 302, 'Restante do site continua privado');
    check((await http.post(base + '/wp-admin/admin-post.php', {form: {action: 'igreja_erp_save_member'}})).status() === 401, 'POST administrativo permanece fechado');
    const visitor = await browser.newContext({viewport: {width: 375, height: 812}});
    await visitor.route('https://challenges.cloudflare.com/turnstile/v0/api.js', route => route.fulfill({contentType: 'application/javascript', body: '/* Test widget; server validation fixture is installed only in CI. */'}));
    const formPage = await visitor.newPage();
    await formPage.goto(publicUrl);
    check(await formPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Cadastro público cabe no celular');
    const nonce = await formPage.locator('[name="_wpnonce"]').inputValue();
    const failed = await visitor.request.post(publicUrl, {form: {_wpnonce: nonce, 'membro[nome_completo]': 'Sem captcha', 'membro[email]': 'sem@example.org', 'membro[lgpd_aceite]': '1', 'cf-turnstile-response': 'invalid'}});
    check(failed.status() === 422 && (await failed.text()).includes('verificação de segurança'), 'CAPTCHA inválido impede envio HTTP');
    await formPage.getByLabel('Nome completo *', {exact: true}).fill('Visitante Navegador');
    await formPage.getByLabel('E-mail', {exact: true}).fill('navegador@example.org');
    await formPage.locator('[name="membro[lgpd_aceite]"]').check();
    await formPage.locator('form').evaluate(form => {
      const input = document.createElement('input');
      input.name = 'cf-turnstile-response'; input.type = 'hidden'; input.value = 'browser-valid-token'; form.append(input);
    });
    await Promise.all([formPage.waitForURL(/enviado=1/), formPage.getByRole('button', {name: 'Enviar cadastro', exact: true}).click()]);
    check(await formPage.getByRole('heading', {name: 'Cadastro recebido!'}).count() === 1, 'Formulário envia e mostra confirmação');
    const repeated = await visitor.request.post(publicUrl, {form: {_wpnonce: nonce, 'membro[nome_completo]': 'Repetido', 'membro[email]': 'repetido@example.org', 'membro[lgpd_aceite]': '1', 'cf-turnstile-response': 'browser-valid-token'}});
    check(repeated.status() === 422, 'Token repetido não cria novo cadastro');
    await visitor.close();

    const secretary = await login(browser, 'erp_members_secretaria', '/wp-admin/admin.php?page=igreja-erp-membros');
    const {page, context} = secretary;
    check(await page.getByRole('heading', {name: 'Membros', exact: true}).count() === 1, 'Secretaria acessa gestão');
    check((await context.request.get(base + '/wp-admin/admin.php?page=igreja-erp-cadastro')).status() === 403, 'Secretaria não configura chaves');
    check((await context.request.post(base + '/wp-admin/admin-post.php', {form: {action: 'igreja_erp_save_member', _wpnonce: 'invalid', 'membro[nome_completo]': 'Sem nonce'}})).status() === 403, 'Gravação administrativa exige nonce');
    await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-familias&novo=1');
    await page.getByLabel('Nome da família *', {exact: true}).fill('Família Navegador');
    await page.getByLabel('Cidade', {exact: true}).fill('Curitiba');
    await page.getByLabel('Estado (UF)', {exact: true}).fill('PR');
    await Promise.all([page.waitForURL(/salvo=1/), page.getByRole('button', {name: 'Salvar família', exact: true}).click()]);
    check(await page.getByText('Família salva.', {exact: true}).count() === 1, 'Secretaria cadastra família');
    await page.goto(base + '/wp-admin/admin.php?page=igreja-erp-membros&busca=Visitante+Navegador&status=Visitante');
    check(await page.locator('tbody tr').count() === 1 && (await page.locator('tbody').textContent()).includes('Visitante Navegador'), 'Busca e filtro encontram cadastro público');
    await page.locator('tbody').getByRole('link', {name: 'Editar', exact: true}).click();
    await page.locator('[name="membro[status]"]').selectOption('Membro Ativo');
    await page.locator('#igreja-family-search').fill('Família Navegador');
    await page.locator('#igreja-family-results').getByRole('button', {name: 'Família Navegador', exact: true}).click();
    await page.locator('[name="membro[parentesco]"]').selectOption('Titular');
    await Promise.all([page.waitForURL(/salvo=1/), page.getByRole('button', {name: 'Salvar membro', exact: true}).click()]);
    check(await page.locator('[name="membro[status]"]').inputValue() === 'Membro Ativo', 'Secretaria atualiza situação');
    check((await page.locator('#igreja-family-selected').textContent()) === 'Família Navegador', 'Vínculo familiar preservado');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Gestão de membros cabe no celular');
    await page.setViewportSize({width: 1440, height: 1000});
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Gestão de membros cabe no desktop');
    await context.close();

    const ordinary = await login(browser, 'erp_brand_member', '/');
    check((await ordinary.context.request.get(base + '/wp-admin/admin.php?page=igreja-erp-membros')).status() === 403, 'Membro comum não acessa lista');
    check((await ordinary.context.request.post(base + '/wp-admin/admin-post.php', {form: {action: 'igreja_erp_save_member', _wpnonce: 'invalid'}})).status() === 403, 'Membro comum não grava');
    await ordinary.context.close();

    const pastor = await login(browser, 'erp_brand_pastor', '/wp-admin/admin.php?page=igreja-erp-cadastro');
    check(await pastor.page.locator('[name="secret_key"]').inputValue() === '', 'Chave secreta não volta ao HTML administrativo');
    await pastor.page.locator('[name="enabled"]').uncheck();
    await Promise.all([pastor.page.waitForURL(/salvo=1/), pastor.page.getByRole('button', {name: 'Salvar cadastro público', exact: true}).click()]);
    const disabled = await http.get(publicUrl);
    check(disabled.status() === 503 && !(await disabled.text()).includes('name="membro[nome_completo]"'), 'Desativar cadastro bloqueia formulário');
    await pastor.page.locator('[name="enabled"]').check();
    await Promise.all([pastor.page.waitForURL(/salvo=1/), pastor.page.getByRole('button', {name: 'Salvar cadastro público', exact: true}).click()]);
    check((await http.get(publicUrl)).status() === 200, 'Chave vazia no admin mantém segredo anterior');
    if (process.env.IGREJA_MULTISITE === '1') {
      const other = await http.get(base + '/igreja-b/?igreja_cadastro=1');
      check(other.status() === 503 && !(await other.text()).includes('fixture-site'), 'Formulário e chaves isolados em outra igreja');
    }
    await pastor.context.close();
    console.log(checks + ' verificações HTTP e de interface de membros passaram.');
  } finally { await browser.close(); await http.dispose(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
