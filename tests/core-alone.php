<?php
use IgrejaERP\Core\Database;
use IgrejaERP\Core\Settings;

if (!defined('WP_CLI') || !WP_CLI || getenv('IGREJA_ERP_TEST_ENV') !== '1') {
    throw new RuntimeException('Execute apenas no WordPress descartável.');
}
$checks = 0;
$expect = static function ($condition, $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException('FALHA ADDONS: ' . $message); }
};
global $wpdb;
$expect(did_action('igreja_erp_core_loaded') === 1, 'Core ativo');
$expect(!did_action('igreja_erp_membros_loaded'), 'Addon desativado');
$expect(!class_exists('IgrejaERP\Core\Membros') && !class_exists('IgrejaERP\Core\MembersAdmin') && !class_exists('IgrejaERP\Core\Registration'), 'Core não carrega recursos de membros');
$expect(Settings::is_restricted(), 'Privacidade preservada');
$expect((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE nome_completo = %s', Database::table('membros'), 'Visitante Navegador')) === 1, 'Cadastro preservado no banco');
$saved = get_option('igreja_erp_registration');
$expect(is_array($saved) && !$saved['fields']['profissao'], 'Opções preservadas');
$expect(class_exists('IgrejaERP\Membros\Updater'), 'Atualizador permanece disponível com addon instalado e inativo');
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/addons/igreja-erp-membros/update.json'), true);
$fixture['version'] = '999.0.0';
$fixture['download_url'] = 'https://github.com/paulommaeda/appshalom/releases/download/membros-v999.0.0/igreja-erp-membros-999.0.0.zip';
$mock = static function ($pre, $args, $url) use ($fixture) {
    if (!str_ends_with($url, '/main/addons/igreja-erp-membros/update.json')) { return $pre; }
    return ['headers' => [], 'body' => wp_json_encode($fixture), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
add_filter('pre_http_request', $mock, 10, 3);
delete_transient('igreja_erp_membros_update_metadata');
$update = apply_filters('update_plugins_github.com', false, [], 'igreja-erp-membros/igreja-erp-membros.php', []);
$expect(is_array($update) && $update['version'] === '999.0.0' && $update['slug'] === 'igreja-erp-membros', 'Atualização nativa independente do addon inativo');
$expect($update['package'] === $fixture['download_url'], 'Pacote do addon usa release própria');
remove_filter('pre_http_request', $mock);
delete_transient('igreja_erp_membros_update_metadata');
WP_CLI::success($checks . ' verificações de Core independente passaram.');
