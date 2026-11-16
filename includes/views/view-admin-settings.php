<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;
?>
<div class="wrap igreja-settings">
    <header class="igreja-settings-header">
        <div><p class="igreja-eyebrow">Identidade e acesso</p><h1>Configurações da igreja</h1><p>Um espaço com a identidade da sua comunidade.</p></div>
        <span class="igreja-status"><?php echo Settings::is_restricted() ? 'Acesso restrito' : 'Acesso público'; ?></span>
    </header>
    <?php if (isset($_GET['igreja_salvo']) && is_string($_GET['igreja_salvo']) && $_GET['igreja_salvo'] === '1'): ?>
        <div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>
    <?php endif; ?>
    <?php foreach (is_array($errors) ? $errors : [] as $error): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error['message'] ?? 'Revise os campos informados.'); ?></p></div>
    <?php endforeach; ?>
    <div class="igreja-settings-layout">
        <form class="igreja-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="igreja_erp_save_settings">
            <?php wp_nonce_field('igreja_erp_save_settings'); ?>
            <section class="igreja-card">
                <h2>Identidade da igreja</h2>
                <div class="igreja-fields">
                    <label class="igreja-field">Nome da igreja
                        <input required maxlength="180" name="igreja_erp_settings[nome_igreja]" value="<?php echo esc_attr($settings['nome_igreja']); ?>">
                    </label>
                    <label class="igreja-field">Nome de apresentação
                        <input required maxlength="100" data-preview="name" name="igreja_erp_settings[nome_curto]" value="<?php echo esc_attr($settings['nome_curto']); ?>">
                    </label>
                    <label class="igreja-field igreja-full">Mensagem na tela de entrada
                        <input maxlength="180" data-preview="message" name="igreja_erp_settings[mensagem_login]" value="<?php echo esc_attr($settings['mensagem_login']); ?>">
                    </label>
                </div>
                <h3>Logo</h3>
                <div class="igreja-logo-picker">
                    <div class="igreja-logo-stage"><img id="igreja-logo-preview" src="<?php echo esc_url($logo); ?>" alt="Logo da igreja" <?php echo $logo ? '' : 'hidden'; ?>><span id="igreja-logo-empty" <?php echo $logo ? 'hidden' : ''; ?>>Sua logo</span></div>
                    <div><button type="button" class="button" id="igreja-select-logo">Selecionar imagem</button> <button type="button" class="button-link-delete" id="igreja-remove-logo">Remover</button><p class="description">Escolha uma imagem da biblioteca ou informe uma URL abaixo.</p></div>
                </div>
                <input type="hidden" id="igreja-logo-id" name="igreja_erp_settings[logo_id]" value="<?php echo absint($settings['logo_id']); ?>">
                <label class="igreja-field">URL da logo
                    <input type="url" id="igreja-logo-url" name="igreja_erp_settings[logo_url]" value="<?php echo esc_attr($logo); ?>" placeholder="https://">
                </label>
            </section>
            <section class="igreja-card">
                <h2>Cores</h2>
                <p class="description">As cores serão usadas na entrada e disponibilizadas para os módulos do sistema.</p>
                <div class="igreja-colors">
                <?php foreach (['cor_primaria' => 'Principal', 'cor_destaque' => 'Destaque', 'cor_fundo' => 'Fundo dos cartões', 'cor_texto' => 'Texto'] as $key => $label): ?>
                    <label class="igreja-color"><?php echo esc_html($label); ?><span><input type="color" data-color="<?php echo esc_attr($key); ?>" name="igreja_erp_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(sanitize_hex_color($settings[$key]) ?: '#000A52'); ?>"><output><?php echo esc_html($settings[$key]); ?></output></span></label>
                <?php endforeach; ?>
                </div>
            </section>
            <section class="igreja-card">
                <h2>Contato e localização</h2>
                <div class="igreja-fields">
                <?php foreach ([
                    'email' => ['E-mail', 'email', 150], 'telefone' => ['Telefone / WhatsApp', 'tel', 30],
                    'site' => ['Site da igreja', 'url', 255], 'cep' => ['CEP', 'text', 9],
                    'logradouro' => ['Rua / avenida', 'text', 200], 'numero' => ['Número', 'text', 30],
                    'complemento' => ['Complemento', 'text', 100], 'bairro' => ['Bairro', 'text', 100],
                    'cidade' => ['Cidade', 'text', 100], 'uf' => ['Estado (UF)', 'text', 2], 'pais' => ['País', 'text', 80],
                ] as $key => $field): ?>
                    <label class="igreja-field"><?php echo esc_html($field[0]); ?><input type="<?php echo esc_attr($field[1]); ?>" maxlength="<?php echo (int) $field[2]; ?>" name="igreja_erp_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key]); ?>"></label>
                <?php endforeach; ?>
                </div>
            </section>
            <section class="igreja-card">
                <h2>Acesso ao site</h2>
                <input type="hidden" name="igreja_erp_settings[restrito]" value="0">
                <label class="igreja-access-toggle"><input type="checkbox" name="igreja_erp_settings[restrito]" value="1" <?php checked(Settings::is_restricted()); ?>><span><strong>Permitir acesso somente a usuários logados</strong><small>Páginas, feeds e APIs exigem autenticação. Na rede Multisite, a conta também precisa pertencer a esta igreja.</small></span></label>
                <p class="description igreja-access-note">A proteção é aplicada às respostas do WordPress. Arquivos já públicos por URL direta e caches externos precisam de proteção no servidor.</p>
            </section>
            <div class="igreja-settings-actions"><?php submit_button('Salvar configurações', 'primary', 'submit', false); ?></div>
        </form>
        <aside class="igreja-preview-wrap">
            <p class="igreja-eyebrow">Prévia da identidade</p>
            <div class="igreja-preview" id="igreja-brand-preview">
                <div class="igreja-preview-top"><img id="igreja-preview-logo" src="<?php echo esc_url($logo); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?>><h2 id="igreja-preview-name"><?php echo esc_html($settings['nome_curto']); ?></h2><p id="igreja-preview-message"><?php echo esc_html($settings['mensagem_login']); ?></p></div>
                <div class="igreja-preview-form"><strong>Que bom ter você aqui.</strong><span>Sua conta</span><div class="igreja-fake-input"></div><span>Sua senha</span><div class="igreja-fake-input"></div><div class="igreja-preview-button">Entrar</div><small>Acesso à sua comunidade</small></div>
            </div>
            <p class="igreja-preview-help">Nome, logo e cores podem ser personalizados para cada igreja.</p>
        </aside>
    </div>
</div>
