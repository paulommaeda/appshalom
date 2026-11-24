<?php
namespace IgrejaERP\Membros;
use IgrejaERP\Core\Database;
use IgrejaERP\Core\Settings;
use IgrejaERP\Core\Membros;
defined('ABSPATH') || exit;

final class Accounts
{
    public static function register(): void
    {
        add_action('admin_post_igreja_erp_approve_members', [self::class, 'handler']);
    }

    public static function username(string $name): string
    {
        $parts = preg_split('/\s+/u', trim(strtolower(remove_accents($name)))) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($p) => preg_replace('/[^a-z0-9]/', '', $p), $parts)));
        $first = substr(array_shift($parts) ?: 'membro', 0, 25);
        $candidates = [];
        foreach (array_reverse($parts) as $last) {
            if (!in_array($last, ['de', 'da', 'do', 'dos', 'das', 'e'], true)) {
                $candidates[] = substr($first . '.' . $last, 0, 55);
            }
        }
        $candidates = array_unique($candidates ?: [$first . '.membro']);
        foreach ($candidates as $candidate) {
            if (!username_exists($candidate) && validate_username($candidate)) { return $candidate; }
        }
        $base = reset($candidates);
        for ($suffix = 2; ; $suffix++) {
            $candidate = $base . '-' . $suffix;
            if (!username_exists($candidate) && validate_username($candidate)) { return $candidate; }
        }
    }

    public static function approve(int $id, bool $resend = false): array|\WP_Error
    {
        global $wpdb;
        if (!current_user_can('erp_gerenciar_membros')) {
            return new \WP_Error('approval_cap', 'Você não tem permissão para aprovar membros.');
        }
        // wp_users is shared by Multisite. Serialize our provisioning across sites.
        $lock = 'igreja_accounts_' . substr(hash('sha256', $wpdb->users), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
            return new \WP_Error('approval_busy', 'Outra aprovação está sendo processada. Tente novamente.');
        }
        try {
            $member = Membros::get_by_id($id);
            if (!$member) { return new \WP_Error('approval_missing', 'Membro não encontrado nesta igreja.'); }
            if (empty($member->email) || !is_email($member->email)) { return new \WP_Error('approval_email', 'Informe um e-mail válido no cadastro antes de aprovar e convidar.'); }
            $user = $member->wp_user_id ? get_userdata((int) $member->wp_user_id) : false;
            if ($member->wp_user_id && (!$user || !is_user_member_of_blog($user->ID, get_current_blog_id()))) {
                return new \WP_Error('approval_link', 'A conta vinculada não pertence a esta igreja. Confira o vínculo.');
            }
            if ($user && (is_super_admin($user->ID) || !in_array('erp_membro', $user->roles, true) || count($user->roles) !== 1 || user_can($user, 'manage_options') || user_can($user, 'erp_gerenciar_membros'))) {
                return new \WP_Error('approval_privileged', 'Esta conta possui permissões de gestão. Seu acesso deve ser administrado na tela de usuários do WordPress.');
            }
            if ($user && strcasecmp($user->user_email, $member->email) !== 0) {
                return new \WP_Error('approval_email_link', 'O e-mail do cadastro difere da conta vinculada. Confira os dados antes de enviar o convite.');
            }
            if (!$user) {
                if (email_exists($member->email)) {
                    return new \WP_Error('approval_exists', 'Este e-mail já pertence a uma conta WordPress. Confira o vínculo; nenhuma conta existente foi alterada.');
                }
                // Recovery marker is server-owned and is never accepted from imports/forms.
                $meta_key = 'igreja_erp_member_' . get_current_blog_id();
                $recover = get_users(['meta_key' => $meta_key, 'meta_value' => $id, 'number' => 2]);
                if (count($recover) === 1) {
                    return new \WP_Error('approval_recover', 'Há uma conta criada para este cadastro sem vínculo concluído. Confira a conta antes de tentar novamente.');
                }
                $parts = preg_split('/\s+/u', trim($member->nome_completo));
                $user_id = wp_insert_user([
                    'user_login' => self::username($member->nome_completo),
                    'user_pass' => wp_generate_password(32, true, true),
                    'user_email' => $member->email,
                    'display_name' => $member->nome_completo,
                    'first_name' => $parts[0] ?? '',
                    'last_name' => implode(' ', array_slice($parts, 1)),
                    'role' => 'erp_membro',
                    'meta_input' => [$meta_key => $id],
                ]);
                if (is_wp_error($user_id)) { return $user_id; }
                $user = get_userdata($user_id);
                if (!$user || $wpdb->update(Database::table('membros'), ['wp_user_id' => $user_id], ['id' => $id, 'wp_user_id' => null]) !== 1) {
                    return new \WP_Error('approval_save', 'A conta foi criada, mas o vínculo não pôde ser concluído. Confira o cadastro antes de repetir.');
                }
            }
            if ((int) $member->aprovado === 1 && $member->convite_status === 'enviado' && !$resend) {
                return ['user_id' => (int) $user->ID, 'sent' => false, 'message' => 'Membro já aprovado; convite anterior preservado.'];
            }
            $approval = ['aprovado' => 1];
            if (!(int) $member->aprovado) {
                $approval['aprovado_em'] = current_time('mysql');
                $approval['aprovado_por'] = get_current_user_id();
            }
            if ($wpdb->update(Database::table('membros'), $approval, ['id' => $id]) === false) { return new \WP_Error('approval_save', 'Não foi possível registrar a aprovação.'); }
            $sent = self::invite($user, $member);
            $wpdb->update(Database::table('membros'), ['convite_status' => is_wp_error($sent) ? 'falhou' : 'enviado', 'convite_enviado_em' => is_wp_error($sent) ? null : current_time('mysql')], ['id' => $id]);
            if (!(int) $member->aprovado) { do_action('igreja_erp_membro_aprovado', $id, (int) $user->ID, get_current_blog_id()); }
            if (is_wp_error($sent)) { return $sent; }
            return ['user_id' => (int) $user->ID, 'sent' => true, 'message' => 'Membro aprovado e convite enviado ao serviço de e-mail.'];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private static function invite(\WP_User $user, object $member): bool|\WP_Error
    {
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) { return $key; }
        $reset_url = add_query_arg(['action' => 'rp', 'key' => $key, 'login' => $user->user_login], site_url('wp-login.php', 'login'));
        $name = sanitize_text_field((string) Settings::get('nome_curto'));
        $primary = sanitize_hex_color((string) Settings::get('cor_primaria')) ?: '#000A52';
        $accent = sanitize_hex_color((string) Settings::get('cor_destaque')) ?: '#001BE1';
        $logo = Settings::logo_url();
        $message = '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f1f4fa;font-family:Arial,sans-serif;color:#172238"><div style="max-width:600px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden"><div style="background:' . esc_attr($primary) . ';color:' . Settings::contrast($primary) . ';padding:28px">';
        if ($logo) { $message .= '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '" style="max-width:200px;max-height:90px"><br>'; }
        $message .= '<h1 style="font-size:24px">' . esc_html($name) . '</h1></div><div style="padding:28px"><h2>Seu acesso está pronto</h2><p>Olá, ' . esc_html($member->nome_social_apelido ?: $member->nome_completo) . '!</p><p>Seu cadastro foi aprovado. Estamos felizes em caminhar com você.</p><p>Seu usuário: <strong>' . esc_html($user->user_login) . '</strong></p><p>Criamos uma senha temporária aleatória. Use o botão abaixo para definir sua própria senha e acessar o sistema.</p><p style="margin:30px 0"><a href="' . esc_url($reset_url) . '" style="background:' . esc_attr($accent) . ';color:' . Settings::contrast($accent) . ';padding:14px 20px;border-radius:8px;text-decoration:none;display:inline-block">Definir minha senha</a></p><p>Se o botão não abrir, copie este endereço:</p><p style="word-break:break-all"><a href="' . esc_url($reset_url) . '">' . esc_html($reset_url) . '</a></p><p>Este link é pessoal e tem validade limitada. Se precisar de outro, use a recuperação de senha na tela de entrada.</p><p>' . esc_html((string) Settings::get('mensagem_login')) . '</p></div></div></body></html>';
        $from_name = static fn () => $name;
        add_filter('wp_mail_from_name', $from_name);
        try { $sent = wp_mail($user->user_email, 'Seu convite — ' . $name, $message, ['Content-Type: text/html; charset=UTF-8']); }
        finally { remove_filter('wp_mail_from_name', $from_name); }
        return $sent ? true : new \WP_Error('invite_failed', 'Membro aprovado e conta criada, mas o serviço de e-mail não aceitou o convite. Use Reenviar convite após conferir o envio de e-mails do site.');
    }

    public static function handler(): void
    {
        if (!current_user_can('erp_gerenciar_membros')) { wp_die('Acesso não autorizado.', '', ['response' => 403]); }
        check_admin_referer('igreja_erp_approve_members');
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || !$ids || count($ids) > 20) { wp_die('Selecione de 1 a 20 membros.', '', ['response' => 400]); }
        $messages = [];
        foreach (array_unique($ids, SORT_REGULAR) as $raw) {
            $id = Membros::parse_id($raw);
            $result = is_wp_error($id) ? $id : self::approve($id, ($_POST['resend'] ?? '') === '1');
            $member = !is_wp_error($id) ? Membros::get_by_id($id) : null;
            $messages[] = ($member ? $member->nome_completo . ': ' : '') . (is_wp_error($result) ? $result->get_error_message() : $result['message']);
        }
        set_transient('igreja_erp_account_notice_' . get_current_user_id(), $messages, 120);
        wp_safe_redirect(admin_url('admin.php?page=igreja-erp-membros'));
        exit;
    }
}
