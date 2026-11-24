<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Current-site services shared with add-ons. Reads and writes enforce Core caps. */
final class Membros
{
    public const STATUS = ['Membro Ativo', 'Congregado', 'Visitante', 'Criança/Adolescente', 'Em Discipulado', 'Disciplina', 'Transferido', 'Inativo'];
    public const PARENTESCOS = ['Titular', 'Conjuge', 'Filho(a)', 'Outro'];
    public const ESTADOS_CIVIS = ['Solteiro(a)', 'Casado(a)', 'Divorciado(a)', 'Viúvo(a)', 'União estável'];
    public const RECEPCAO = ['Batismo', 'Aclamação', 'Carta de Transferência', 'Infância'];

    private static function raw_member(int $id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', Database::table('membros'), $id));
    }

    public static function get_by_id(int $id): ?object
    {
        if (!RBAC::usuario_pode(get_current_user_id(), 'visualizar_proprio_membro', $id)) {
            return null;
        }
        return self::raw_member($id);
    }

    public static function get_by_wp_user_id(int $user_id): ?object
    {
        global $wpdb;
        if ($user_id <= 0 || ($user_id !== get_current_user_id() && !current_user_can('erp_visualizar_membros'))) {
            return null;
        }
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE wp_user_id = %d', Database::table('membros'), $user_id));
        return $id ? self::get_by_id($id) : null;
    }

    public static function get_current_membro(): ?object
    {
        return self::get_by_wp_user_id(get_current_user_id());
    }

