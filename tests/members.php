<?php
use IgrejaERP\Core\Membros;
use IgrejaERP\Core\Registration;
use IgrejaERP\Core\Database;
use IgrejaERP\Core\Access;

if (!defined('WP_CLI') || !WP_CLI || getenv('IGREJA_ERP_TEST_ENV') !== '1') {
    throw new RuntimeException('Execute apenas no WordPress descartável de teste.');
}
$checks = 0;
$expect = static function ($value, $message) use (&$checks): void {
    ++$checks;
    if (!$value) {
        throw new RuntimeException('FALHA MEMBROS: ' . $message);
    }
};
global $wpdb;
$admin = (int) get_user_by('login', 'tests')->ID;
wp_set_current_user(0);
$expect(is_wp_error(Membros::salvar_membro(['nome_completo' => 'Intruso'])), 'Anônimo não gerencia membros');
$expect(is_wp_error(Membros::salvar_familia(['nome_familia' => 'Intruso'])), 'Anônimo não gerencia famílias');
$expect(Membros::listar_membros()['total'] === 0 && Membros::get_familia(1) === [], 'Anônimo não lê cadastros');
$expect(Registration::ready() && !Registration::uses_turnstile(), 'Sem chaves, formulário disponível sem CAPTCHA');
$expect(Registration::authorize_submission('') === true, 'Envio permitido sem Cloudflare quando desativado');
wp_set_current_user($admin);
$family = Membros::salvar_familia(['nome_familia' => 'Família Integração', 'cep' => '81750-000', 'cidade' => 'Curitiba', 'uf' => 'pr']);
$expect(is_int($family), 'Criar família');
$expect(Membros::get_familia($family)['familia']->uf === 'PR', 'Normalizar UF');
$expect(is_wp_error(Membros::salvar_familia(['nome_familia' => 'Inválida', 'uf' => 'XX'])), 'UF inválida');
$expect(is_wp_error(Membros::salvar_familia(['nome_familia' => 'Inválida', 'cep' => '123'])), 'CEP inválido');
$member = Membros::salvar_membro(['nome_completo' => 'Membro Integração', 'familia_id' => $family, 'parentesco' => 'Titular', 'status' => 'Congregado', 'email' => 'integra@example.org', 'data_nascimento' => '1990-02-28', 'cpf' => '529.982.247-25']);
$expect(is_int($member), 'Criar membro: ' . (is_wp_error($member) ? $member->get_error_message() : 'ok'));
$expect(Membros::get_by_id($member)->cpf === '52998224725', 'Normalizar CPF');
$expect(count(Membros::get_familia($family)['membros']) === 1, 'Vínculo familiar');
$expect(Membros::salvar_membro(['id' => $member, 'email' => 'atualizado@example.org', 'status' => 'Membro Ativo']) === $member, 'Editar cadastro');
$expect(Membros::get_by_id($member)->nome_completo === 'Membro Integração', 'Update parcial preserva dados');
$expect(Membros::listar_membros(['busca' => 'Membro Integração', 'status' => 'Membro Ativo', 'familia_id' => $family])['total'] === 1, 'Busca e filtros combinados');
$expect(Membros::listar_membros(['busca' => '%_'])['total'] === 0, 'LIKE escapa curingas');
$expect(Membros::listar_membros(['busca' => "' OR 1=1 --"])['total'] === 0, 'Busca não injeta SQL');
$expect(Membros::listar_membros(['per_page' => 99999])['per_page'] === 100, 'Limitar paginação');
foreach ([['id' => -1], ['id' => []], ['id' => '99999999999999999999'], ['data_nascimento' => '2020-02-30'], ['data_batismo' => '2999-01-01'], ['status' => 'Administrador'], ['sexo' => ['M']], ['email' => 'invalid'], ['cpf' => '11111111111'], ['familia_id' => 99999999], ['wp_user_id' => 99999999]] as $bad) {
    $expect(is_wp_error(Membros::salvar_membro(array_replace(['nome_completo' => 'Inválido'], $bad))), 'Rejeitar ' . json_encode($bad));
}
$expect(is_wp_error(Membros::salvar_membro(['nome_completo' => 'CPF duplicado', 'cpf' => '52998224725'])), 'CPF duplicado não altera original');
$expect(is_wp_error(Membros::salvar_membro(['id' => $member, 'nome_completo' => ''])), 'Edição não pode apagar nome obrigatório');
$expect(is_wp_error(Membros::salvar_membro(['nome_completo' => 'Contato inválido', 'celular_whatsapp' => '------------'])), 'Contato exige números e DDD');
$expect(is_int(Membros::salvar_membro(['nome_completo' => 'Contato válido', 'celular_whatsapp' => '(41) 99999-1234'])), 'WhatsApp formatado com DDD aceito');
$expect(is_wp_error(Membros::parse_id('9223372036854775808')), 'Overflow de ID rejeitado sem arredondamento');
$expect(is_int(Membros::salvar_membro(['nome_completo' => 'Sem CPF 1', 'cpf' => ''])) && is_int(Membros::salvar_membro(['nome_completo' => 'Sem CPF 2', 'cpf' => ''])), 'Vários CPFs ausentes permanecem NULL');
$expect(Membros::salvar_membro(['id' => $member, 'familia_id' => 0]) === $member && Membros::get_by_id($member)->parentesco === null, 'Desvincular família limpa parentesco');

