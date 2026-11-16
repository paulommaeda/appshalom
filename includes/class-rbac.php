<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Explicit capabilities, with constrained fallback for object owners/leaders. */
final class RBAC
{
    private const VERSION = '1.1.0';

    public static function rules(): array
    {
        return apply_filters('igreja_erp_rbac_acoes', [
            'acessar_portal' => ['cap' => 'erp_acessar_portal'],
            'visualizar_membros' => ['cap' => 'erp_visualizar_membros'],
            'gerenciar_membros' => ['cap' => 'erp_gerenciar_membros'],
            'visualizar_familias' => ['cap' => 'erp_visualizar_familias'],
            'gerenciar_familias' => ['cap' => 'erp_gerenciar_familias'],
            'visualizar_proprio_membro' => ['cap' => 'erp_visualizar_membros', 'scope' => 'membro', 'local_cap' => 'erp_acessar_portal'],
            'editar_proprio_membro' => ['cap' => 'erp_gerenciar_membros', 'scope' => 'membro', 'local_cap' => 'erp_editar_proprio_perfil'],
            'visualizar_departamentos' => ['cap' => 'erp_visualizar_departamentos'],
            'gerenciar_departamentos' => ['cap' => 'erp_gerenciar_departamentos'],
            'visualizar_equipe' => ['cap' => 'erp_visualizar_equipes', 'scope' => 'departamento', 'local_cap' => 'erp_visualizar_equipe_propria'],
            'gerenciar_equipe' => ['cap' => 'erp_gerenciar_equipes', 'scope' => 'departamento', 'local_cap' => 'erp_gerenciar_equipe_propria'],
            'visualizar_centros_custo' => ['cap' => 'erp_visualizar_centros_custo'],
            'gerenciar_centros_custo' => ['cap' => 'erp_gerenciar_centros_custo'],
            'visualizar_financeiro' => ['cap' => 'erp_visualizar_financeiro'],
            'gerenciar_financeiro' => ['cap' => 'erp_gerenciar_financeiro'],
            'gerenciar_configuracoes' => ['cap' => 'erp_gerenciar_configuracoes'],
            'executar_seed' => ['cap' => 'erp_executar_seed'],
        ]);
    }

    public static function role_definitions(): array
    {
        $base = ['read', 'erp_acessar_portal', 'erp_editar_proprio_perfil', 'erp_visualizar_departamentos'];
        $secretaria = ['erp_visualizar_membros', 'erp_gerenciar_membros', 'erp_visualizar_familias', 'erp_gerenciar_familias', 'erp_gerenciar_departamentos', 'erp_visualizar_equipes', 'erp_gerenciar_equipes', 'erp_visualizar_centros_custo'];
        $administrativo = ['erp_visualizar_centros_custo', 'erp_gerenciar_centros_custo', 'erp_visualizar_financeiro', 'erp_gerenciar_financeiro'];
        $pastor = array_unique(array_merge($base, $secretaria, $administrativo, ['erp_gerenciar_configuracoes', 'erp_executar_seed', 'upload_files']));
        return [
            'erp_admin_pastor' => ['nome' => 'ERP — Administração pastoral', 'caps' => $pastor],
            'erp_secretaria' => ['nome' => 'ERP — Secretaria', 'caps' => array_merge($base, $secretaria)],
            'erp_administrativo' => ['nome' => 'ERP — Administrativo', 'caps' => array_merge($base, $administrativo)],
            'erp_lider_depto' => ['nome' => 'ERP — Líder de departamento', 'caps' => array_merge($base, ['erp_visualizar_equipe_propria', 'erp_gerenciar_equipe_propria'])],
            'erp_lider_pg' => ['nome' => 'ERP — Líder de pequeno grupo', 'caps' => $base],
            'erp_professor_curso' => ['nome' => 'ERP — Professor de curso', 'caps' => $base],
            'erp_membro' => ['nome' => 'ERP — Membro', 'caps' => $base],
        ];
    }

