<?php
namespace IgrejaERP\Membros;
use IgrejaERP\Core\Transfer;
use IgrejaERP\Core\Settings;
use IgrejaERP\Core\Registration;
use IgrejaERP\Core\Database;
use IgrejaERP\Core\Membros;
defined('ABSPATH') || exit;

final class MemberTransfer
{
    public const FIELDS = ['nome_completo', 'nome_social_apelido', 'data_nascimento', 'sexo', 'estado_civil', 'cpf', 'rg', 'email', 'celular_whatsapp', 'profissao', 'escolaridade', 'status', 'data_conversao', 'data_batismo', 'forma_recepcao', 'igreja_origem', 'parentesco'];

    public static function register(): void
    {
        Transfer::register_provider('members', ['name' => 'Membros', 'cap' => 'erp_gerenciar_membros', 'extension' => 'csv', 'export' => [self::class, 'export'], 'preview' => [self::class, 'preview'], 'apply' => [self::class, 'apply']]);
        Transfer::register_settings('formulario_membros', Registration::OPTION, static fn () => array_intersect_key(Registration::settings(), array_flip(['enabled', 'fields'])), static function ($input) {
            if (!is_array($input) || array_diff(array_keys($input), ['enabled', 'fields']) || !is_bool($input['enabled'] ?? null) || !is_array($input['fields'] ?? null) || array_diff(array_keys($input['fields']), array_keys(Registration::fields()))) {
                return new \WP_Error('transfer_fields', 'Configuração dos campos do formulário inválida.');
            }
            $settings = Registration::settings();
            $settings['enabled'] = $input['enabled'];
            $settings['fields'] = Registration::normalize_fields($input['fields']);
            return $settings;
        });
    }

