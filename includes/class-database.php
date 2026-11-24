<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Non-destructive, versioned schema. Relations are enforced by the service layer. */
final class Database
{
    private const TABLES = ['familias', 'membros', 'centros_custo', 'departamentos', 'departamento_equipe'];

    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Tabela desconhecida do Igreja ERP Core.');
        }
        return $wpdb->prefix . 'erp_' . $name;
    }

    public static function schemas(): array
    {
        global $wpdb;
        $definitions = [
            'familias' => "
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
nome_familia varchar(150) NOT NULL,
cep varchar(10) DEFAULT NULL,
logradouro varchar(200) DEFAULT NULL,
numero varchar(30) DEFAULT NULL,
complemento varchar(100) DEFAULT NULL,
bairro varchar(100) DEFAULT NULL,
cidade varchar(100) DEFAULT NULL,
uf char(2) DEFAULT NULL,
telefone_fixo varchar(20) DEFAULT NULL,
observacoes text DEFAULT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY nome_familia (nome_familia)",
            'membros' => "
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
wp_user_id bigint(20) unsigned DEFAULT NULL,
aprovado tinyint(1) NOT NULL DEFAULT 0,
aprovado_em datetime DEFAULT NULL,
aprovado_por bigint(20) unsigned DEFAULT NULL,
convite_status varchar(20) NOT NULL DEFAULT 'pendente',
convite_enviado_em datetime DEFAULT NULL,
familia_id bigint(20) unsigned DEFAULT NULL,
parentesco varchar(30) DEFAULT NULL,
nome_completo varchar(180) NOT NULL,
nome_social_apelido varchar(100) DEFAULT NULL,
data_nascimento date DEFAULT NULL,
sexo char(1) DEFAULT NULL,
estado_civil varchar(30) DEFAULT NULL,
cpf varchar(14) DEFAULT NULL,
rg varchar(25) DEFAULT NULL,
email varchar(150) DEFAULT NULL,
celular_whatsapp varchar(20) DEFAULT NULL,
foto_url varchar(255) DEFAULT NULL,
profissao varchar(120) DEFAULT NULL,
escolaridade varchar(60) DEFAULT NULL,
cep varchar(9) DEFAULT NULL,
logradouro varchar(200) DEFAULT NULL,
numero varchar(30) DEFAULT NULL,
complemento varchar(100) DEFAULT NULL,
bairro varchar(100) DEFAULT NULL,
cidade varchar(100) DEFAULT NULL,
uf char(2) DEFAULT NULL,
status varchar(40) NOT NULL DEFAULT 'Membro Ativo',
data_conversao date DEFAULT NULL,
data_batismo date DEFAULT NULL,
forma_recepcao varchar(50) DEFAULT NULL,
igreja_origem varchar(150) DEFAULT NULL,
dons_ministerios_json longtext DEFAULT NULL,
cursos_concluidos_json longtext DEFAULT NULL,
lgpd_aceite tinyint(1) NOT NULL DEFAULT 0,
lgpd_data_aceite datetime DEFAULT NULL,
lgpd_ip varchar(45) DEFAULT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY wp_user_id (wp_user_id),
UNIQUE KEY cpf (cpf),
KEY familia_id (familia_id),
KEY aprovado (aprovado),
KEY status (status),
KEY celular_whatsapp (celular_whatsapp),
KEY nome_completo (nome_completo)",
            'centros_custo' => "
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
codigo varchar(30) NOT NULL,
nome varchar(150) NOT NULL,
tipo varchar(30) NOT NULL DEFAULT 'Geral',
responsavel_membro_id bigint(20) unsigned DEFAULT NULL,
ativo tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY codigo (codigo),
KEY responsavel_membro_id (responsavel_membro_id),
KEY tipo_ativo (tipo,ativo)",
            'departamentos' => "
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
nome varchar(150) NOT NULL,
slug varchar(150) NOT NULL,
descricao text DEFAULT NULL,
lider_membro_id bigint(20) unsigned DEFAULT NULL,
vice_lider_id bigint(20) unsigned DEFAULT NULL,
centro_custo_id bigint(20) unsigned DEFAULT NULL,
exige_batismo tinyint(1) NOT NULL DEFAULT 0,
exige_curso_membresia tinyint(1) NOT NULL DEFAULT 0,
funcoes_disponiveis_json text DEFAULT NULL,
ativo tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY lider_membro_id (lider_membro_id),
KEY vice_lider_id (vice_lider_id),
KEY centro_custo_id (centro_custo_id),
KEY ativo (ativo)",
            'departamento_equipe' => "
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
departamento_id bigint(20) unsigned NOT NULL,
membro_id bigint(20) unsigned NOT NULL,
funcao varchar(100) NOT NULL,
papel varchar(40) NOT NULL DEFAULT 'Voluntário',
data_ingresso date DEFAULT NULL,
ativo tinyint(1) NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
UNIQUE KEY departamento_membro_funcao (departamento_id,membro_id,funcao),
KEY membro_id (membro_id),
KEY departamento_ativo (departamento_id,ativo)",
        ];
        $schemas = [];
        foreach ($definitions as $name => $fields) {
            // Fields and collation are internal definitions, never HTTP input.
            $schemas[$name] = $wpdb->prepare(
                'CREATE TABLE %i (' . $fields . ') ENGINE=InnoDB ' . $wpdb->get_charset_collate() . ';',
                self::table($name)
            );
            // WordPress 6.5 dbDelta keys its change log inconsistently for quoted
            // CREATE identifiers. Only dequote a validated simple table identifier.
            $schemas[$name] = preg_replace('/^CREATE TABLE `([a-zA-Z0-9_]+)` /', 'CREATE TABLE $1 ', $schemas[$name]);
        }
        return $schemas;
    }

    /** Never marks an incomplete migration as successful. */
    public static function install(): bool|\WP_Error
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $lock = 'igreja_erp_core_migration_lock';
        $started = (int) get_option($lock, 0);
        if ($started && $started < time() - 300) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', false)) {
            return new \WP_Error('igreja_erp_migracao_em_andamento', 'A atualização do banco está em andamento. Tente novamente em alguns instantes.');
        }
        try {
            foreach (self::schemas() as $name => $sql) {
                $wpdb->last_error = '';
                dbDelta($sql);
                if ($wpdb->last_error !== '') {
                    return self::install_error();
                }
                $table = self::table($name);
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
                if ($exists !== $table) {
                    return self::install_error();
                }
                preg_match_all('/^([a-z][a-z0-9_]*)\s/m', $sql, $matches);
                $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
                if ($wpdb->last_error !== '' || array_diff($matches[1], $columns)) {
                    return self::install_error();
                }
                $actual = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
                if ($wpdb->last_error !== '') {
                    return self::install_error();
                }
                preg_match_all('/^(UNIQUE )?KEY ([a-z0-9_]+) \(([^)]+)\)/m', $sql, $keys, PREG_SET_ORDER);
                $keys[] = ['PRIMARY KEY', 'UNIQUE ', 'PRIMARY', 'id'];
                foreach ($keys as $key) {
                    $rows = array_values(array_filter($actual, static fn ($row) => $row['Key_name'] === $key[2]));
                    usort($rows, static fn ($a, $b) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
                    if (array_column($rows, 'Column_name') !== explode(',', $key[3]) || !$rows || (int) $rows[0]['Non_unique'] !== ($key[1] ? 0 : 1)) {
                        return self::install_error();
                    }
                }
            }
            update_option('igreja_erp_core_db_version', IGREJA_ERP_CORE_DB_VERSION, false);
            delete_option('igreja_erp_core_install_error');
            delete_option('igreja_erp_core_migration_retry_after');
            return true;
        } finally {
            delete_option($lock);
        }
    }

    private static function install_error(): \WP_Error
    {
        $message = 'Não foi possível concluir as tabelas do Igreja ERP Core. Verifique as permissões do banco e reative o plugin.';
        update_option('igreja_erp_core_install_error', $message, false);
        update_option('igreja_erp_core_migration_retry_after', time() + 300, false);
        return new \WP_Error('igreja_erp_schema_error', $message);
    }

    public static function maybe_upgrade(): bool
    {
        if (get_option('igreja_erp_core_db_version') === IGREJA_ERP_CORE_DB_VERSION) {
            return true;
        }
        if ((int) get_option('igreja_erp_core_migration_retry_after', 0) > time()) {
            return false;
        }
        return !is_wp_error(self::install());
    }

    public static function admin_notice(): void
    {
        if (current_user_can('activate_plugins')) {
            $message = get_option('igreja_erp_core_install_error', 'O banco do Igreja ERP Core está sendo preparado. Recarregue esta página em alguns instantes.');
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
