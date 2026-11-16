const assert = require('node:assert/strict');
const {chromium, request} = require(process.env.IGREJA_PLAYWRIGHT);
const base = 'http://127.0.0.1:8080';
let checks = 0;
function check(value, message) {assert.ok(value, message); checks++;}
async function login(browser, user, pass, target='/') {
    const context = await browser.newContext({viewport:{width:390,height:844}});
    const page = await context.newPage();
    await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(base + target));
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(pass);
    await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
    return {context, page};
}
(async () => {
    const http = await request.newContext();
    for (let attempt = 0; attempt < 30; attempt++) {
        try {await http.get(base + '/wp-login.php'); break;} catch (error) {
            if (attempt === 29) throw error;
            await new Promise(resolve => setTimeout(resolve, 250));
        }
    }
    for (const path of ['/', '/?feed=rss2', '/?s=teste', '/?author=1']) {
        const response = await http.get(base + path, {maxRedirects:0});
        check(response.status() === 302, 'Visitante redirecionado em ' + path);
        check((response.headers().location || '').includes('wp-login.php'), 'Destino de login seguro');
        check((response.headers()['cache-control'] || '').includes('no-store'), 'Conteúdo privado não é cacheado');
    }
    const rest = await http.get(base + '/?rest_route=/wp/v2/posts');
    check(rest.status() === 401, 'REST nega visitantes');
    check((await rest.json()).code === 'igreja_erp_site_restrito', 'Erro REST sem revelar dados');
    const ajax = await http.post(base + '/wp-admin/admin-ajax.php', {form:{action:'rest-nonce'}});
    check(ajax.status() === 401, 'AJAX anônimo bloqueado');
    const post = await http.post(base + '/wp-admin/admin-post.php', {form:{action:'igreja_erp_save_settings'}});
    check(post.status() === 401, 'admin-post anônimo bloqueado');
    const xmlrpc = await http.post(base + '/xmlrpc.php', {headers:{'content-type':'text/xml'}, data:'<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params/></methodCall>'});
    check(xmlrpc.status() === 403 || (await xmlrpc.text()).includes('acesso autenticado'), 'XML-RPC não expõe métodos');
    const lost = await http.get(base + '/wp-login.php?action=lostpassword');
    check(lost.status() === 200 && (await lost.text()).includes('lostpasswordform'), 'Recuperação de senha permanece disponível');

    const browser = await chromium.launch({headless:true});
    try {
        const visitor = await browser.newContext({viewport:{width:375,height:812}});
        const loginPage = await visitor.newPage();
        await loginPage.goto(base + '/wp-login.php');
        check((await loginPage.locator('.igreja-login-name').textContent()).includes('CCShalom Curitiba'), 'Login exibe identidade inicial');
        check(await loginPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Login sem rolagem horizontal no celular');
        const styleLink = await loginPage.locator('#igreja-erp-login-css').getAttribute('href');
        const styleResponse = await http.get(styleLink, {maxRedirects:0});
        check(styleResponse.status() === 200, 'CSS da entrada acessível: ' + styleResponse.status() + ' ' + styleLink + ' ' + JSON.stringify(styleResponse.headers()));
        const family = await loginPage.locator('body').evaluate(el=>getComputedStyle(el).fontFamily);
        check(family.includes('Montserrat'), 'Fonte institucional carregada: ' + family + ' / ' + styleLink + ' / ' + (await styleResponse.text()).slice(0,100));
        await loginPage.evaluate(()=>document.fonts.ready);
        check(await loginPage.evaluate(()=>[...document.fonts].some(font=>font.family === 'Montserrat' && font.status === 'loaded')), 'Arquivo da fonte local carregado');
        const originalLogo = await loginPage.locator('h1 a').evaluate(el=>getComputedStyle(el).backgroundImage);
        check(originalLogo.includes('ccshalom.com.br'), 'Logo inicial aponta para a marca oficial');
        await visitor.close();

        const {context, page} = await login(browser, 'erp_brand_pastor', 'IgErp-CI-123!x', '/wp-admin/admin.php?page=igreja-erp-core');
        await page.locator('#igreja-brand-preview').waitFor();
        check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), 'Configurações sem rolagem horizontal no celular');
        await page.getByLabel('Nome de apresentação', {exact:true}).fill('Comunidade Vida — Teste');
        check((await page.locator('#igreja-preview-name').textContent()) === 'Comunidade Vida — Teste', 'Prévia acompanha o nome');
        await page.locator('[data-color=cor_destaque]').fill('#234567');
        await page.locator('[data-color=cor_destaque]').dispatchEvent('input');
        check((await page.locator('#igreja-brand-preview').evaluate(el=>getComputedStyle(el).getPropertyValue('--igreja-accent'))).trim() === '#234567', 'Prévia acompanha as cores');
        await page.locator('#igreja-select-logo').click();
        await page.locator('.media-modal').waitFor();
        const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=', 'base64');
        await page.locator('input[type=file]').last().setInputFiles({name:'logo-test.png',mimeType:'image/png',buffer:pixel});
        await page.getByRole('button', {name:'Usar esta logo', exact:true}).waitFor();
        await page.waitForFunction(()=> {
            const selected = document.querySelector('.media-modal .attachment.selected');
            return selected && !selected.classList.contains('uploading');
        });
        await page.getByRole('button', {name:'Usar esta logo', exact:true}).click();
        check(Number(await page.locator('#igreja-logo-id').inputValue()) > 0, 'Logo enviada e selecionada');
        const uploadedLogo = await page.locator('#igreja-logo-url').inputValue();
        await page.locator('#igreja-logo-url').fill(uploadedLogo + '?version=1&variant=logo');
        await Promise.all([page.waitForNavigation({waitUntil:'load'}), page.getByRole('button',{name:'Salvar configurações',exact:true}).click()]);
        check((await page.getByLabel('Nome de apresentação',{exact:true}).inputValue()) === 'Comunidade Vida — Teste', 'Identidade salva pelo pastor');
        check((await page.locator('[data-color=cor_destaque]').inputValue()) === '#234567', 'Cor salva');
        check((await page.locator('#igreja-logo-url').inputValue()).includes('/uploads/'), 'Logo local salva');

        const rejected = await context.request.post(base + '/wp-admin/admin-post.php', {form:{action:'igreja_erp_save_settings',_wpnonce:'invalid','igreja_erp_settings[nome_curto]':'Alteração indevida'}});
        check(rejected.status() === 403, 'Nonce inválido bloqueia gravação');
        await page.reload();
        check((await page.getByLabel('Nome de apresentação',{exact:true}).inputValue()) === 'Comunidade Vida — Teste', 'Tentativa sem nonce não altera nome');

        const member = await login(browser, 'erp_brand_member', 'IgErp-CI-123!x');
        const memberSettings = await member.context.request.get(base + '/wp-admin/admin.php?page=igreja-erp-core');
        check(memberSettings.status() === 403, 'Membro não acessa configurações');
        const memberSave = await member.context.request.post(base + '/wp-admin/admin-post.php', {form:{action:'igreja_erp_save_settings',_wpnonce:'invalid','igreja_erp_settings[restrito]':'0'}});
        check(memberSave.status() === 403, 'Membro não desativa a restrição');
        if (process.env.IGREJA_MULTISITE === '1') {
            const other = await member.context.request.get(base + '/igreja-b/', {maxRedirects:0});
            check(other.status() === 403, 'Sessão de uma igreja não acessa outra no navegador: ' + other.status() + ' ' + (other.headers().location || ''));
        }
        const privateHome = await member.context.request.get(base + '/');
        check(privateHome.status() === 200, 'Membro logado acessa o site');
        await member.context.close();

        const anonymous = await browser.newContext();
        const rebranded = await anonymous.newPage();
        await rebranded.goto(base + '/wp-login.php');
        check((await rebranded.locator('.igreja-login-name').textContent()) === 'Comunidade Vida — Teste', 'Login usa nome personalizado');
        const background = await rebranded.locator('h1 a').evaluate(el=>getComputedStyle(el).backgroundImage);
        check(background.includes('/uploads/') && background.includes('&variant=logo'), 'Login usa logo personalizada e mantém parâmetros da URL');
        await anonymous.close();

        const access = page.locator('input[type=checkbox][name="igreja_erp_settings[restrito]"]');
        await access.uncheck();
        await Promise.all([page.waitForURL(/igreja_salvo=1/), page.getByRole('button',{name:'Salvar configurações',exact:true}).click()]);
        check((await http.get(base + '/', {maxRedirects:0})).status() === 200, 'Administrador pode abrir o site');
        await access.check();
        await Promise.all([page.waitForURL(/igreja_salvo=1/), page.getByRole('button',{name:'Salvar configurações',exact:true}).click()]);
        check((await http.get(base + '/', {maxRedirects:0})).status() === 302, 'Administrador pode restringir novamente');
        await page.setViewportSize({width:1440,height:1000});
        check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), 'Layout desktop sem rolagem horizontal');
        await context.close();
        console.log(checks + ' verificações HTTP e de interface passaram.');
    } finally {
        await browser.close();
        await http.dispose();
    }
})().catch(error => {console.error(error);process.exitCode=1;});