foreach (['erp_brand_pastor', 'erp_members_secretaria', 'erp_brand_member'] as $login) {
    if (!get_user_by('login', $login)) {
        $created = wp_insert_user(['user_login' => $login, 'user_pass' => 'IgErp-CI-123!x', 'role' => 'erp_secretaria']);
        $expect(!is_wp_error($created), 'Criar secretaria de teste');
    }
}
foreach (['erp_brand_pastor', 'erp_members_secretaria'] as $login) {
    wp_set_current_user(get_user_by('login', $login)->ID);
    $expect(is_int(Membros::salvar_membro(['nome_completo' => 'Criado por ' . $login])), 'Pastor/secretaria cadastra');
    $expect(is_int(Membros::salvar_familia(['nome_familia' => 'Criada por ' . $login])), 'Pastor/secretaria cadastra família');
}
wp_set_current_user(get_user_by('login', 'erp_brand_member')->ID);
$expect(is_wp_error(Membros::salvar_membro(['id' => $member, 'nome_completo' => 'Hack'])), 'Membro comum não altera cadastro');
$expect(Membros::listar_membros()['total'] === 0 && Membros::get_by_id($member) === null, 'Membro não lê outro cadastro');
wp_set_current_user($admin);
Membros::salvar_membro(['id' => $member, 'wp_user_id' => get_user_by('login', 'erp_brand_member')->ID]);
wp_set_current_user(get_user_by('login', 'erp_brand_member')->ID);
$expect(Membros::get_current_membro()->id === (string) $member || (int) Membros::get_current_membro()->id === $member, 'Próprio perfil disponível ao addon');

