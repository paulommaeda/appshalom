<?php
use IgrejaERP\Core\Database;
use IgrejaERP\Core\Settings;
use IgrejaERP\Core\Transfer;
use IgrejaERP\Core\Membros;
use IgrejaERP\Membros\Accounts;
use IgrejaERP\Membros\MemberTransfer;
if (!defined('WP_CLI') || !WP_CLI || getenv('IGREJA_ERP_TEST_ENV') !== '1') { throw new RuntimeException('Somente CI descartável.'); }
$checks = 0;
$expect = static function ($value, $message) use (&$checks): void { ++$checks; if (!$value) { throw new RuntimeException('FALHA TRANSFER/CONVITE: ' . $message); } };
global $wpdb;
$admin = get_user_by('login', 'tests')->ID;
wp_set_current_user($admin);
$export = Transfer::export_settings();
$packet = json_decode($export, true);
$expect(isset($packet['data']['igreja'], $packet['data']['formulario_membros']), 'Exporta configurações do Core e addon');
$expect(!isset($packet['data']['formulario_membros']['secret_key'], $packet['data']['igreja']['tenant_id']), 'Não exporta segredo ou identidade interna');
$packet['data']['igreja']['nome_curto'] = 'Igreja de Importação';
$plan = Transfer::preview_settings(wp_json_encode($packet));
$expect(!is_wp_error($plan), 'Prévia de configurações válida');
$expect(Settings::get('nome_curto') !== 'Igreja de Importação', 'Prévia não grava');
$result = Transfer::apply_settings($plan);
$expect(!is_wp_error($result) && Settings::get('nome_curto') === 'Igreja de Importação', 'Aplicar configurações');
$expect(is_wp_error(Transfer::apply_settings($plan)), 'Prévia desatualizada não sobrescreve mudanças');
$restore = Transfer::preview_settings($export);
$expect(!is_wp_error(Transfer::apply_settings($restore)), 'Restaurar exportação');
$packet['data']['igreja']['cor_primaria'] = 'javascript:invalid';
$expect(is_wp_error(Transfer::preview_settings(wp_json_encode($packet))), 'Configuração inválida rejeitada antes de gravar');
$packet['data'] = ['unknown_plugin' => []];
$expect(is_wp_error(Transfer::preview_settings(wp_json_encode($packet))), 'Não importa opções arbitrárias');
$expect(is_wp_error(Transfer::preview_settings('{invalid')), 'JSON inválido rejeitado');

$header = ['nome_completo', 'email', 'status', 'familia', 'parentesco'];
$csv = MemberTransfer::csv([$header, ['Importado Silva', 'importado@example.org', 'Congregado', 'Família Importação', 'Titular'], ['Importada Silva', 'importada@example.org', 'Visitante', 'Família Importação', 'Conjuge']]);
$plan = MemberTransfer::preview($csv);
$expect(!is_wp_error($plan) && count($plan['rows']) === 2, 'Prévia CSV válida');
$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::table('membros'));
$expect(!is_wp_error(MemberTransfer::apply($plan)), 'Importar membros');
$expect((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::table('membros')) === $before + 2, 'Inclui dois registros');
$rows = Membros::listar_membros(['busca' => 'importado@example.org'])['items'];
$imported = $rows[0];
$expect(!(int) $imported->aprovado && $imported->wp_user_id === null, 'Importado fica pendente sem conta');
$expect($imported->familia_id && $imported->parentesco === 'Titular', 'Preserva vínculo familiar');
$exported = MemberTransfer::export();
$expect(str_starts_with($exported, "\xEF\xBB\xBF") && str_contains($exported, 'origem_igreja'), 'CSV compatível com Excel e identificação da origem');
$local = MemberTransfer::csv([['origem_igreja', 'codigo', 'nome_completo', 'email'], [Settings::tenant_id(), $imported->id, 'Importado Atualizado', 'importado@example.org']]);
$update = MemberTransfer::preview($local);
$expect(!is_wp_error($update) && $update['rows'][0]['id'] === (int) $imported->id, 'Atualiza por código da própria igreja');
$expect(!is_wp_error(MemberTransfer::apply($update)) && Membros::get_by_id((int) $imported->id)->nome_completo === 'Importado Atualizado', 'Atualização CSV preserva identidade');
$expect(is_wp_error(MemberTransfer::apply($update)), 'Membro modificado após prévia é protegido');
$expect(is_wp_error(MemberTransfer::preview(MemberTransfer::csv([['nome_completo', 'wp_user_id'], ['Intruso', '1']]))), 'Não importa contas nem privilégios');
$expect(is_wp_error(MemberTransfer::preview(MemberTransfer::csv([$header, ['Repetido', 'r@example.org', 'Visitante', '', ''], ['Repetido', 'r@example.org', 'Visitante', '', '']]))), 'Duplicado no CSV rejeitado');
$expect(is_wp_error(MemberTransfer::preview(MemberTransfer::csv([['nome_completo', 'data_nascimento'], ['Inválido', '2020-02-30']]))), 'Data inválida rejeitada');
$expect(str_contains(MemberTransfer::csv([['=formula']]), "'=formula"), 'Exportação neutraliza fórmula de planilha');