    public static function install_roles(): void
    {
        $definitions = self::role_definitions();
        $owned = [];
        foreach ($definitions as $definition) {
            $owned = array_merge($owned, $definition['caps']);
        }
        $owned = array_values(array_unique(array_filter($owned, static fn ($cap) => str_starts_with($cap, 'erp_'))));
        foreach ($definitions as $slug => $definition) {
            $role = get_role($slug) ?: add_role($slug, $definition['nome'], []);
            if (!$role) {
                continue;
            }
            // Synchronize only Core capabilities; add-on capabilities are retained.
            foreach ($owned as $cap) {
                if (in_array($cap, $definition['caps'], true)) {
                    $role->add_cap($cap);
                } else {
                    $role->remove_cap($cap);
                }
            }
            foreach ($definition['caps'] as $cap) {
                $role->add_cap($cap);
            }
        }
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($owned as $cap) {
                $admin->add_cap($cap);
            }
        }
        update_option('igreja_erp_core_rbac_version', self::VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('igreja_erp_core_rbac_version') !== self::VERSION) {
            self::install_roles();
        }
    }

    /** $contexto_id is a member or department ID, according to the action. */
    public static function usuario_pode(int $wp_user_id, string $acao, int $contexto_id = 0): bool
    {
        if ($wp_user_id < 1 || $contexto_id < 0) {
            return false;
        }
        $rules = self::rules();
        if (!isset($rules[$acao]) || !is_array($rules[$acao]) || empty($rules[$acao]['cap'])) {
            return false;
        }
        $user = get_userdata($wp_user_id);
        if (!$user || !$user->exists()) {
            return false;
        }
        $rule = $rules[$acao];
        if (user_can($user, $rule['cap'])) {
            return true;
        }
        if ($contexto_id < 1 || empty($rule['scope']) || empty($rule['local_cap']) || !user_can($user, $rule['local_cap'])) {
            return false;
        }
        global $wpdb;
        $member_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE wp_user_id = %d LIMIT 1',
            Database::table('membros'), $wp_user_id
        ));
        if (!$member_id) {
            return false;
        }
        if ($rule['scope'] === 'membro') {
            return $member_id === $contexto_id;
        }
        if ($rule['scope'] === 'departamento') {
            $leader = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE id = %d AND ativo = 1 AND (lider_membro_id = %d OR vice_lider_id = %d) LIMIT 1',
                Database::table('departamentos'), $contexto_id, $member_id, $member_id
            ));
            if ($leader) {
                return true;
            }
            return (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT equipe.id FROM %i AS equipe INNER JOIN %i AS depto ON depto.id = equipe.departamento_id WHERE equipe.departamento_id = %d AND equipe.membro_id = %d AND equipe.ativo = 1 AND depto.ativo = 1 AND equipe.papel IN (%s, %s, %s) LIMIT 1',
                Database::table('departamento_equipe'), Database::table('departamentos'),
                $contexto_id, $member_id, 'Líder', 'Vice-Líder', 'Coordenador'
            ));
        }
        // Add-ons must verify their own PG/course contextual relationships.
        return (bool) apply_filters('igreja_erp_rbac_contexto_autorizado', false, $rule['scope'], $contexto_id, $wp_user_id, $member_id, $acao);
    }

    /**
     * permission_callback for native WordPress REST. Native authentication runs
     * first; cookies require a REST nonce, Application Passwords remain supported.
     * Only route parameters can identify the object, never a body/query override.
     */
    public static function rest_permission_check(\WP_REST_Request $request, string $acao = 'acessar_portal'): bool|\WP_Error
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_Error('igreja_erp_nao_autenticado', 'Entre na sua conta para continuar.', ['status' => 401]);
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce === null || $nonce === '') {
            $nonce = $request->get_param('_wpnonce');
        }
        if ($nonce !== null && $nonce !== '' && (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest'))) {
            return new \WP_Error('igreja_erp_nonce_invalido', 'A sessão expirou. Atualize a página e tente novamente.', ['status' => 403]);
        }
        $rules = self::rules();
        $scope = $rules[$acao]['scope'] ?? '';
        $context = 0;
        $route = $request->get_url_params();
        $keys = $scope === 'membro' ? ['membro_id', 'id'] : ['departamento_id', 'depto_id', 'id'];
        foreach ($keys as $key) {
            if (isset($route[$key])) {
                $raw = $route[$key];
                if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]*$/D', (string) $raw) || filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    return new \WP_Error('igreja_erp_contexto_invalido', 'Identificador de contexto inválido.', ['status' => 400]);
                }
                $context = (int) $raw;
                break;
            }
        }
        if (!self::usuario_pode($user_id, $acao, $context)) {
            return new \WP_Error('igreja_erp_acesso_negado', 'Você não tem permissão para realizar esta ação.', ['status' => 403]);
        }
        return true;
    }
}
