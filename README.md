# App Shalom — Igreja ERP Core

Repositório: https://github.com/paulommaeda/appshalom

## Versão 0.6.0 — Endereço de membros e consulta ao CEP

O Core entrega banco, permissões, atualização WordPress, identidade por igreja e acesso privado. Membros, famílias e formulário público ficam no plugin independente **Igreja ERP — Membros 0.3.0**, ativável em Plugins ou Igreja ERP → Addons.

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
4. Para os cadastros, instale também o ZIP igreja-erp-membros-0.3.0.zip e ative o addon.
5. Personalize em Igreja ERP → Configurações.
6. Para novas versões, use Painel → Atualizações ou Verificar atualizações na linha de cada plugin. As versões do Core e dos addons são independentes.

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

Cinco tabelas com dbDelta e esquema 1.2.0. Relações são lógicas; os serviços de membros e famílias validam os vínculos no site atual. CPF e wp_user_id ausentes devem ser NULL. Migrações verificam colunas e índices antes de avançar a versão, preservam dados e têm trava de concorrência.

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
| Addon Membros 0.3.0 / Core 0.4.0 | Plugin independente de membros/famílias e formulário público configurável com Turnstile opcional |
| 2 — restante | Serviços de departamentos/equipes, centros de custo e EventBus |
| 3 | Portal mobile, views e assets dos cadastros |
| 4 | REST dos módulos, seed, PDF, WhatsApp e exemplo de add-on |

A gestão de cadastros usa o painel WordPress nesta versão. O portal dos membros e os add-ons serão desenvolvidos nas próximas etapas. Add-ons devem aguardar igreja_erp_core_loaded e conferir as classes e a versão mínima de que precisam.

## Desenvolvimento

Classes no namespace IgrejaERP\Core usam um mapa explícito para preservar os nomes class-*.php solicitados.

O workflow valida PHP, pacote determinístico, migrações, RBAC, isolamento Multisite, autenticação e interface em navegador. Testes usam apenas banco descartável e contas fictícias e exigem IGREJA_ERP_TEST_ENV=1.

Use python3 scripts/package.py para gerar o ZIP. Inclui bootstrap, classes, views, assets e licenças; não inclui testes. A release é criada em main somente depois das verificações.

Para publicar, incremente o cabeçalho, a constante de versão, update.json e as notas. Incremente o esquema ou a versão RBAC somente quando seus respectivos contratos mudarem.
## Cadastro de membros e famílias

O addon **Igreja ERP — Membros** oferece listagem e edição de membros, vínculos familiares, situação na igreja e formulário público. Configure em **Igreja ERP → Cadastro público**, inclusive a seleção dos campos exibidos e o uso opcional do Turnstile. Sem chaves, o formulário funciona com proteção básica. Ao habilitar o CAPTCHA, as duas chaves e a validação do servidor são obrigatórias nos envios. Novos cadastros públicos entram como visitantes e não criam contas de acesso.

Desativar o addon remove suas telas e a rota pública, preservando os cadastros e as opções. O Core continua funcionando. Atualizar do Core 0.3.0 para 0.4.0 exige instalar o addon para voltar a acessar os cadastros; os dados permanecem nas mesmas tabelas.

Veja [uso, permissões, segurança e serviços para addons](docs/CADASTRO-MEMBROS.md).

## Importação, exportação e acesso dos membros

Em Igreja ERP → Importar / exportar, baixe configurações JSON ou membros CSV UTF-8 para Excel. A importação mostra uma prévia antes de gravar. JSON não contém segredos ou tenant; a logo é transferida por URL. Importar configurações do formulário preserva as chaves Cloudflare já existentes no destino.

Com o addon Membros 0.3.0 ativo, importe CSV de até 1.000 registros/2 MB, utilizando as colunas do arquivo exportado. Código da mesma igreja, CPF ou nome/e-mail/nascimento coincidentes identificam atualizações; IDs externos não são reutilizados. A importação é transacional e não envia convites. Famílias são associadas pelo nome e criadas quando ausentes.

Novos membros ficam pendentes. Em Membros, filtre Pendentes e aprove individualmente ou até 20 por vez. É necessário um e-mail válido e exclusivo para criar o usuário WordPress. A conta recebe apenas erp_membro e uma senha aleatória; o convite não contém essa senha. O usuário define sua senha pelo link nativo do WordPress, com a identidade da igreja no e-mail e na tela de entrada. E-mails de contas existentes não causam vinculação automática nem alteração de privilégios.

O login tenta nome.último-sobrenome, depois os nomes anteriores, ignorando de/da/do/dos/das/e. Caso todas as combinações existam, recebe sufixo numérico. Aprovações repetidas preservam conta e convite; Reenviar convite gera um novo link. O estado enviado indica que wp_mail aceitou o envio, sem comprovar entrega à caixa postal. Falhas ficam visíveis para nova tentativa. O envio usa a configuração de e-mail/SMTP do próprio WordPress.

## Endereço dos membros

A ficha administrativa e o formulário público incluem CEP, rua/avenida, número, complemento, bairro, cidade e UF. O endereço é individual e não altera o endereço compartilhado da família. Os campos também fazem parte do CSV de importação/exportação.

No painel e no público, ao informar os oito números do CEP, o navegador consulta https://viacep.com.br e preenche rua, bairro, cidade e UF quando disponíveis. Todos os campos permanecem editáveis; número e complemento são manuais. Se a consulta falhar ou o CEP não existir, preencha manualmente. Não é necessária chave de API e não são enviados dados do membro ao serviço, apenas o CEP. Consultas antigas são descartadas ao trocar o CEP e respostas não sobrescrevem alterações feitas durante a consulta.

Os campos de endereço podem ser ocultados nas configurações do formulário público e os campos ocultos são ignorados no servidor. Ao atualizar uma configuração antiga, os novos campos de endereço ficam disponíveis, preservando as escolhas existentes dos demais campos.