$mail = []; $mail_ok = true;
$mock_mail = static function ($pre, $atts) use (&$mail, &$mail_ok) { $mail[] = $atts; return $mail_ok; };
add_filter('pre_wp_mail', $mock_mail, 10, 2);
$one = Membros::salvar_membro(['nome_completo' => 'João Pedro Silvaconvite', 'email' => 'one@example.org']);
$two = Membros::salvar_membro(['nome_completo' => 'João Pedro Silvaconvite', 'email' => 'two@example.org']);
$three = Membros::salvar_membro(['nome_completo' => 'João Pedro Silvaconvite', 'email' => 'three@example.org']);
$a = Accounts::approve($one); $b = Accounts::approve($two); $c = Accounts::approve($three);
$expect(!is_wp_error($a) && !is_wp_error($b) && !is_wp_error($c), 'Aprovar e provisionar três homônimos');
$expect(get_userdata($a['user_id'])->user_login === 'joao.silvaconvite', 'Nome e último sobrenome');
$expect(get_userdata($b['user_id'])->user_login === 'joao.pedro', 'Homônimo usa penúltimo nome');
$expect(get_userdata($c['user_id'])->user_login === 'joao.silvaconvite-2', 'Todas as combinações ocupadas ganham número');
$expect(get_userdata($a['user_id'])->roles === ['erp_membro'], 'Cria apenas perfil de membro');
$expect(count($mail) === 3 && str_contains($mail[0]['message'], Settings::get('nome_curto')) && str_contains($mail[0]['message'], 'Definir minha senha'), 'Convite tem identidade e botão');
$expect(str_contains($mail[0]['message'], Settings::get('cor_primaria')) && str_contains($mail[0]['message'], Settings::logo_url()), 'Convite tem logo e cores');
$expect(!is_wp_error(Accounts::approve($one)) && count($mail) === 3, 'Reaprovar não duplica conta ou convite');
preg_match('/href="([^"]*action=rp[^"]*)"/', $mail[0]['message'], $match);
$reset_url = html_entity_decode($match[1], ENT_QUOTES);
parse_str(wp_parse_url($reset_url, PHP_URL_QUERY), $query);
$expect(!is_wp_error(check_password_reset_key($query['key'], $query['login'])), 'Link nativo de senha válido');
$expect(!str_contains(wp_json_encode(Membros::get_by_id($one)), $query['key']), 'Token não é gravado no membro');
$expect(!is_wp_error(Accounts::approve($one, true)) && count($mail) === 4, 'Reenvio explícito');
$expect(is_wp_error(check_password_reset_key($query['key'], $query['login'])), 'Reenvio invalida link anterior');
$mail_ok = false;
$failed = Membros::salvar_membro(['nome_completo' => 'Convite Falhou', 'email' => 'falhou@example.org']);
$expect(is_wp_error(Accounts::approve($failed)), 'Falha no transporte é exibida');
$expect((int) Membros::get_by_id($failed)->aprovado === 1 && Membros::get_by_id($failed)->convite_status === 'falhou', 'Falha preserva aprovação e registra pendência');
$mail_ok = true;
$expect(!is_wp_error(Accounts::approve($failed)) && Membros::get_by_id($failed)->convite_status === 'enviado', 'Repetir após falha usa a mesma conta');
$no_email = Membros::salvar_membro(['nome_completo' => 'Sem Email']);
$expect(is_wp_error(Accounts::approve($no_email)) && Membros::get_by_id($no_email)->wp_user_id === null, 'Sem e-mail não cria conta');
$existing = Membros::salvar_membro(['nome_completo' => 'Outro com Email', 'email' => 'one@example.org']);
$expect(is_wp_error(Accounts::approve($existing)) && Membros::get_by_id($existing)->wp_user_id === null, 'Não toma conta existente pelo e-mail');
$protected_user = wp_insert_user(['user_login' => 'invite_protected_admin', 'user_email' => 'protected@example.org', 'user_pass' => wp_generate_password(32), 'role' => 'administrator']);
$protected = Membros::salvar_membro(['nome_completo' => 'Admin Protegido', 'wp_user_id' => $protected_user, 'email' => 'protected@example.org']);
$expect(is_wp_error(Accounts::approve($protected, true)), 'Não redefine acesso de administrador');
wp_set_current_user(get_user_by('login', 'erp_brand_member')->ID);
$expect(is_wp_error(Accounts::approve($no_email)) && is_wp_error(MemberTransfer::preview($csv)), 'Membro comum não aprova nem importa');
wp_set_current_user($admin);
remove_filter('pre_wp_mail', $mock_mail);

// Browser-only mail sink; the production ZIP never contains this fixture.
$fixture = <<<'PHP_FIXTURE'
<?php
if (getenv('IGREJA_ERP_TEST_ENV') !== '1' || wp_get_environment_type() !== 'local') { return; }
add_filter('pre_wp_mail', static function ($pre, $atts) {
    if (getenv('IGREJA_INVITE_FILE')) { file_put_contents(getenv('IGREJA_INVITE_FILE'), wp_json_encode($atts)); chmod(getenv('IGREJA_INVITE_FILE'), 0600); }
    return true;
}, 10, 2);
PHP_FIXTURE;
file_put_contents(WPMU_PLUGIN_DIR . '/igreja-invite-ci.php', $fixture);
WP_CLI::success($checks . ' verificações de importação, aprovação e convite passaram.');