// Field configuration is enforced on the server, independently of the HTML.
$expect(did_action('igreja_erp_membros_loaded') === 1, 'Addon independente carregado');
$expect(!file_exists(IGREJA_ERP_CORE_DIR . 'includes/class-membros.php'), 'Core não contém implementação do addon');
$expect(defined('IGREJA_ERP_MEMBROS_VERSION'), 'Versão própria do addon');
$normalized = Registration::normalize_fields(['nome_completo' => '0', 'profissao' => [], 'email' => '1', 'status' => '1']);
$expect($normalized['nome_completo'] && $normalized['email'] && !$normalized['profissao'] && !isset($normalized['status']), 'Campos fixos e whitelist de configuração');
update_option(Registration::OPTION, ['fields' => $normalized, 'captcha_enabled' => false], false);
wp_set_current_user(0);
$hidden = Membros::cadastrar_visitante(['nome_completo' => 'Campos Ocultos', 'email' => 'campos@example.org', 'celular_whatsapp' => ['malformed'], 'profissao' => 'NAO SALVAR', 'data_nascimento' => 'invalid', 'lgpd_aceite' => '1'], '');
$expect(is_int($hidden), 'Campos desativados ignorados antes da validação');
wp_set_current_user($admin);
$hidden_record = Membros::get_by_id($hidden);
$expect($hidden_record->profissao === null && $hidden_record->celular_whatsapp === null && $hidden_record->data_nascimento === null, 'Servidor não persiste campos ocultos');
$expect(Membros::get_by_id($member)->email === 'atualizado@example.org', 'Ocultar campo não apaga dados existentes');
update_option(Registration::OPTION, ['fields' => [], 'captcha_enabled' => false], false);
wp_set_current_user(0);
$only_name = Membros::cadastrar_visitante(['nome_completo' => 'Somente Nome', 'lgpd_aceite' => '1'], '');
$expect(is_int($only_name), 'Contatos podem ser desabilitados sem bloquear o cadastro');
$expect(is_wp_error(Membros::cadastrar_visitante(['lgpd_aceite' => '1'], '')), 'Nome obrigatório não pode ser removido');
delete_option(Registration::OPTION);
$expect(Registration::field_enabled('email') && Registration::field_enabled('profissao'), 'Configuração legada preserva todos os campos originais');

