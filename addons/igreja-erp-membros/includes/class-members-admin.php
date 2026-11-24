<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

final class MembersAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_igreja_erp_save_member', [self::class, 'save_member']);
        add_action('admin_post_igreja_erp_save_family', [self::class, 'save_family']);
        add_action('wp_ajax_igreja_erp_buscar_familias', [self::class, 'search_families']);
    }

    public static function menu(): void
    {
        add_submenu_page('igreja-erp-core', 'Membros', 'Membros', 'erp_visualizar_membros', 'igreja-erp-membros', [self::class, 'render_members']);
        add_submenu_page('igreja-erp-core', 'Famílias', 'Famílias', 'erp_visualizar_familias', 'igreja-erp-familias', [self::class, 'render_families']);
    }

    public static function assets(string $hook): void
    {
        $page = self::query('page');
        if (!in_array($page, ['igreja-erp-membros', 'igreja-erp-familias', 'igreja-erp-cadastro', 'igreja-erp-core'], true) || !current_user_can('erp_visualizar_membros')) {
            return;
        }
        wp_enqueue_style('igreja-erp-members', IGREJA_ERP_MEMBROS_URL . 'assets/css/members.css', [], IGREJA_ERP_MEMBROS_VERSION);
        wp_add_inline_style('igreja-erp-members', Settings::css_variables());
        wp_enqueue_script('igreja-erp-members', IGREJA_ERP_MEMBROS_URL . 'assets/js/members.js', [], IGREJA_ERP_MEMBROS_VERSION, true);
        wp_localize_script('igreja-erp-members', 'igrejaMembers', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('igreja_erp_search_families')]);
    }

    public static function query(string $key): string
    {
        return is_string($_GET[$key] ?? null) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
    }

    private static function guard(string $cap): void
    {
        if (!current_user_can($cap)) {
            wp_die('Você não tem permissão para acessar este cadastro.', '', ['response' => 403]);
        }
    }

    private static function notice(string $kind): array
    {
        $key = 'igreja_erp_form_' . $kind . '_' . get_current_user_id();
        $value = get_transient($key);
        delete_transient($key);
        return is_array($value) ? $value : [];
    }

    public static function render_members(): void
    {
        self::guard('erp_visualizar_membros');
        $can_edit = current_user_can('erp_gerenciar_membros');
        $notice = self::notice('member');
        $id = Membros::parse_id(self::query('editar') ?: '0');
        if (is_wp_error($id)) {
            wp_die(esc_html($id->get_error_message()), '', ['response' => 400]);
        }
        $record = $id ? Membros::get_by_id($id) : null;
        if ($id && !$record) {
            wp_die('Membro não encontrado nesta igreja.', '', ['response' => 404]);
        }
        $editing = $can_edit && (self::query('novo') === '1' || $id || $notice);
        $values = array_replace($record ? (array) $record : ['status' => 'Membro Ativo'], $notice['values'] ?? []);
        $filters = ['busca' => self::query('busca'), 'status' => self::query('status'), 'familia_id' => absint(self::query('familia_id')), 'page' => absint(self::query('pagina') ?: '1')];
        $list = Membros::listar_membros($filters);
        require IGREJA_ERP_MEMBROS_DIR . 'includes/views/view-admin-members.php';
    }

    public static function render_families(): void
    {
        self::guard('erp_visualizar_familias');
        $can_edit = current_user_can('erp_gerenciar_familias');
        $notice = self::notice('family');
        $id = Membros::parse_id(self::query('editar') ?: '0');
        if (is_wp_error($id)) {
            wp_die(esc_html($id->get_error_message()), '', ['response' => 400]);
        }
        $family = $id ? Membros::get_familia($id) : [];
        if ($id && !$family) {
            wp_die('Família não encontrada nesta igreja.', '', ['response' => 404]);
        }
        $editing = $can_edit && (self::query('novo') === '1' || $id || $notice);
        $values = array_replace(isset($family['familia']) ? (array) $family['familia'] : [], $notice['values'] ?? []);
        $list = Membros::listar_familias(self::query('busca'), absint(self::query('pagina') ?: '1'));
        require IGREJA_ERP_MEMBROS_DIR . 'includes/views/view-admin-families.php';
    }

    private static function finish(string $kind, array $input, int|\WP_Error $result): void
    {
        $slug = $kind === 'member' ? 'igreja-erp-membros' : 'igreja-erp-familias';
        $params = ['page' => $slug];
        if (is_wp_error($result)) {
            // Keep bounded form values for this operator only, never in a URL.
            $values = [];
            foreach ($input as $key => $value) {
                if (is_string($value)) {
                    $values[$key] = mb_substr(sanitize_textarea_field($value), 0, 5000);
                }
            }
            set_transient('igreja_erp_form_' . $kind . '_' . get_current_user_id(), ['error' => $result->get_error_message(), 'values' => $values], 180);
            $id = Membros::parse_id($input['id'] ?? 0);
            $params[!is_wp_error($id) && $id ? 'editar' : 'novo'] = !is_wp_error($id) && $id ? $id : 1;
        } else {
            $params['editar'] = $result;
            $params['salvo'] = '1';
        }
        wp_safe_redirect(add_query_arg($params, admin_url('admin.php')));
        exit;
    }

    public static function save_member(): void
    {
        self::guard('erp_gerenciar_membros');
        check_admin_referer('igreja_erp_save_member');
        $input = isset($_POST['membro']) && is_array($_POST['membro']) ? wp_unslash($_POST['membro']) : [];
        self::finish('member', $input, Membros::salvar_membro($input));
    }

    public static function save_family(): void
    {
        self::guard('erp_gerenciar_familias');
        check_admin_referer('igreja_erp_save_family');
        $input = isset($_POST['familia']) && is_array($_POST['familia']) ? wp_unslash($_POST['familia']) : [];
        self::finish('family', $input, Membros::salvar_familia($input));
    }

    public static function search_families(): void
    {
        check_ajax_referer('igreja_erp_search_families');
        self::guard('erp_visualizar_familias');
        self::guard('erp_gerenciar_membros');
        $list = Membros::listar_familias(self::query('busca'));
        wp_send_json_success(array_map(static fn ($f) => ['id' => (int) $f->id, 'nome' => $f->nome_familia], $list['items']));
    }

    public static function input(array $values, string $prefix, string $key, string $label, string $type = 'text', int $limit = 180, bool $required = false): void
    {
        $value = is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
        echo '<label class="igreja-field">' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($prefix . '[' . $key . ']') . '" value="' . esc_attr($value) . '" maxlength="' . (int) $limit . '"' . ($required ? ' required' : '') . ($type === 'date' ? ' min="1900-01-01" max="' . esc_attr(current_time('Y-m-d')) . '"' : '') . '></label>';
    }

    public static function select(array $values, string $key, string $label, array $options, bool $required = false): void
    {
        echo '<label class="igreja-field">' . esc_html($label) . '<select name="membro[' . esc_attr($key) . ']"' . ($required ? ' required' : '') . '>';
        if (!$required) {
            echo '<option value="">Não informado</option>';
        }
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($values[$key] ?? ''), (string) $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label>';
    }

    public static function pagination(array $list): void
    {
        if ($list['pages'] < 2) {
            return;
        }
        $base = add_query_arg('pagina', '%#%');
        echo '<nav class="igreja-pagination" aria-label="Paginação">' . wp_kses_post(paginate_links(['base' => $base, 'format' => '', 'current' => $list['page'], 'total' => $list['pages'], 'prev_text' => 'Anterior', 'next_text' => 'Próxima'])) . '</nav>';
    }
}