    public static function get_familia(int $id): array
    {
        global $wpdb;
        if (!current_user_can('erp_visualizar_familias')) {
            return [];
        }
        $family = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', Database::table('familias'), $id));
        if (!$family) {
            return [];
        }
        return ['familia' => $family, 'membros' => current_user_can('erp_visualizar_membros') ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE familia_id = %d ORDER BY nome_completo, id', Database::table('membros'), $id)) : []];
    }

    public static function listar_membros(array $filters = []): array
    {
        global $wpdb;
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 0];
        if (!current_user_can('erp_visualizar_membros')) {
            return $empty;
        }
        $where = ['1=1'];
        $args = [Database::table('membros')];
        $search = is_string($filters['busca'] ?? null) ? mb_substr(trim($filters['busca']), 0, 180) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(nome_completo LIKE %s OR nome_social_apelido LIKE %s OR email LIKE %s OR celular_whatsapp LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $where[] = 'status = %s';
            $args[] = $filters['status'];
        }
        if (isset($filters['aprovado']) && in_array($filters['aprovado'], ['0', '1'], true)) {
            $where[] = 'aprovado = %d';
            $args[] = (int) $filters['aprovado'];
        }
        if (!empty($filters['familia_id'])) {
            $where[] = 'familia_id = %d';
            $args[] = absint($filters['familia_id']);
        }
        if (!empty($filters['departamento_id'])) {
            $where[] = 'id IN (SELECT membro_id FROM %i WHERE departamento_id = %d AND ativo = 1)';
            $args[] = Database::table('departamento_equipe');
            $args[] = absint($filters['departamento_id']);
        }
        $sql = ' FROM %i WHERE ' . implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*)' . $sql, $args));
        $per_page = max(1, min(100, absint($filters['per_page'] ?? 20)));
        $pages = (int) ceil($total / $per_page);
        $page = min(max(1, absint($filters['page'] ?? 1)), max(1, $pages));
        $items = $wpdb->get_results($wpdb->prepare('SELECT *' . $sql . ' ORDER BY nome_completo, id LIMIT %d OFFSET %d', array_merge($args, [$per_page, ($page - 1) * $per_page])));
        return compact('items', 'total', 'page', 'per_page', 'pages');
    }

    public static function listar_familias(string $search = '', int $page = 1): array
    {
        global $wpdb;
        if (!current_user_can('erp_visualizar_familias')) {
            return ['items' => [], 'total' => 0, 'pages' => 0, 'page' => 1];
        }
        $like = '%' . $wpdb->esc_like(mb_substr($search, 0, 150)) . '%';
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE nome_familia LIKE %s', Database::table('familias'), $like));
        $pages = (int) ceil($total / 20);
        $page = min(max(1, $page), max(1, $pages));
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE nome_familia LIKE %s ORDER BY nome_familia, id LIMIT 20 OFFSET %d', Database::table('familias'), $like, ($page - 1) * 20));
        return compact('items', 'total', 'pages', 'page');
    }

    private static function error(string $message): \WP_Error
    {
        return new \WP_Error('igreja_erp_dados_invalidos', $message);
    }

    /** Strict positive database ID, or zero for a new/unlinked record. */
    public static function parse_id(mixed $value): int|\WP_Error
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) || strlen((string) $value) > strlen((string) PHP_INT_MAX) || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) {
            return self::error('Identificador inválido.');
        }
        return (int) $value;
    }

    private static function texts(array $input, array $limits): array|\WP_Error
    {
        $out = [];
        foreach ($limits as $key => $limit) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_string($input[$key])) {
                return self::error('Revise o formato dos campos de texto.');
            }
            $value = $key === 'observacoes' ? sanitize_textarea_field($input[$key]) : sanitize_text_field($input[$key]);
            if (mb_strlen($value) > $limit) {
                return self::error('Um dos campos ultrapassa o tamanho permitido.');
            }
            $out[$key] = $value === '' ? null : $value;
        }
        return $out;
    }

    private static function date(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date && $date >= '1900-01-01' && $date <= current_time('Y-m-d');
    }

    private static function valid_cpf(string $cpf): bool
    {
        if (!preg_match('/^[0-9]{11}$/D', $cpf) || preg_match('/^(\d)\1{10}$/D', $cpf)) {
            return false;
        }
        for ($length = 9; $length < 11; $length++) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }
            $digit = ($sum * 10) % 11;
            if (($digit === 10 ? 0 : $digit) !== (int) $cpf[$length]) {
                return false;
            }
        }
        return true;
    }

    private static function member_data(array $input, ?object $old): array|\WP_Error
    {
        $out = self::texts($input, ['nome_completo' => 180, 'nome_social_apelido' => 100, 'sexo' => 1, 'estado_civil' => 30, 'rg' => 25, 'email' => 150, 'celular_whatsapp' => 20, 'profissao' => 120, 'escolaridade' => 60, 'parentesco' => 30, 'status' => 40, 'forma_recepcao' => 50, 'igreja_origem' => 150]);
        if (is_wp_error($out)) {
            return $out;
        }
        $name = array_key_exists('nome_completo', $out) ? $out['nome_completo'] : ($old->nome_completo ?? '');
        if (trim((string) $name) === '') {
            return self::error('Informe o nome completo.');
        }
        if (!empty($out['email']) && !is_email($out['email'])) {
            return self::error('Informe um e-mail válido.');
        }
        if (!empty($out['celular_whatsapp'])) {
            $digits = preg_replace('/\D/', '', $out['celular_whatsapp']);
            if (!preg_match('/^\+?[0-9 ()-]{8,20}$/D', $out['celular_whatsapp']) || strlen($digits) < 10 || strlen($digits) > 15) {
                return self::error('Informe um telefone com DDD.');
            }
        }
        foreach (['sexo' => ['M', 'F'], 'estado_civil' => self::ESTADOS_CIVIS, 'parentesco' => self::PARENTESCOS, 'status' => self::STATUS, 'forma_recepcao' => self::RECEPCAO] as $key => $allowed) {
            if (isset($out[$key]) && !in_array($out[$key], $allowed, true)) {
                return self::error('Selecione uma opção válida para ' . str_replace('_', ' ', $key) . '.');
            }
        }
        if (array_key_exists('status', $out) && $out['status'] === null) {
            return self::error('Selecione a situação do membro.');
        }
        foreach (['data_nascimento', 'data_conversao', 'data_batismo'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_string($input[$key]) || ($input[$key] !== '' && !self::date($input[$key]))) {
                return self::error('Informe datas válidas, entre 1900 e hoje.');
            }
            $out[$key] = $input[$key] === '' ? null : $input[$key];
        }
        if (array_key_exists('cpf', $input)) {
            if (!is_string($input['cpf']) || !preg_match('/^[0-9. -]*$/D', $input['cpf'])) {
                return self::error('Informe um CPF válido.');
            }
            $cpf = preg_replace('/\D/', '', $input['cpf']);
            if ($cpf !== '' && !self::valid_cpf($cpf)) {
                return self::error('Informe um CPF válido.');
            }
            $out['cpf'] = $cpf === '' ? null : $cpf;
        }
        foreach (['familia_id', 'wp_user_id'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $id = self::parse_id($input[$key] === '' ? '0' : $input[$key]);
            if (is_wp_error($id)) {
                return $id;
            }
            if ($key === 'familia_id' && !current_user_can('erp_gerenciar_familias')) {
                return self::error('Você não tem permissão para vincular famílias.');
            }
            if ($id && $key === 'familia_id') {
                global $wpdb;
                if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', Database::table('familias'), $id))) {
                    return self::error('A família selecionada não pertence a esta igreja.');
                }
                if (!current_user_can('erp_gerenciar_familias')) {
                    return self::error('Você não tem permissão para vincular famílias.');
                }
            }
            if ($id && $key === 'wp_user_id' && (!get_userdata($id) || !is_user_member_of_blog($id, get_current_blog_id()))) {
                return self::error('A conta precisa estar vinculada a esta igreja.');
            }
            $out[$key] = $id ?: null;
        }
        $family = array_key_exists('familia_id', $out) ? $out['familia_id'] : ($old->familia_id ?? null);
        if (!$family) {
            $out['parentesco'] = null;
        }
        return $out;
    }

    public static function validar_importacao(array $input, int $id = 0): array|\WP_Error
    {
        if (!current_user_can('erp_gerenciar_membros')) { return self::error('Acesso não autorizado.'); }
        return self::member_data($input, $id ? self::raw_member($id) : null);
    }

    public static function salvar_membro(array $input, bool $emit = true): int|\WP_Error
    {
        if (!current_user_can('erp_gerenciar_membros')) {
            return new \WP_Error('igreja_erp_sem_permissao', 'Você não tem permissão para cadastrar membros.');
        }
        $id = self::parse_id($input['id'] ?? 0);
        if (is_wp_error($id)) {
            return $id;
        }
        $old = $id ? self::raw_member($id) : null;
        if ($id && !$old) {
            return self::error('Cadastro não encontrado nesta igreja.');
        }
        $data = self::member_data($input, $old);
        return is_wp_error($data) ? $data : self::persist_member($id, $data, $emit);
    }

    private static function persist_member(int $id, array $data, bool $emit = true): int|\WP_Error
    {
        global $wpdb;
        // UNIQUE constraints also cover concurrent requests; absent CPF/user remain NULL.
        foreach (['cpf', 'wp_user_id'] as $key) {
            if (!empty($data[$key]) && $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE ' . $key . ' = %s AND id <> %d', Database::table('membros'), $data[$key], $id))) {
                return self::error('O CPF ou a conta informada já está vinculado a outro cadastro.');
            }
        }
        $result = $id ? $wpdb->update(Database::table('membros'), $data, ['id' => $id]) : $wpdb->insert(Database::table('membros'), $data);
        if ($result === false) {
            return self::error('Não foi possível salvar. Verifique se já existe um cadastro com esse CPF ou conta.');
        }
        $id = $id ?: (int) $wpdb->insert_id;
        if ($emit) { do_action('igreja_erp_membro_salvo', $id, get_current_blog_id()); }
        return $id;
    }

    /** Public intake only accepts enabled fields; it never updates existing records. */
    public static function cadastrar_visitante(array $input, string $token): int|\WP_Error
    {
        $data = self::member_data(Registration::public_input($input), null);
        if (is_wp_error($data)) {
            return $data;
        }
        if ((Registration::field_enabled('email') || Registration::field_enabled('celular_whatsapp')) && empty($data['email']) && empty($data['celular_whatsapp'])) {
            return self::error('Informe um e-mail ou WhatsApp para contato.');
        }
        if (($input['lgpd_aceite'] ?? null) !== '1') {
            return self::error('Leia e aceite a informação sobre o uso dos dados.');
        }
        $verified = Registration::authorize_submission($token);
        if (is_wp_error($verified)) {
            return $verified;
        }
        $data['status'] = 'Visitante';
        $data['lgpd_aceite'] = 1;
        $data['lgpd_data_aceite'] = current_time('mysql');
        $data['lgpd_ip'] = Registration::remote_ip();
        return self::persist_member(0, $data);
    }

    public static function salvar_familia(array $input, bool $emit = true): int|\WP_Error
    {
        global $wpdb;
        if (!current_user_can('erp_gerenciar_familias')) {
            return new \WP_Error('igreja_erp_sem_permissao', 'Você não tem permissão para cadastrar famílias.');
        }
        $id = self::parse_id($input['id'] ?? 0);
        if (is_wp_error($id)) {
            return $id;
        }
        if ($id && !$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', Database::table('familias'), $id))) {
            return self::error('Família não encontrada nesta igreja.');
        }
        $data = self::texts($input, ['nome_familia' => 150, 'cep' => 10, 'logradouro' => 200, 'numero' => 30, 'complemento' => 100, 'bairro' => 100, 'cidade' => 100, 'uf' => 2, 'telefone_fixo' => 20, 'observacoes' => 5000]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data['nome_familia'])) {
            return self::error('Informe o nome da família.');
        }
        if (!empty($data['cep'])) {
            if (!preg_match('/^[0-9]{5}-?[0-9]{3}$/D', $data['cep'])) {
                return self::error('Informe um CEP válido.');
            }
            $data['cep'] = preg_replace('/\D/', '', $data['cep']);
        }
        if (!empty($data['uf'])) {
            $data['uf'] = strtoupper($data['uf']);
            if (!in_array($data['uf'], ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'], true)) {
                return self::error('Informe uma UF válida.');
            }
        }
        $result = $id ? $wpdb->update(Database::table('familias'), $data, ['id' => $id]) : $wpdb->insert(Database::table('familias'), $data);
        if ($result === false) {
            return self::error('Não foi possível salvar a família.');
        }
        $id = $id ?: (int) $wpdb->insert_id;
        if ($emit) { do_action('igreja_erp_familia_salva', $id, get_current_blog_id()); }
        return $id;
    }
}