$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
$expect(Registration::remote_ip() === '192.0.2.1', 'Não confiar em IP encaminhado pelo cliente');
update_option(Registration::OPTION, ['enabled' => true, 'site_key' => 'fixture-site', 'secret_key' => 'fixture-secret'], false);
$mock_result = ['success' => true, 'hostname' => wp_parse_url(home_url('/'), PHP_URL_HOST), 'action' => Registration::ACTION];
$transport = static function ($pre, $args, $url) use (&$mock_result) {
    if ($url !== 'https://challenges.cloudflare.com/turnstile/v0/siteverify') {
        return $pre;
    }
    return ['headers' => [], 'body' => wp_json_encode($mock_result), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
add_filter('pre_http_request', $transport, 10, 3);
wp_set_current_user(0);
$expect(is_wp_error(Membros::cadastrar_visitante(['nome_completo' => 'Sem aceite', 'email' => 'a@example.org'], 'no-consent')), 'Exigir aceite');
$expect(is_wp_error(Membros::cadastrar_visitante(['nome_completo' => 'Sem contato', 'lgpd_aceite' => '1'], 'no-contact')), 'Exigir contato');
$mock_result['success'] = false;
$expect(is_wp_error(Registration::authorize_submission('invalid-token')), 'Rejeitar CAPTCHA inválido/expirado');
$mock_result['success'] = true;
$mock_result['hostname'] = 'other.example.org';
$expect(is_wp_error(Registration::authorize_submission('wrong-host')), 'Rejeitar hostname de outro site');
$mock_result['hostname'] = wp_parse_url(home_url('/'), PHP_URL_HOST);
$mock_result['action'] = 'other_form';
$expect(is_wp_error(Registration::authorize_submission('wrong-action')), 'Rejeitar action de outro formulário');
$mock_result['action'] = Registration::ACTION;
$public = Membros::cadastrar_visitante(['id' => $member, 'wp_user_id' => $admin, 'familia_id' => $family, 'status' => 'Membro Ativo', 'nome_completo' => 'Visitante Público Integração', 'email' => 'public@example.org', 'lgpd_aceite' => '1'], 'valid-unique-token');
$expect(is_int($public) && $public !== $member, 'Público só insere, nunca atualiza IDs fornecidos');
$expect(is_wp_error(Registration::authorize_submission('valid-unique-token')), 'Repetição local bloqueada mesmo com Siteverify positivo');
$_SERVER['REMOTE_ADDR'] = '192.0.2.88';
for ($attempt = 0; $attempt < 20; $attempt++) {
    $expect(Registration::authorize_submission('rate-' . $attempt) === true, 'Aceitar verificação dentro do limite ' . $attempt);
}
$expect(is_wp_error(Registration::authorize_submission('rate-limit-exceeded')), 'Limite de tentativas aplicado no servidor');
add_option('igreja_erp_guard_expired_fixture', time() - 60, '', false);
Registration::cleanup();
$expect(get_option('igreja_erp_guard_expired_fixture', false) === false, 'Limpar guardas expiradas');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$expect(is_wp_error(Access::rest_gate(null)), 'Formulário não libera REST');
wp_set_current_user($admin);
$record = Membros::get_by_id($public);
$expect($record->status === 'Visitante' && $record->wp_user_id === null && $record->familia_id === null, 'Não aceitar privilégios/família/conta do visitante');
$expect((int) $record->lgpd_aceite === 1 && $record->lgpd_data_aceite && $record->lgpd_ip === '192.0.2.1', 'Registrar aceite no servidor');
$mock_result = [];
$expect(is_wp_error(Registration::authorize_submission('malformed-json')), 'Resposta sem sucesso rejeitada');
remove_filter('pre_http_request', $transport);
$offline = static fn ($pre, $args, $url) => str_contains($url, 'siteverify') ? new WP_Error('offline', 'Offline') : $pre;
add_filter('pre_http_request', $offline, 10, 3);
$expect(is_wp_error(Registration::authorize_submission('network-down')), 'Indisponibilidade Cloudflare não libera cadastro');
remove_filter('pre_http_request', $offline);
$_GET = ['igreja_cadastro' => '1'];
$_SERVER['REQUEST_URI'] = wp_parse_url(home_url('/'), PHP_URL_PATH) . '?igreja_cadastro=1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$expect(Registration::is_public_request(), 'Rota pública exata reconhecida');
foreach (['s', 'feed', 'author', 'rest_route', 'page_id'] as $key) {
    $_GET[$key] = '1';
    $expect(!Registration::is_public_request(), 'Flag pública não abre ' . $key);
    unset($_GET[$key]);
}
$_SERVER['REQUEST_URI'] = '/outra-pagina/?igreja_cadastro=1';
$expect(!Registration::is_public_request(), 'Flag pública não abre outra página');
$_GET = [];

if (is_multisite()) {
    $a = get_current_blog_id();
    $b = get_site_by_path(get_network()->domain, '/igreja-b/')->blog_id;
    switch_to_blog($b);
    $expect(Registration::ready() && !Registration::uses_turnstile(), 'Igreja sem chaves mantém formulário disponível');
    $expect(Membros::listar_membros(['busca' => 'Visitante Público Integração'])['total'] === 0, 'Cadastros isolados por igreja');
    $expect(Membros::get_familia($family) === [], 'Família da outra igreja invisível');
    restore_current_blog();
    $expect(get_current_blog_id() === $a && Registration::ready(), 'Contexto e configuração restaurados');
}

// HTTP/browser fixtures stay OUTSIDE the plugin package and run only in local CI.
$dir = WPMU_PLUGIN_DIR;
wp_mkdir_p($dir);
$fixture = <<<'PHP'
<?php
if (getenv('IGREJA_ERP_TEST_ENV') !== '1' || wp_get_environment_type() !== 'local') { return; }
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($url !== 'https://challenges.cloudflare.com/turnstile/v0/siteverify') { return $pre; }
    $ok = ($args['body']['response'] ?? '') === 'browser-valid-token';
    return ['headers' => [], 'body' => wp_json_encode(['success' => $ok, 'hostname' => wp_parse_url(home_url('/'), PHP_URL_HOST), 'action' => 'member_registration']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
}, 10, 3);
PHP;
file_put_contents($dir . '/igreja-members-ci.php', $fixture);
WP_CLI::success($checks . ' verificações de membros e cadastro público passaram.');
