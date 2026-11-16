<?php
use IgrejaERP\Core\Settings;
use IgrejaERP\Core\Access;
use IgrejaERP\Core\Database;

if (!defined('WP_CLI') || !WP_CLI || getenv('IGREJA_ERP_TEST_ENV') !== '1') {
    throw new RuntimeException('Execute apenas no banco descartável de teste.');
}
require_once ABSPATH . 'wp-admin/includes/template.php';
$GLOBALS['igreja_settings_checks'] = 0;
function igreja_check($condition, string $message): void
{
    ++$GLOBALS['igreja_settings_checks'];
    if (!$condition) {
        throw new RuntimeException('FALHA SETTINGS: ' . $message);
    }
}
$defaults = Settings::defaults();
igreja_check(Settings::get('nome_curto') === 'CCShalom Curitiba', 'Preset inicial');
igreja_check(Settings::get('cor_primaria') === '#000A52' && Settings::get('cor_destaque') === '#001BE1', 'Paleta da marca');
igreja_check(Settings::is_restricted(), 'Restrito por padrão');
igreja_check((bool) preg_match('/^[a-f0-9-]{36}$/D', Settings::tenant_id()), 'Identificador estável da igreja');
$tenant = Settings::tenant_id();
$custom = Settings::sanitize(['nome_curto' => 'Comunidade de Teste', 'cor_primaria' => '#123456']);
update_option(Settings::OPTION, $custom);
delete_option('igreja_erp_settings_version');
Settings::initialize();
igreja_check(Settings::get('nome_curto') === 'Comunidade de Teste', 'Inicialização preserva personalização');
igreja_check(Settings::tenant_id() === $tenant, 'Identificador preservado');
$bad = Settings::sanitize([
    'nome_curto' => '<b>Nome seguro</b>',
    'cor_primaria' => '#fff;background:url(javascript:alert(1))',
    'cor_destaque' => ['#ffffff'],
    'logo_url' => 'javascript:alert(1)',
    'logo_id' => '-7',
    'email' => 'email invalido',
    'restrito' => [],
    'tenant_id' => 'outro-tenant',
]);
igreja_check($bad['nome_curto'] === 'Nome seguro', 'Nome sanitizado');
igreja_check($bad['cor_primaria'] === '#123456', 'CSS malicioso rejeitado');
igreja_check($bad['cor_destaque'] === '#001BE1', 'Cor de tipo inválido rejeitada');
igreja_check($bad['logo_url'] === $custom['logo_url'], 'URL de script rejeitada');
igreja_check($bad['logo_id'] === $custom['logo_id'], 'ID negativo rejeitado');
igreja_check($bad['email'] === $custom['email'], 'E-mail inválido rejeitado');
igreja_check($bad['restrito'] === true, 'Valor malformado não abre o site');
igreja_check(!isset($bad['tenant_id']) && Settings::tenant_id() === $tenant, 'Tenant não vem da requisição');
igreja_check(Settings::sanitize('invalid') === Settings::all(), 'Formato inválido preserva configuração');
$post_id = wp_insert_post(['post_title' => 'Não é uma imagem', 'post_status' => 'draft']);
igreja_check(Settings::sanitize(['logo_id' => $post_id])['logo_id'] === $custom['logo_id'], 'Conteúdo comum não vira logo');
igreja_check(Settings::contrast('#fff') === '#000000' && Settings::contrast('#000') === '#FFFFFF', 'Contraste dos botões');
igreja_check(str_contains(Settings::css_variables(), '--igreja-primary:#123456'), 'Variáveis de identidade');
igreja_check(get_role('erp_admin_pastor')->has_cap('upload_files'), 'Pastor pode enviar logo');
igreja_check(!get_role('erp_membro')->has_cap('erp_gerenciar_configuracoes'), 'Membro não configura igreja');

