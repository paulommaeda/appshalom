<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="<?php echo esc_attr(get_option('blog_charset', 'UTF-8')); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="strict-origin-when-cross-origin">
<title>Cadastro — <?php echo esc_html((string) Settings::get('nome_curto')); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(IGREJA_ERP_MEMBROS_URL . 'assets/css/members.css?ver=' . IGREJA_ERP_MEMBROS_VERSION); ?>">
<style><?php echo Settings::css_variables(); // Sanitized hex colors only. ?></style>
<?php if (Registration::ready() && Registration::uses_turnstile() && !$success): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<?php if (Registration::ready() && !$success): ?><script src="<?php echo esc_url(IGREJA_ERP_MEMBROS_URL . 'assets/js/address.js?ver=' . IGREJA_ERP_MEMBROS_VERSION); ?>" defer></script><?php endif; ?>
</head><body class="igreja-public"><main class="igreja-registration">
<header class="igreja-public-heading"><?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="Logo da igreja"><?php endif; ?><p><?php echo esc_html((string) Settings::get('nome_curto')); ?></p><h1>Vamos caminhar juntos</h1><p>Preencha seus dados para que nossa equipe possa conhecer você e entrar em contato.</p></header>
<?php if (!Registration::ready()): ?>
    <section class="igreja-panel" role="status"><h2>Cadastro temporariamente indisponível</h2><p>Entre em contato com a igreja para realizar seu cadastro.</p></section>
<?php elseif ($success): ?>
    <section class="igreja-panel" role="status"><h2>Cadastro recebido!</h2><p>Nossa equipe irá conferir as informações. Obrigado por caminhar conosco.</p><a href="<?php echo esc_url(Registration::url()); ?>">Enviar outro cadastro</a></section>
<?php else: ?>
<form method="post" action="<?php echo esc_url(Registration::url()); ?>" class="igreja-panel">
    <?php if ($error): ?><div class="igreja-error" role="alert"><?php echo esc_html($error); ?></div><?php endif; ?>
    <?php wp_nonce_field('igreja_erp_public_registration', '_wpnonce', false); ?>
    <div class="igreja-trap" aria-hidden="true"><label>Não preencha este campo<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <p class="igreja-hint">* Campo obrigatório.<?php if (Registration::field_enabled('email') || Registration::field_enabled('celular_whatsapp')): ?> Informe pelo menos um contato entre os campos exibidos.<?php endif; ?></p>
    <?php foreach (['personal', 'address'] as $section):
        $shown = array_filter(Registration::fields(), static fn ($field, $key) => ($field['section'] ?? 'personal') === $section && Registration::field_enabled($key), ARRAY_FILTER_USE_BOTH);
        if (!$shown) { continue; }
    ?>
    <section <?php echo $section === 'address' ? 'data-igreja-address' : ''; ?>>
    <?php if ($section === 'address'): ?><h2>Endereço</h2><?php endif; ?>
    <div class="igreja-grid">
    <?php foreach ($shown as $key => $field): ?>
        <label class="igreja-field"><?php echo esc_html($field['label'] . (!empty($field['locked']) ? ' *' : '')); ?>
        <?php if ($field['type'] === 'select'): ?>
            <select name="membro[<?php echo esc_attr($key); ?>]"><option value="">Prefiro não informar</option><?php foreach ($field['options'] as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected(is_string($values[$key] ?? null) ? $values[$key] : '', $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        <?php else: ?>
            <input name="membro[<?php echo esc_attr($key); ?>]" type="<?php echo esc_attr($field['type']); ?>" maxlength="<?php echo (int) $field['max']; ?>" autocomplete="<?php echo esc_attr($field['autocomplete']); ?>" value="<?php echo esc_attr(is_string($values[$key] ?? null) ? $values[$key] : ''); ?>" <?php echo isset(Membros::ADDRESS[$key]) ? 'data-address-field="' . esc_attr($key) . '"' : ''; ?> <?php echo $key === 'cep' ? 'inputmode="numeric"' : ''; ?> <?php echo !empty($field['locked']) ? 'required' : ''; ?> <?php echo $field['type'] === 'date' ? 'min="1900-01-01" max="' . esc_attr(current_time('Y-m-d')) . '"' : ''; ?>>
        <?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <?php if ($section === 'address' && Registration::field_enabled('cep')): ?><p><button type="button" class="igreja-cep-button" data-address-lookup>Consultar CEP</button></p><p class="igreja-hint" data-address-status role="status" aria-live="polite">Digite o CEP para preencher o endereço. Número e complemento devem ser informados por você.</p><?php endif; ?>
    </section>
    <?php endforeach; ?>
    <div class="igreja-consent"><p>Os dados informados serão usados por <?php echo esc_html((string) Settings::get('nome_igreja')); ?> para manter seu cadastro e entrar em contato sobre a vida da comunidade. Você pode solicitar atualização ou exclusão entrando em contato com a igreja.</p><label><input type="checkbox" name="membro[lgpd_aceite]" value="1" required> Li as informações acima e concordo com o uso dos meus dados para essas finalidades. *</label></div>
    <?php if (Registration::uses_turnstile()): ?><div class="cf-turnstile" data-sitekey="<?php echo esc_attr($settings['site_key']); ?>" data-action="<?php echo esc_attr(Registration::ACTION); ?>" data-language="pt-br" data-size="flexible"></div><?php endif; ?>
    <button type="submit" class="igreja-submit">Enviar cadastro</button>
    <p class="igreja-hint">Este formulário não cria uma conta de acesso ao sistema.</p>
</form>
<?php endif; ?>
<footer class="igreja-public-footer"><?php echo esc_html((string) Settings::get('nome_igreja')); ?></footer>
</main></body></html>
