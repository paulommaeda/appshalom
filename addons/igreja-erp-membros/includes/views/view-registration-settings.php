<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;
?>
<div class="wrap igreja-members"><h1>Cadastro público</h1>
<?php if (isset($_GET['salvo'])): ?><div class="notice notice-success"><p>Configurações salvas.</p></div><?php endif; ?>
<p>Compartilhe este link: <a href="<?php echo esc_url(self::url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(self::url()); ?></a></p>
<p>Os envios criam visitantes, sem conta de acesso. Confira os dados na lista de membros e defina a situação e a família.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="igreja_erp_save_registration"><?php wp_nonce_field('igreja_erp_save_registration'); ?>
    <section class="igreja-panel"><h2>Formulário</h2>
        <label><input type="checkbox" name="enabled" value="1" <?php checked((bool) $settings['enabled']); ?>> Habilitar formulário público</label>
    </section>
    <section class="igreja-panel"><h2>Campos do formulário</h2>
        <p>Marque os campos que deseja exibir no cadastro público. A ficha administrativa continua completa e os dados já cadastrados são preservados.</p>
        <div class="igreja-grid">
        <?php foreach (self::fields() as $key => $field): ?>
            <label class="igreja-field-toggle"><input type="checkbox" name="fields[<?php echo esc_attr($key); ?>]" value="1" <?php checked($settings['fields'][$key]); ?> <?php disabled(!empty($field['locked'])); ?>> <?php echo esc_html($field['label']); ?><?php echo !empty($field['locked']) ? ' (obrigatório)' : ''; ?></label>
        <?php endforeach; ?>
        <label class="igreja-field-toggle"><input type="checkbox" checked disabled> Aceite do uso dos dados (obrigatório)</label>
        </div>
        <p class="igreja-hint">Nome e aceite permanecem obrigatórios. Se e-mail ou WhatsApp estiverem habilitados, a pessoa deverá preencher ao menos um deles. Campos desabilitados também são ignorados no servidor.</p>
    </section>
    <section class="igreja-panel"><h2>Proteção do formulário</h2>
        <p><label><input type="checkbox" name="captcha_enabled" value="1" <?php checked((bool) $settings['captcha_enabled']); ?>> Habilitar Cloudflare Turnstile</label></p>
        <p>O formulário pode ser exibido e enviado sem Cloudflare. Nesse caso, permanecem o limite de tentativas, a validação do formulário e o campo de proteção contra robôs.</p>
        <p><label>Turnstile — Site key<br><input class="regular-text" name="site_key" maxlength="100" value="<?php echo esc_attr($settings['site_key']); ?>" autocomplete="off"></label></p>
        <p><label>Turnstile — Secret key<br><input type="password" class="regular-text" name="secret_key" maxlength="200" value="" autocomplete="new-password" placeholder="<?php echo $settings['secret_key'] ? 'Chave configurada; deixe vazio para manter' : 'Informe a chave secreta'; ?>"></label></p>
        <p><label><input type="checkbox" name="remove_secret" value="1"> Remover a chave secreta e desativar o Turnstile</label></p>
        <p>Para usar o CAPTCHA, crie um widget Turnstile no modo Managed para o domínio <strong><?php echo esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST)); ?></strong>, preencha as duas chaves e habilite a opção acima. A chave secreta não aparece no formulário público.</p>
        <p role="status"><strong><?php echo $settings['captcha_enabled'] ? 'Turnstile habilitado: verificação obrigatória nos envios.' : 'Turnstile desativado: formulário disponível com proteção básica.'; ?></strong></p>
    </section>
    <?php submit_button('Salvar cadastro público'); ?>
</form></div>
