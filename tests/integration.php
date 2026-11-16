<?php
/** Integration suite for the disposable CI WordPress/MySQL instance only. */
use IgrejaERP\Core\Database;
use IgrejaERP\Core\RBAC;
use IgrejaERP\Core\Updater;

if (!defined('WP_CLI') || !WP_CLI || getenv('IGREJA_ERP_TEST_ENV') !== '1') {
    throw new RuntimeException('Os testes exigem WordPress CLI e banco descartável.');
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$erp_checks = 0;
function erp_expect($condition, string $message): void
{
    global $erp_checks;
    ++$erp_checks;
    if (!$condition) {
        throw new RuntimeException('FALHA: ' . $message);
    }
}
function erp_insert(string $table, array $data): int
{
    global $wpdb;
    erp_expect($wpdb->insert(Database::table($table), $data) !== false, 'Inserir fixture em ' . $table);
    return (int) $wpdb->insert_id;
}
function erp_user(string $login, string $role): int
{
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32), 'role' => $role]);
    erp_expect(!is_wp_error($id), 'Criar conta ' . $role);
    return (int) $id;
}
global $wpdb;
erp_expect(did_action('igreja_erp_core_loaded') === 1, 'Hook de bootstrap dispara uma vez');
erp_expect(get_option('igreja_erp_core_db_version') === '1.0.0', 'Versão do esquema');
erp_expect(count(Database::schemas()) === 5, 'Cinco tabelas Core');
foreach (Database::schemas() as $name => $sql) {
    $table = Database::table($name);
    erp_expect(str_starts_with($table, $wpdb->prefix . 'erp_'), 'Prefixo da tabela ' . $name);
    erp_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table, 'Tabela existe ' . $name);
    erp_expect(dbDelta($sql) === [], 'dbDelta idempotente ' . $name);
}
$indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', Database::table('membros')), ARRAY_A);
$uniques = array_column(array_filter($indexes, static fn ($row) => (int) $row['Non_unique'] === 0), 'Key_name');
erp_expect(in_array('cpf', $uniques, true) && in_array('wp_user_id', $uniques, true), 'CPF e conta únicos');
$family = erp_insert('familias', ['nome_familia' => 'Família de Teste']);
$member_a = erp_insert('membros', ['nome_completo' => 'Pessoa A', 'familia_id' => $family]);
$member_b = erp_insert('membros', ['nome_completo' => 'Pessoa B', 'familia_id' => $family]);
erp_expect($member_a !== $member_b, 'Vários membros com CPF e conta NULL');
erp_insert('membros', ['nome_completo' => 'Pessoa com CPF', 'cpf' => '12345678901']);
$previous = $wpdb->suppress_errors(true);
erp_expect($wpdb->insert(Database::table('membros'), ['nome_completo' => 'CPF repetido', 'cpf' => '12345678901']) === false, 'CPF duplicado bloqueado');
$wpdb->suppress_errors($previous);
erp_expect(Database::install() === true, 'Reinstalação idempotente');
erp_expect((int) $wpdb->get_var($wpdb->prepare('SELECT familia_id FROM %i WHERE id = %d', Database::table('membros'), $member_a)) === $family, 'Reinstalação preserva vínculos');

