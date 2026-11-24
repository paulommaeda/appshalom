<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;
$url = admin_url('admin.php?page=igreja-erp-membros');
?>
<div class="wrap igreja-members"><header class="igreja-admin-heading"><div><h1>Membros</h1><p><?php echo (int) $list['total']; ?> cadastro(s) encontrado(s).</p></div><?php if ($can_edit): ?><a class="button button-primary" href="<?php echo esc_url($url . '&novo=1'); ?>">Cadastrar membro</a><?php endif; ?></header>
<?php if ($notice): ?><div class="notice notice-error"><p><?php echo esc_html($notice['error']); ?></p></div><?php elseif (self::query('salvo') === '1'): ?><div class="notice notice-success"><p>Cadastro salvo.</p></div><?php endif; ?>
<?php if ($editing): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="igreja-panel" id="igreja-member-form">
<h2><?php echo $id ? 'Editar membro' : 'Novo membro'; ?></h2>
<input type="hidden" name="action" value="igreja_erp_save_member"><input type="hidden" name="membro[id]" value="<?php echo (int) $id; ?>"><?php wp_nonce_field('igreja_erp_save_member'); ?>
<h3>Identificação e contato</h3><div class="igreja-grid">
<?php self::input($values, 'membro', 'nome_completo', 'Nome completo *', 'text', 180, true);
self::input($values, 'membro', 'nome_social_apelido', 'Nome social / apelido', 'text', 100);
self::input($values, 'membro', 'data_nascimento', 'Data de nascimento', 'date', 10);
self::select($values, 'sexo', 'Sexo', ['M' => 'Masculino', 'F' => 'Feminino']);
self::select($values, 'estado_civil', 'Estado civil', array_combine(Membros::ESTADOS_CIVIS, Membros::ESTADOS_CIVIS));
self::input($values, 'membro', 'cpf', 'CPF', 'text', 14);
self::input($values, 'membro', 'rg', 'RG', 'text', 25);
self::input($values, 'membro', 'email', 'E-mail', 'email', 150);
self::input($values, 'membro', 'celular_whatsapp', 'WhatsApp com DDD', 'tel', 20);
self::input($values, 'membro', 'profissao', 'Profissão', 'text', 120);
self::input($values, 'membro', 'escolaridade', 'Escolaridade', 'text', 60); ?>
</div><h3>Vida na igreja</h3><div class="igreja-grid">
<?php self::select($values, 'status', 'Situação *', array_combine(Membros::STATUS, Membros::STATUS), true);
self::input($values, 'membro', 'data_conversao', 'Data de conversão', 'date', 10);
self::input($values, 'membro', 'data_batismo', 'Data de batismo', 'date', 10);
self::select($values, 'forma_recepcao', 'Forma de recepção', array_combine(Membros::RECEPCAO, Membros::RECEPCAO));
self::input($values, 'membro', 'igreja_origem', 'Igreja de origem', 'text', 150); ?>
</div>
<?php if (current_user_can('erp_gerenciar_familias') && current_user_can('erp_visualizar_familias')):
    $selected_family = Membros::get_familia((int) ($values['familia_id'] ?? 0)); ?>
<h3>Família</h3><div class="igreja-grid"><div class="igreja-family-picker">
<input type="hidden" name="membro[familia_id]" id="igreja-family-id" value="<?php echo (int) ($values['familia_id'] ?? 0); ?>">
<label class="igreja-field">Buscar família pelo nome<input type="search" id="igreja-family-search" autocomplete="off" aria-describedby="igreja-family-selected"></label>
<p id="igreja-family-selected" aria-live="polite"><?php echo isset($selected_family['familia']) ? esc_html($selected_family['familia']->nome_familia) : 'Nenhuma família vinculada'; ?></p>
<div id="igreja-family-results" aria-live="polite"></div><button type="button" class="button" id="igreja-family-clear">Desvincular família</button>
<p><a href="<?php echo esc_url(admin_url('admin.php?page=igreja-erp-familias&novo=1')); ?>" target="_blank" rel="noopener">Cadastrar nova família em outra aba</a></p>
</div><?php self::select($values, 'parentesco', 'Parentesco', array_combine(Membros::PARENTESCOS, Membros::PARENTESCOS)); ?></div>
<?php endif; ?>
<?php if (!empty($record->lgpd_aceite)): ?><p class="igreja-hint">Aceite do formulário público registrado em <?php echo esc_html(mysql2date('d/m/Y H:i', $record->lgpd_data_aceite)); ?>.</p><?php endif; ?>
<div class="igreja-form-actions"><?php submit_button('Salvar membro', 'primary', 'submit', false); ?><a class="button" href="<?php echo esc_url($url); ?>">Voltar à lista</a></div>
</form>
<?php endif; ?>
<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="igreja-filters"><input type="hidden" name="page" value="igreja-erp-membros">
<label>Nome, e-mail ou WhatsApp<input type="search" name="busca" value="<?php echo esc_attr($filters['busca']); ?>"></label>
<label>Situação<select name="status"><option value="">Todas</option><?php foreach (Membros::STATUS as $status): ?><option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label>
<?php if ($filters['familia_id']): ?><input type="hidden" name="familia_id" value="<?php echo (int) $filters['familia_id']; ?>"><?php endif; ?>
<button class="button">Filtrar</button><a class="button" href="<?php echo esc_url($url); ?>">Limpar</a></form>
<div class="igreja-table-wrap"><table class="widefat striped"><thead><tr><th>Nome</th><th>Contato</th><th>Situação</th><th>Família</th><th>Ações</th></tr></thead><tbody>
<?php if (!$list['items']): ?><tr><td colspan="5">Nenhum cadastro encontrado.</td></tr><?php endif; ?>
<?php foreach ($list['items'] as $membro): $family = $membro->familia_id ? Membros::get_familia((int) $membro->familia_id) : []; ?>
<tr><td><strong><?php echo esc_html($membro->nome_completo); ?></strong><?php if ($membro->nome_social_apelido): ?><br><?php echo esc_html($membro->nome_social_apelido); ?><?php endif; ?></td>
<td><?php echo esc_html($membro->email ?: '—'); ?><br><?php echo esc_html($membro->celular_whatsapp ?: '—'); ?></td>
<td><?php echo esc_html($membro->status); ?></td><td><?php echo isset($family['familia']) ? esc_html($family['familia']->nome_familia) : '—'; ?></td>
<td><?php if ($can_edit): ?><a class="button button-small" href="<?php echo esc_url($url . '&editar=' . (int) $membro->id); ?>">Editar</a><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php self::pagination($list); ?>
</div>
