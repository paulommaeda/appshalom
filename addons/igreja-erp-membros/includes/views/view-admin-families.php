<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;
$url = admin_url('admin.php?page=igreja-erp-familias');
?>
<div class="wrap igreja-members"><header class="igreja-admin-heading"><div><h1>Famílias</h1><p><?php echo (int) $list['total']; ?> família(s) encontrada(s).</p></div><?php if ($can_edit): ?><a class="button button-primary" href="<?php echo esc_url($url . '&novo=1'); ?>">Cadastrar família</a><?php endif; ?></header>
<?php if ($notice): ?><div class="notice notice-error"><p><?php echo esc_html($notice['error']); ?></p></div><?php elseif (self::query('salvo') === '1'): ?><div class="notice notice-success"><p>Família salva.</p></div><?php endif; ?>
<?php if ($editing): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="igreja-panel">
<h2><?php echo $id ? 'Editar família' : 'Nova família'; ?></h2><input type="hidden" name="action" value="igreja_erp_save_family"><input type="hidden" name="familia[id]" value="<?php echo (int) $id; ?>"><?php wp_nonce_field('igreja_erp_save_family'); ?>
<div class="igreja-grid"><?php
self::input($values, 'familia', 'nome_familia', 'Nome da família *', 'text', 150, true);
self::input($values, 'familia', 'telefone_fixo', 'Telefone', 'tel', 20);
foreach (['cep' => ['CEP', 10], 'logradouro' => ['Rua / avenida', 200], 'numero' => ['Número', 30], 'complemento' => ['Complemento', 100], 'bairro' => ['Bairro', 100], 'cidade' => ['Cidade', 100], 'uf' => ['Estado (UF)', 2]] as $key => $field) {
    self::input($values, 'familia', $key, $field[0], 'text', $field[1]);
}
?></div><label class="igreja-field">Observações<textarea name="familia[observacoes]" rows="3" maxlength="5000"><?php echo esc_textarea((string) ($values['observacoes'] ?? '')); ?></textarea></label>
<div class="igreja-form-actions"><?php submit_button('Salvar família', 'primary', 'submit', false); ?><a class="button" href="<?php echo esc_url($url); ?>">Voltar à lista</a></div></form>
<?php if (!empty($family['membros'])): ?><section class="igreja-panel"><h2>Integrantes da família</h2><ul><?php foreach ($family['membros'] as $member): ?><li><a href="<?php echo esc_url(admin_url('admin.php?page=igreja-erp-membros&editar=' . (int) $member->id)); ?>"><?php echo esc_html($member->nome_completo); ?></a> — <?php echo esc_html($member->parentesco ?: 'Parentesco não informado'); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php endif; ?>
<form method="get" class="igreja-filters"><input type="hidden" name="page" value="igreja-erp-familias"><label>Nome da família<input type="search" name="busca" value="<?php echo esc_attr(self::query('busca')); ?>"></label><button class="button">Filtrar</button><a class="button" href="<?php echo esc_url($url); ?>">Limpar</a></form>
<div class="igreja-table-wrap"><table class="widefat striped"><thead><tr><th>Família</th><th>Cidade / UF</th><th>Telefone</th><th>Ações</th></tr></thead><tbody>
<?php if (!$list['items']): ?><tr><td colspan="4">Nenhuma família encontrada.</td></tr><?php endif; ?>
<?php foreach ($list['items'] as $row): ?><tr><td><strong><?php echo esc_html($row->nome_familia); ?></strong></td><td><?php echo esc_html($row->cidade . ($row->uf ? ' / ' . $row->uf : '')); ?></td><td><?php echo esc_html($row->telefone_fixo ?: '—'); ?></td><td><?php if ($can_edit): ?><a class="button button-small" href="<?php echo esc_url($url . '&editar=' . (int) $row->id); ?>">Editar</a><?php endif; ?> <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=igreja-erp-membros&familia_id=' . (int) $row->id)); ?>">Ver membros</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php self::pagination($list); ?></div>