wp_set_current_user(0);
igreja_check(!Access::can_access(), 'Anônimo sem acesso');
$denied = Access::rest_gate(null);
igreja_check(is_wp_error($denied) && $denied->get_error_data()['status'] === 401, 'REST privado retorna 401');
$existing = new WP_Error('existing', 'Existing error', ['status' => 403]);
igreja_check(Access::rest_gate($existing) === $existing, 'Erro de autenticação anterior preservado');
igreja_check(has_filter('rest_authentication_errors', [Access::class, 'rest_gate']) > 100, 'Gate executa depois da validação de nonce');
igreja_check(apply_filters('wp_sitemaps_enabled', true) === false, 'Sitemap desabilitado');
igreja_check(get_option('blog_public') === 0, 'Indexação desabilitada enquanto restrito');
igreja_check(get_option('users_can_register') === 0, 'Cadastro público desabilitado');
igreja_check(str_contains(Access::headers([])['Cache-Control'], 'no-store'), 'Sem cache de conteúdo privado');

$admin = get_user_by('login', 'tests')->ID;
wp_set_current_user($admin);
$GLOBALS['wp_rest_auth_cookie'] = true;
unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
$no_nonce = apply_filters('rest_authentication_errors', null);
igreja_check(is_wp_error($no_nonce) && $no_nonce->get_error_data()['status'] === 401, 'Cookie sem nonce não fura restrição REST');
wp_set_current_user($admin);
$GLOBALS['wp_rest_auth_cookie'] = true;
$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
igreja_check(!is_wp_error(apply_filters('rest_authentication_errors', null)), 'REST autenticado com nonce funciona');
unset($_SERVER['HTTP_X_WP_NONCE']);

$open = Settings::sanitize(['restrito' => '0']);
update_option(Settings::OPTION, $open);
wp_set_current_user(0);
igreja_check(Access::rest_gate(null) === null, 'Acesso público configurável');
igreja_check(Access::headers(['Existing' => 'yes']) === ['Existing' => 'yes'], 'Sem alterar cabeçalhos no modo público');
update_option(Settings::OPTION, $custom);
wp_set_current_user($admin);
foreach (['erp_brand_pastor' => 'erp_admin_pastor', 'erp_brand_member' => 'erp_membro'] as $login => $role) {
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => 'IgErp-CI-123!x', 'role' => $role]);
    igreja_check(!is_wp_error($id), 'Conta de teste ' . $login);
}

if (is_multisite()) {
    $a_id = get_current_blog_id();
    $a_member = get_user_by('login', 'erp_brand_member')->ID;
    $preset_b = static fn ($preset, $site_id) => array_replace($preset, ['nome_curto' => 'Igreja B', 'nome_igreja' => 'Comunidade B', 'cor_primaria' => '#224433']);
    add_filter('igreja_erp_initial_settings', $preset_b, 10, 2);
    $b_id = wpmu_create_blog(get_network()->domain, '/igreja-b/', 'Igreja B', $admin, ['public' => 0], get_current_network_id());
    remove_filter('igreja_erp_initial_settings', $preset_b);
    igreja_check(!is_wp_error($b_id), 'Criar igreja B');
    switch_to_blog($b_id);
    igreja_check(Settings::get('nome_curto') === 'Igreja B', 'Provisionamento permite identidade por igreja');
    igreja_check(Settings::tenant_id() !== $tenant && Settings::tenant_id() !== '', 'Igrejas têm IDs próprios');
    igreja_check(Settings::is_restricted(), 'Novo site nasce restrito');
    global $wpdb;
    igreja_check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Database::table('membros')))) === Database::table('membros'), 'Novo site recebe tabelas próprias');
    wp_set_current_user($a_member);
    igreja_check(!Access::can_access(), 'Usuário de A não acessa B');
    $cross = Access::rest_gate(null);
    igreja_check(is_wp_error($cross) && $cross->get_error_data()['status'] === 403, 'REST nega conta de outra igreja');
    wp_set_current_user($admin);
    igreja_check(Access::can_access(), 'Administrador da rede preserva acesso');
    update_option(Settings::OPTION, Settings::sanitize(['nome_curto' => 'Identidade exclusiva B']));
    restore_current_blog();
    igreja_check(Settings::get('nome_curto') === 'Comunidade de Teste', 'Alterar B não modifica A');
    igreja_check(Settings::tenant_id() === $tenant && get_current_blog_id() === $a_id, 'Contexto restaurado');
}
update_option(Settings::OPTION, $defaults);
Settings::initialize();
igreja_check(Settings::get('nome_curto') === 'CCShalom Curitiba' && Settings::is_restricted(), 'Fixture restaurada para testes HTTP');
WP_CLI::success($GLOBALS['igreja_settings_checks'] . ' verificações de configurações e isolamento passaram.');
