# Igreja ERP — Membros 0.2.0

Plugin independente para membros, famílias e formulário público. Requer Igreja ERP Core 0.5.0 ou superior.

Instale o Core atualizado e depois este ZIP. Ative ou desative em Plugins ou Igreja ERP → Addons. Desativar preserva as mesmas tabelas e opções já utilizadas pelo Core 0.3.0. A ativação não cria nem duplica os dados.

Em Igreja ERP → Cadastro público, marque os campos que deseja exibir e habilite o formulário. Nome e aceite continuam obrigatórios. E-mail e WhatsApp podem ser ocultados; quando algum contato está habilitado, é preciso preencher ao menos um dos contatos visíveis. Campos ocultos são ignorados no servidor, inclusive em requisições manipuladas.

Sem Cloudflare, o formulário funciona com nonce, campo armadilha e limite de tentativas. Para usar Turnstile, preencha as duas chaves e marque Habilitar Cloudflare Turnstile. Quando habilitado, erros ou indisponibilidade da verificação impedem o envio e mantêm o formulário visível. Chave secreta vazia no painel preserva a chave existente; removê-la desabilita o Turnstile.

O addon mantém o namespace de serviços IgrejaERP\Core\Membros para compatibilidade com integrações. Verifique did_action('igreja_erp_membros_loaded') antes de chamar seus serviços; somente o addon ativo carrega as classes de membros/formulário. Cada instalação/site do Multisite possui seus dados e configurações.

Atualizações nativas do WordPress usam o manifesto addons/igreja-erp-membros/update.json no repositório paulommaeda/appshalom e releases membros-vX.Y.Z, separadas das versões do Core. No Multisite, o administrador da rede pode ativar/desativar o plugin para toda a rede; para habilitar por igreja, ative o Core na rede e o addon somente nos sites desejados.

Importe e exporte CSV em Igreja ERP → Importar / exportar. Novos registros ficam pendentes até aprovação em Membros. A aprovação exige e-mail válido, cria um usuário erp_membro e envia convite para definir senha. Reaprovar não duplica contas nem convites. O botão Reenviar convite invalida o link anterior. Falhas do serviço de e-mail ficam registradas para nova tentativa. O convite usa nome, logo e cores da igreja atual.

Aprovação e situação eclesiástica são independentes: aprovar um Visitante libera sua conta sem convertê-lo automaticamente em Membro Ativo. Exportações não contêm senha, chave de redefinição, contas WP ou estado de aprovação; a importação não cria privilégios nem fabrica evidências de aceite do formulário.
