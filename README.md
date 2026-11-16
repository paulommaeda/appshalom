# App Shalom — Igreja ERP Core

Repositório: https://github.com/paulommaeda/appshalom

## Etapa 1 — versão 0.1.0

Primeira das quatro etapas da especificação recebida:

- igreja-erp-core.php: bootstrap, constantes, autoloader e hook igreja_erp_core_loaded em plugins_loaded, prioridade 5.
- includes/class-database.php: cinco tabelas, dbDelta, índices, migração não destrutiva e esquema 1.0.0.
- includes/class-rbac.php: sete papéis ERP, capabilities e autorização contextual, com callback de permissão REST.
- includes/class-updater.php: atualização nativa pelo WordPress usando as versões deste GitHub.

As classes usam o namespace IgrejaERP\Core. O autoloader utiliza um mapa explícito para preservar os nomes class-*.php da estrutura solicitada sem depender de Composer.

**Esta versão entrega a infraestrutura inicial.** Serviços, cadastros, portal, shortcode, endpoints, seed, PWA instalável, PDF e WhatsApp pertencem às etapas seguintes.

## Instalação e atualizações

Requer WordPress 6.5+, PHP 8.1+ e banco MySQL/MariaDB compatível com WordPress, com permissão para criar e alterar tabelas.

1. Em Releases, baixe igreja-erp-core-0.1.0.zip.
2. Use Plugins → Adicionar plugin → Enviar plugin e ative Igreja ERP Core.
3. As tabelas e perfis são preparados automaticamente. Não há tela de portal nesta etapa.
4. Para versões futuras, use Painel → Atualizações ou o link Verificar atualizações na linha do plugin.
5. A instalação automática pode ser ativada pelo controle nativo de atualizações automáticas do WordPress.

O ZIP da release usa a pasta raiz igreja-erp-core/, necessária para o cabeçalho Requires Plugins: igreja-erp-core dos add-ons. Instale pelo pacote da release: o Download ZIP do repositório usa outro nome de pasta.

O atualizador consulta main/update.json por HTTPS, mantém cache de uma hora e aceita apenas ZIPs versionados das releases deste repositório público. Não precisa de token GitHub. A verificação manual exige permissão update_plugins e nonce e limpa o cache antes de consultar.

Desativar ou excluir arquivos preserva dados e perfis. A ativação em rede prepara os sites existentes; novos sites também são preparados se o Core estiver ativo na rede.

## Banco

Usa o prefixo do site atual seguido por erp_.

| Tabela | Índices e relações |
| --- | --- |
| erp_familias | ID e nome da família |
| erp_membros | CPF e usuário WordPress únicos e anuláveis; família, status, WhatsApp e nome indexados |
| erp_centros_custo | Código único; responsável e tipo/ativo indexados |
| erp_departamentos | Slug único; líder, vice, centro de custo e ativo indexados |
| erp_departamento_equipe | Único em departamento + membro + função; índices por membro e departamento/ativo |

Todos os campos da especificação estão presentes. O nome lgpd_ip foi normalizado sem o espaço acidental da especificação. CPF e wp_user_id ausentes devem ser gravados como NULL. As relações são lógicas, sem cascatas; validação de vínculos, normalização de CPF e JSON e regras de exclusão pertencem aos serviços da Etapa 2.

A versão do esquema só é atualizada depois de criar e verificar as colunas de todas as tabelas. O processo também roda após atualização de plugin, sem reativação. Migrações têm trava de concorrência e intervalo de cinco minutos após falha; reativar tenta novamente imediatamente.

## Permissões

Nenhuma conta é promovida automaticamente. Atribua os perfis pela tela Usuários. O administrador WordPress recebe as capacidades do Core.

| Perfil | Permissões do Core |
| --- | --- |
| erp_admin_pastor | Gestão do Core, membros, famílias, departamentos, equipes, centros de custo e financeiro |
| erp_secretaria | Gestão de membros, famílias, departamentos e equipes; consulta de centros de custo |
| erp_administrativo | Gestão de centros de custo e financeiro |
| erp_lider_depto | Gestão das equipes em que é líder, vice ou coordenador ativo |
| erp_lider_pg | Portal e próprio perfil; PG será definido pelo add-on |
| erp_professor_curso | Portal e próprio perfil; cursos serão definidos pelo add-on |
| erp_membro | Portal e próprio perfil |

Todos esses perfis consultam a lista básica de departamentos. Isso não concede acesso às equipes e aos dados dos membros. Perfis ERP não recebem manage_options nem promovem usuários a administradores do WordPress.

RBAC::usuario_pode($wp_user_id, $acao, $contexto_id) valida a capability global ou, para ações contextuais, a capability local e o vínculo ao departamento/membro. O membro é resolvido pelo usuário autenticado. Ações desconhecidas são negadas.

RBAC::rest_permission_check($request, $acao) é um permission_callback da REST API nativa e retorna true ou WP_Error com HTTP 401, 403 ou 400. Os IDs vêm da rota (id, membro_id ou departamento_id); corpo e query não substituem a rota. Autenticação por cookie e nonce são processados pelo WordPress antes do callback, que também rejeita nonces fornecidos e inválidos. Application Passwords permanecem compatíveis. Permissão sobre o próprio membro não libera alteração de campos administrativos: os serviços da etapa seguinte devem limitar os campos editáveis.

RBAC::rules() contém as ações disponíveis. Add-ons podem acrescentar ações pelo filtro igreja_erp_rbac_acoes (cap, scope e local_cap) e validar seus escopos pelo filtro igreja_erp_rbac_contexto_autorizado (decisão, escopo, contexto, usuário WordPress, membro e ação). Contextos externos são negados por padrão. A sincronização dos perfis preserva capabilities dos add-ons.

## Sequência da entrega

| Etapa | Escopo |
| --- | --- |
| 1 — atual | Bootstrap, banco, RBAC e atualização WordPress |
| 2 | Serviços de membros/famílias, departamentos/equipes, centros de custo e EventBus |
| 3 | Portal mobile, views e assets |
| 4 | REST, seed, PDF, WhatsApp, configurações e exemplo de add-on |

Add-ons devem aguardar igreja_erp_core_loaded e conferir a versão mínima e as classes necessárias. A versão 0.1.0 ainda não oferece os serviços da Etapa 2.

## Desenvolvimento

O workflow valida sintaxe PHP e integração com WordPress real e MySQL 8. Os cenários cobrem migração, idempotência, índices, isolamento de permissões, REST, nonces e atualização pelo Plugin_Upgrader com o mesmo ZIP da release. Os testes só rodam com IGREJA_ERP_TEST_ENV=1 em banco descartável.

Use python3 scripts/package.py para gerar o ZIP. O pacote inclui apenas bootstrap, classes, README e licença. O job de publicação só cria uma release em main depois que os testes passam.

Para publicar uma versão, incremente o cabeçalho, a constante e update.json. A versão do esquema ou do RBAC só muda quando seu respectivo contrato mudar.