    public static function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $safe = array_map(static fn ($v) => preg_match('/^[=+@-]/', (string) $v) ? "'" . $v : (string) $v, $row);
            fputcsv($stream, $safe, ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    public static function export(): string|\WP_Error
    {
        global $wpdb;
        if (!current_user_can('erp_gerenciar_membros') || !current_user_can('erp_visualizar_familias')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        $rows = [array_merge(['origem_igreja', 'codigo'], self::FIELDS, ['familia'])];
        $offset = 0;
        do {
            $members = $wpdb->get_results($wpdb->prepare('SELECT m.*, f.nome_familia FROM %i m LEFT JOIN %i f ON f.id = m.familia_id ORDER BY m.id LIMIT 500 OFFSET %d', Database::table('membros'), Database::table('familias'), $offset));
            foreach ($members as $member) {
                $row = [Settings::tenant_id(), $member->id];
                foreach (self::FIELDS as $field) { $row[] = $member->$field ?? ''; }
                $row[] = $member->nome_familia ?? '';
                $rows[] = $row;
            }
            $offset += 500;
        } while (count($members) === 500);
        return self::csv($rows);
    }

    public static function preview(string $content): array|\WP_Error
    {
        global $wpdb;
        if (!current_user_can('erp_gerenciar_membros') || !current_user_can('erp_gerenciar_familias')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        if (strlen($content) > Transfer::MAX_BYTES || !preg_match('//u', $content)) { return new \WP_Error('transfer_encoding', 'Use CSV UTF-8 de até 2 MB.'); }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $first = strtok($content, "\r\n") ?: '';
        $delimiter = count(str_getcsv($first, ';', '"', '')) >= count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content); rewind($stream);
        $headers = fgetcsv($stream, 0, $delimiter, '"', '');
        $allowed = array_merge(['origem_igreja', 'codigo'], self::FIELDS, ['familia']);
        if (!$headers || !in_array('nome_completo', $headers, true) || count(array_unique($headers)) !== count($headers) || array_diff($headers, $allowed)) {
            fclose($stream);
            return new \WP_Error('transfer_header', 'Cabeçalho inválido. Use o modelo exportado em Membros; nome_completo é obrigatório.');
        }
        $rows = []; $seen = []; $errors = []; $line = 1; $new = 0; $updated = 0;
        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            ++$line;
            if ($values === [null]) { continue; }
            if (count($rows) >= 1000 || $line > 1100) { $errors[] = 'Use até 1.000 membros por arquivo.'; break; }
            if (count($headers) !== count($values)) { $errors[] = 'Linha ' . $line . ': quantidade de colunas incorreta.'; continue; }
            $values = array_map(static fn ($v) => preg_match("/^'[=+@-]/", (string) $v) ? substr($v, 1) : $v, $values);
            $raw = array_combine($headers, $values);
            $id = 0;
            if (($raw['origem_igreja'] ?? '') === Settings::tenant_id() && !empty($raw['codigo'])) {
                $parsed = Membros::parse_id($raw['codigo']);
                if (is_wp_error($parsed) || !Membros::get_by_id($parsed)) { $errors[] = 'Linha ' . $line . ': código local não encontrado.'; continue; }
                $id = $parsed;
            }
            $cpf = preg_replace('/\D/', '', (string) ($raw['cpf'] ?? ''));
            if ($cpf !== '') {
                $match = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE REPLACE(REPLACE(cpf, %s, %s), %s, %s) = %s', Database::table('membros'), '.', '', '-', '', $cpf));
                if ($id && $match && $match !== $id) { $errors[] = 'Linha ' . $line . ': CPF pertence a outro cadastro.'; continue; }
                $id = $id ?: $match;
            }
            if (!$id && $cpf === '' && !empty($raw['email'])) {
                $matches = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE email = %s AND nome_completo = %s AND COALESCE(data_nascimento, %s) = %s LIMIT 2', Database::table('membros'), $raw['email'], $raw['nome_completo'], '', $raw['data_nascimento'] ?? ''));
                if (count($matches) > 1) { $errors[] = 'Linha ' . $line . ': cadastro ambíguo. Informe CPF ou código exportado desta igreja.'; continue; }
                if ($matches) { $id = (int) $matches[0]; }
            }
            $input = array_intersect_key($raw, array_flip(self::FIELDS));
            if (!$id && empty($input['status'])) { $input['status'] = 'Visitante'; }
            $data = Membros::validar_importacao($input, $id);
            if (is_wp_error($data)) { $errors[] = 'Linha ' . $line . ': ' . $data->get_error_message(); continue; }
            $family = sanitize_text_field($raw['familia'] ?? '');
            if (mb_strlen($family) > 150) { $errors[] = 'Linha ' . $line . ': nome de família muito longo.'; continue; }
            // Parentesco is validated independently, then applied after the family resolves.
            $parentesco = $raw['parentesco'] ?? '';
            if ($parentesco !== '' && !in_array($parentesco, Membros::PARENTESCOS, true)) { $errors[] = 'Linha ' . $line . ': parentesco inválido.'; continue; }
            $data['parentesco'] = $parentesco ?: null;
            $identity = $id ? 'id:' . $id : ($cpf ? 'cpf:' . $cpf : 'new:' . hash('sha256', wp_json_encode([$data['nome_completo'] ?? '', $data['data_nascimento'] ?? '', $data['email'] ?? '', $data['celular_whatsapp'] ?? ''])));
            if (isset($seen[$identity])) { $errors[] = 'Linha ' . $line . ': membro repetido no arquivo.'; continue; }
            $seen[$identity] = true;
            $old = $id ? Membros::get_by_id($id) : null;
            if ($old && $old->wp_user_id && isset($data['email']) && strcasecmp((string) $data['email'], (string) $old->email) !== 0) { $errors[] = 'Linha ' . $line . ': altere o e-mail da conta vinculada antes de importar uma mudança de contato.'; continue; }
            $rows[] = ['id' => $id, 'data' => $data, 'family' => $family, 'family_present' => array_key_exists('familia', $raw), 'hash' => $old ? hash('sha256', wp_json_encode($old)) : '', 'label' => ($id ? 'Atualizar: ' : 'Novo, pendente: ') . ($data['nome_completo'] ?? $old->nome_completo)];
            $id ? ++$updated : ++$new;
        }
        fclose($stream);
        if ($errors) { return new \WP_Error('transfer_rows', implode(' ', array_slice($errors, 0, 12))); }
        if (!$rows) { return new \WP_Error('transfer_empty', 'O CSV não contém membros.'); }
        return ['rows' => $rows, 'summary' => $new . ' novo(s) pendente(s), ' . $updated . ' atualização(ões). Nenhum convite será enviado nesta importação.'];
    }

    public static function apply(array $plan): array|\WP_Error
    {
        global $wpdb;
        if (!current_user_can('erp_gerenciar_membros') || !current_user_can('erp_gerenciar_familias')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        $lock = 'igreja_import_' . substr(hash('sha256', Database::table('membros')), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) { return new \WP_Error('transfer_busy', 'Outra importação está em andamento.'); }
        $members = []; $families = [];
        try {
            if ($wpdb->query('START TRANSACTION') === false) { return new \WP_Error('transfer_transaction', 'Não foi possível iniciar a importação.'); }
            foreach ($plan['rows'] as $row) {
                $old = $row['id'] ? Membros::get_by_id($row['id']) : null;
                if ($row['id'] && (!$old || !hash_equals($row['hash'], hash('sha256', wp_json_encode($old))))) { throw new \RuntimeException('Um cadastro mudou após a prévia. Envie o arquivo novamente.'); }
                $data = $row['data'];
                $data['id'] = $row['id'];
                if ($row['family_present']) {
                    $family_id = 0;
                    if ($row['family'] !== '') {
                        $matches = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE nome_familia = %s LIMIT 2', Database::table('familias'), $row['family']));
                        if (count($matches) > 1) { throw new \RuntimeException('Há famílias com nomes iguais. Diferencie seus nomes antes da importação.'); }
                        $family_id = $matches ? (int) $matches[0] : Membros::salvar_familia(['nome_familia' => $row['family']], false);
                        if (is_wp_error($family_id)) { throw new \RuntimeException($family_id->get_error_message()); }
                        $families[$family_id] = $family_id;
                    }
                    $data['familia_id'] = $family_id;
                }
                // Null DB values become empty form values before the shared validator.
                foreach ($data as $key => $value) { if ($value === null) { $data[$key] = ''; } }
                $saved = Membros::salvar_membro($data, false);
                if (is_wp_error($saved)) { throw new \RuntimeException($saved->get_error_message()); }
                $members[] = $saved;
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Não foi possível concluir a importação.'); }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('transfer_apply', $error->getMessage());
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        foreach ($families as $id) { do_action('igreja_erp_familia_salva', $id, get_current_blog_id()); }
        foreach ($members as $id) { do_action('igreja_erp_membro_salvo', $id, get_current_blog_id()); }
        return ['message' => count($members) . ' membro(s) importado(s). Confira os cadastros e aprove para criar as contas e enviar os convites.'];
    }
}
