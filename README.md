# App Shalom — Igreja ERP Core

Repositório: https://github.com/paulommaeda/appshalom

## Versão 0.2.0 — Identidade por igreja e acesso restrito

O Core entrega banco, permissões, atualização WordPress e, agora, configurações próprias da igreja. Esta versão antecipa a configuração institucional para preparar o produto como SaaS, antes de concluir as próximas etapas funcionais.

### Configurações da igreja

Em **Igreja ERP → Configurações**, administradores WordPress e o perfil ERP Administração pastoral podem editar:

- Nome completo e nome de apresentação.
- Logo por upload na biblioteca ou URL.
- Cores principal, destaque, fundo e texto, com prévia ao vivo.
- Mensagem da tela de entrada.
- Site, e-mail, telefone e endereço.
- Opção de acesso restrito, habilitada por padrão.

A primeira configuração usa **CCShalom Curitiba**, com azul #000A52, azul #001BE1 e branco, conforme o guia de identidade fornecido. A logo inicial utiliza a imagem do site oficial em https://ccshalom.com.br/curitiba/wp-content/uploads/sites/3/2024/09/logo-rodape.png. Nome, contato e endereço foram conferidos no site oficial da igreja. CEP não confirmado permanece vazio.

O login recebe a identidade configurada. Autenticação e recuperação de senha permanecem nativas do WordPress. Montserrat é incluída localmente com sua licença SIL OFL.

Atualizações e reativações preservam os dados e a opção de acesso escolhidos pela igreja.

## Instalação e atualização

Requer **WordPress 6.5+**, **PHP 8.1+** e MySQL/MariaDB compatível com WordPress.

1. Baixe o ZIP instalável em Releases.
2. Use Plugins → Adicionar plugin → Enviar plugin e ative Igreja ERP Core.
3. O site passa a exigir login e as configurações iniciais são preparadas.
4. Personalize em Igreja ERP → Configurações.
5. Para novas versões, use Painel → Atualizações ou Verificar atualizações na linha do plugin.

A atualização 0.1.0 → 0.2.0 também prepara as configurações e habilita o acesso restrito em instalações ainda sem essas configurações.

Use o ZIP da release, que contém a pasta igreja-erp-core/. O Download ZIP do repositório usa outro nome de pasta, incompatível com o slug esperado pelos add-ons.

O atualizador consulta main/update.json por HTTPS e aceita pacotes versionados das releases deste repositório público. Não precisa de token GitHub. O controle nativo do WordPress permite habilitar instalação automática. A verificação manual limpa o cache e exige permissão e nonce.

## Restrição de acesso

Quando habilitada:

- Visitantes são encaminhados ao login ao abrir páginas, buscas, autores ou feeds.
- REST retorna 401 para visitantes, preservando a autenticação por cookie com nonce e Application Passwords.
- Chamadas anônimas de AJAX e admin-post são bloqueadas.
- XML-RPC não fica disponível; integrações autenticadas utilizam REST.
- Cadastro público e sitemap ficam desabilitados e robots indica Disallow.
- As respostas WordPress recebem no-store, cabeçalhos de conteúdo privado e noindex.
- No Multisite, uma sessão global não basta: o usuário precisa pertencer ao site da igreja atual. Superadministradores preservam seu acesso.

A restrição não altera a senha ou o cadastro dos usuários existentes e só vale enquanto o Core estiver ativo. Não há exclusão de dados ao desativar.

**Limite de infraestrutura:** o PHP protege respostas geradas pelo WordPress. Arquivos já públicos por URL direta em uploads e caches de página servidos antes do WordPress precisam de proteção e limpeza no servidor/CDN. Logo, CSS e fontes da entrada precisam ser públicos para a autenticação funcionar. Documentos privados dos próximos módulos deverão utilizar armazenamento e entrega autenticados, em vez de links públicos da biblioteca.

## Base SaaS

O modelo atual usa uma igreja por instalação WordPress ou por site do Multisite:

- Configuração em wp_options do site atual, nunca em uma opção global da rede.
- Identificador persistente igreja_erp_tenant_id criado por site.
- Tabelas usam o prefixo do site atual.
- Nenhum campo da requisição escolhe o tenant.
- A API de configuração é IgrejaERP\Core\Settings::all(), get(), tenant_id(), logo_url() e css_variables().
- Provisionadores podem definir o primeiro preset pelo filtro igreja_erp_initial_settings($preset, $blog_id), antes da criação/ativação do site.

Este fundamento não inclui planos, cobrança, licença comercial ou provisionamento automático de assinantes. Clone de instalação deve receber um novo identificador no provisionamento, antes de ser associado a uma igreja diferente.

## Banco e permissões

| Tabela | Índices e relações |
| --- | --- |
| erp_familias | ID e nome da família |
| erp_membros | CPF e usuário WordPress únicos e anuláveis; família, status, WhatsApp e nome indexados |
| erp_centros_custo | Código único; responsável e tipo/ativo indexados |
| erp_departamentos | Slug único; líder, vice, centro de custo e ativo indexados |
| erp_departamento_equipe | Único em departamento + membro + função; índices por membro e departamento/ativo |

Cinco tabelas com dbDelta e esquema 1.0.0. Relações são lógicas; a camada de serviços da próxima etapa validará os vínculos e as exclusões. CPF e wp_user_id ausentes devem ser NULL. Migrações verificam colunas e índices antes de avançar a versão, preservam dados e têm trava de concorrência.

| Perfil | Permissões do Core |
| --- | --- |
| erp_admin_pastor | Gestão do Core, configuração da igreja, upload da logo e gestão dos cadastros/financeiro |
| erp_secretaria | Gestão de membros, famílias, departamentos e equipes; consulta de centros de custo |
| erp_administrativo | Gestão de centros de custo e financeiro |
| erp_lider_depto | Gestão das equipes em que é líder, vice ou coordenador ativo |
| erp_lider_pg | Portal e próprio perfil; PG será definido pelo add-on |
| erp_professor_curso | Portal e próprio perfil; cursos serão definidos pelo add-on |
| erp_membro | Portal e próprio perfil |

Administradores WordPress recebem as capabilities do Core. Perfis ERP não recebem manage_options. Apenas o perfil pastoral recebe upload_files para gerenciar sua logo.

RBAC::usuario_pode() exige capability global ou capability local com vínculo ao objeto. Ações desconhecidas são negadas. RBAC::rest_permission_check() usa IDs da rota; corpo e query não substituem o objeto.

Add-ons podem registrar ações pelo filtro igreja_erp_rbac_acoes e validar seus escopos em igreja_erp_rbac_contexto_autorizado. Capabilities externas são preservadas ao sincronizar perfis.

## Etapas funcionais

| Etapa | Escopo |
| --- | --- |
| 1 — concluída | Bootstrap, banco, RBAC e atualização WordPress |
| Atualização 0.2.0 | Configuração institucional, identidade e acesso restrito por igreja |
| 2 — próxima | Serviços de membros/famílias, departamentos/equipes, centros de custo e EventBus |
| 3 | Portal mobile, views e assets dos cadastros |
| 4 | REST dos módulos, seed, PDF, WhatsApp e exemplo de add-on |

O portal de cadastros e os add-ons ainda não são entregues nesta versão. Add-ons devem aguardar igreja_erp_core_loaded e conferir as classes e a versão mínima de que precisam.

## Desenvolvimento

Classes no namespace IgrejaERP\Core usam um mapa explícito para preservar os nomes class-*.php solicitados.

O workflow valida PHP, pacote determinístico, migrações, RBAC, isolamento Multisite, autenticação e interface em navegador. Testes usam apenas banco descartável e contas fictícias e exigem IGREJA_ERP_TEST_ENV=1.

Use python3 scripts/package.py para gerar o ZIP. Inclui bootstrap, classes, views, assets e licenças; não inclui testes. A release é criada em main somente depois das verificações.

Para publicar, incremente o cabeçalho, a constante de versão, update.json e as notas. Incremente o esquema ou a versão RBAC somente quando seus respectivos contratos mudarem.