// Simulate a failed ALTER, then repair it without losing existing rows.
$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP COLUMN lgpd_ip', Database::table('membros')));
update_option('igreja_erp_core_db_version', '0.9.0');
$broken = static fn ($queries) => array_map(static fn ($sql) => str_replace('lgpd_ip varchar(45)', 'lgpd_ip invalid_type', $sql), $queries);
add_filter('dbdelta_queries', $broken);
$previous = $wpdb->suppress_errors(true);
$failed = Database::install();
$wpdb->suppress_errors($previous);
remove_filter('dbdelta_queries', $broken);
erp_expect(is_wp_error($failed), 'Erro real de SQL reportado');
erp_expect(get_option('igreja_erp_core_db_version') === '0.9.0', 'Falha não avança versão');
erp_expect(get_option('igreja_erp_core_migration_lock', false) === false, 'Trava liberada após erro');
erp_expect(Database::maybe_upgrade() === false, 'Intervalo após falha evita tentativas contínuas');
delete_option('igreja_erp_core_migration_retry_after');
erp_expect(Database::maybe_upgrade(), 'Migração restaura coluna');
erp_expect(in_array('lgpd_ip', $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', Database::table('membros'))), true), 'Coluna LGPD restaurada');
erp_expect(get_option('igreja_erp_core_db_version') === '1.0.0', 'Versão avança após sucesso');
add_option('igreja_erp_core_migration_lock', time());
erp_expect(is_wp_error(Database::install()), 'Concorrência bloqueada');
update_option('igreja_erp_core_migration_lock', time() - 301);
erp_expect(Database::install() === true, 'Trava expirada recuperada');

foreach (RBAC::role_definitions() as $slug => $definition) {
    erp_expect(get_role($slug) !== null, 'Perfil existe ' . $slug);
}
erp_expect(count(RBAC::role_definitions()) === 7, 'Sete perfis ERP');
get_role('erp_membro')->add_cap('addon_custom_cap');
get_role('erp_membro')->add_cap('erp_gerenciar_membros');
RBAC::install_roles();
erp_expect(get_role('erp_membro')->has_cap('addon_custom_cap'), 'Capability do add-on preservada');
erp_expect(!get_role('erp_membro')->has_cap('erp_gerenciar_membros'), 'Perfil membro não retém elevação');
$admin = erp_user('erp_test_admin', 'administrator');
$pastor = erp_user('erp_test_pastor', 'erp_admin_pastor');
$secretary = erp_user('erp_test_secretary', 'erp_secretaria');
$financial = erp_user('erp_test_financial', 'erp_administrativo');
$leader_a = erp_user('erp_test_leader_a', 'erp_lider_depto');
$leader_b = erp_user('erp_test_leader_b', 'erp_lider_depto');
$common = erp_user('erp_test_member', 'erp_membro');
$subscriber = erp_user('erp_test_subscriber', 'subscriber');
$wpdb->update(Database::table('membros'), ['wp_user_id' => $leader_a], ['id' => $member_a]);
$wpdb->update(Database::table('membros'), ['wp_user_id' => $leader_b], ['id' => $member_b]);
$own = erp_insert('membros', ['nome_completo' => 'Membro comum', 'wp_user_id' => $common]);
$a = erp_insert('departamentos', ['nome' => 'Louvor', 'slug' => 'louvor', 'lider_membro_id' => $member_a]);
$b = erp_insert('departamentos', ['nome' => 'Mídia', 'slug' => 'midia', 'vice_lider_id' => $member_b]);
$inactive = erp_insert('departamentos', ['nome' => 'Inativo', 'slug' => 'inativo', 'lider_membro_id' => $member_a, 'ativo' => 0]);
erp_expect(!RBAC::usuario_pode(0, 'acessar_portal'), 'Visitante negado');
erp_expect(!RBAC::usuario_pode($subscriber, 'acessar_portal'), 'Assinante sem ERP negado');
erp_expect(!RBAC::usuario_pode($admin, 'acao_inexistente'), 'Ação desconhecida negada');
erp_expect(RBAC::usuario_pode($admin, 'gerenciar_configuracoes'), 'Administrador WP gerencia Core');
erp_expect(RBAC::usuario_pode($pastor, 'gerenciar_membros'), 'Pastor gerencia membros');
erp_expect(!user_can($pastor, 'manage_options'), 'Pastor não vira admin WP');
erp_expect(RBAC::usuario_pode($secretary, 'gerenciar_familias'), 'Secretaria gerencia famílias');
erp_expect(!RBAC::usuario_pode($secretary, 'gerenciar_financeiro'), 'Secretaria não gerencia financeiro');
erp_expect(RBAC::usuario_pode($financial, 'gerenciar_centros_custo'), 'Administrativo gerencia centros');
erp_expect(!RBAC::usuario_pode($financial, 'gerenciar_membros'), 'Administrativo não gerencia membros');
erp_expect(RBAC::usuario_pode($leader_a, 'gerenciar_equipe', $a), 'Líder gerencia sua equipe');
erp_expect(!RBAC::usuario_pode($leader_a, 'gerenciar_equipe', $b), 'Outro departamento negado');
erp_expect(RBAC::usuario_pode($leader_b, 'gerenciar_equipe', $b), 'Vice-líder autorizado');
erp_expect(!RBAC::usuario_pode($leader_a, 'gerenciar_equipe', $inactive), 'Departamento inativo negado');
erp_expect(!RBAC::usuario_pode($leader_a, 'gerenciar_equipe'), 'Contexto obrigatório para líder');
erp_expect(!RBAC::usuario_pode($leader_a, 'gerenciar_equipe', -$a), 'Contexto negativo negado');
erp_expect(!RBAC::usuario_pode($common, 'visualizar_membros'), 'Membro não lista dados gerais');
erp_expect(RBAC::usuario_pode($common, 'editar_proprio_membro', $own), 'Próprio perfil autorizado');
erp_expect(!RBAC::usuario_pode($common, 'editar_proprio_membro', $member_a), 'Outra pessoa negada');
erp_expect(!RBAC::usuario_pode($common, 'visualizar_equipe', $a), 'Lista básica não libera equipe');
$assignment = erp_insert('departamento_equipe', ['departamento_id' => $b, 'membro_id' => $member_a, 'funcao' => 'Coordenação', 'papel' => 'Coordenador']);
erp_expect(RBAC::usuario_pode($leader_a, 'gerenciar_equipe', $b), 'Coordenador ativo autorizado');
$wpdb->update(Database::table('departamento_equipe'), ['ativo' => 0], ['id' => $assignment]);
erp_expect(!RBAC::usuario_pode($leader_a, 'gerenciar_equipe', $b), 'Vínculo inativo negado');
erp_insert('departamento_equipe', ['departamento_id' => $a, 'membro_id' => $member_a, 'funcao' => 'Violão']);
erp_insert('departamento_equipe', ['departamento_id' => $a, 'membro_id' => $member_a, 'funcao' => 'Vocal']);
$previous = $wpdb->suppress_errors(true);
erp_expect($wpdb->insert(Database::table('departamento_equipe'), ['departamento_id' => $a, 'membro_id' => $member_a, 'funcao' => 'Vocal']) === false, 'Permite funções diferentes mas não a mesma função duplicada');
erp_expect($wpdb->insert(Database::table('membros'), ['nome_completo' => 'Conta duplicada', 'wp_user_id' => $leader_a]) === false, 'Conta WP não pertence a dois membros');
$wpdb->suppress_errors($previous);

wp_set_current_user($leader_a);
$request = new WP_REST_Request('POST', '/igreja-erp/v1/departamentos/' . $b);
$request->set_url_params(['id' => $b]);
$request->set_body_params(['id' => $a, 'departamento_id' => $a]);
$request->set_query_params(['id' => $a]);
$denied = RBAC::rest_permission_check($request, 'gerenciar_equipe');
erp_expect(is_wp_error($denied) && $denied->get_error_data()['status'] === 403, 'Corpo/query não substituem rota');
$request->set_url_params(['id' => $a]);
erp_expect(RBAC::rest_permission_check($request, 'gerenciar_equipe') === true, 'Rota da própria equipe autorizada');
$request->set_header('X-WP-Nonce', 'invalido');
erp_expect(is_wp_error(RBAC::rest_permission_check($request, 'gerenciar_equipe')), 'Nonce inválido negado');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
erp_expect(RBAC::rest_permission_check($request, 'gerenciar_equipe') === true, 'Nonce válido aceito');
$request->set_url_params(['id' => '-1']);
erp_expect(RBAC::rest_permission_check($request, 'gerenciar_equipe')->get_error_data()['status'] === 400, 'ID inválido retorna 400');
$request->set_url_params(['id' => '9223372036854775808']);
erp_expect(RBAC::rest_permission_check($request, 'gerenciar_equipe')->get_error_data()['status'] === 400, 'Overflow rejeitado');
wp_set_current_user(0);
erp_expect(RBAC::rest_permission_check($request)->get_error_data()['status'] === 401, 'Sem autenticação retorna 401');
wp_set_current_user($leader_a);
$GLOBALS['wp_rest_auth_cookie'] = true;
unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
rest_cookie_check_errors(null);
erp_expect(get_current_user_id() === 0, 'REST nativo remove cookie sem nonce');
wp_set_current_user($admin);

$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/update.json'), true);
$fixture['version'] = '0.1.1';
$fixture['download_url'] = 'https://github.com/paulommaeda/appshalom/releases/download/v0.1.1/igreja-erp-core-0.1.1.zip';
$calls = 0;
$status = 200;
$http = static function ($pre, $http_args, $url) use (&$fixture, &$calls, &$status) {
    if ($url === 'https://raw.githubusercontent.com/paulommaeda/appshalom/main/update.json') {
        ++$calls;
        return ['headers' => [], 'body' => wp_json_encode($fixture), 'response' => ['code' => $status, 'message' => 'Fixture'], 'cookies' => []];
    }
    if (str_contains($url, 'api.wordpress.org/plugins/update-check/')) {
        return ['headers' => [], 'body' => '{"plugins":{},"no_update":{},"translations":[]}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }
    return $pre;
};
add_filter('pre_http_request', $http, 10, 3);
delete_transient('igreja_erp_core_update_metadata');
erp_expect(Updater::metadata()['version'] === '0.1.1', 'Metadados válidos');
erp_expect(Updater::metadata()['version'] === '0.1.1' && $calls === 1, 'Cache evita repetição');
erp_expect(Updater::update_response(false, [], 'outro/outro.php', []) === false, 'Outro plugin não é afetado');
$plugin = plugin_basename(IGREJA_ERP_CORE_FILE);
delete_site_transient('update_plugins');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
erp_expect(isset($updates->response[$plugin]) && $updates->response[$plugin]->new_version === '0.1.1', 'WordPress anuncia nova versão');
$info = Updater::plugin_information(false, 'plugin_information', (object) ['slug' => 'igreja-erp-core']);
erp_expect(is_object($info) && $info->version === '0.1.1', 'Informações da versão');
$fixture['download_url'] = 'https://example.org/plugin.zip';
delete_transient('igreja_erp_core_update_metadata');
erp_expect(Updater::metadata() === null, 'Pacote externo rejeitado');
$fixture['version'] = ['invalid'];
delete_transient('igreja_erp_core_update_metadata');
erp_expect(Updater::metadata() === null, 'Tipo inválido rejeitado');
$status = 500;
$before = $calls;
delete_transient('igreja_erp_core_update_metadata');
erp_expect(Updater::metadata() === null && Updater::metadata() === null, 'Falha remota tolerada');
erp_expect($calls === $before + 1, 'Cache também em falhas');
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/update.json'), true);
$status = 200;
delete_transient('igreja_erp_core_update_metadata');
delete_site_transient('update_plugins');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
erp_expect(!isset($updates->response[$plugin]), 'Mesma versão não oferece atualização');

// Replace an older installed version through the real WordPress upgrader.
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
$original = file_get_contents(IGREJA_ERP_CORE_FILE);
erp_expect(file_put_contents(IGREJA_ERP_CORE_FILE, str_replace(' * Version: 0.1.0', ' * Version: 0.0.9', $original)) !== false, 'Preparar versão anterior');
wp_clean_plugins_cache(true);
delete_transient('igreja_erp_core_update_metadata');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
erp_expect(isset($updates->response[$plugin]), 'Versão anterior detecta atualização');
$download = static function ($reply, $package) {
    $temp = wp_tempnam('igreja-erp-test.zip');
    copy(dirname(__DIR__) . '/dist/igreja-erp-core-0.1.0.zip', $temp);
    return $temp;
};
add_filter('upgrader_pre_download', $download, 10, 2);
$skin = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader($skin);
$result = $upgrader->upgrade($plugin);
remove_filter('upgrader_pre_download', $download);
erp_expect($result === true, 'Plugin_Upgrader instala ZIP: ' . wp_json_encode($skin->get_errors()->get_error_messages()));
wp_clean_plugins_cache(true);
$installed = get_plugin_data(IGREJA_ERP_CORE_FILE, false, false);
erp_expect($installed['Version'] === '0.1.0', 'Arquivo atualizado para versão nova');
erp_expect(is_file(WP_PLUGIN_DIR . '/igreja-erp-core/igreja-erp-core.php'), 'Pasta canônica preservada');
erp_expect(is_plugin_active($plugin), 'Ativação preservada');
erp_expect((int) $wpdb->get_var($wpdb->prepare('SELECT familia_id FROM %i WHERE id = %d', Database::table('membros'), $member_a)) === $family, 'Atualização preserva dados');
remove_filter('pre_http_request', $http);
delete_transient('igreja_erp_core_update_metadata');
WP_CLI::success($erp_checks . ' verificações de integração passaram.');
