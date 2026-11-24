# Addon de membros e famílias

Implementação na branch `chatgpt/cadastro-membros`. Core 0.4.0 e addon Igreja ERP — Membros 0.1.0 são ZIPs separados, com versões e atualizações próprias. A integração em main passa pelo pull request.

## Uso

- **Igreja ERP → Membros:** pesquisa por nome, apelido, e-mail ou WhatsApp; filtro de situação; paginação; criação e edição dos dados pessoais, contato e vida na igreja.
- **Igreja ERP → Famílias:** cadastro e edição do endereço compartilhado e das observações; visualização dos integrantes. No membro, procure a família pelo nome, selecione e informe o parentesco. É possível desvincular sem apagar a pessoa.
- **Igreja ERP → Cadastro público:** link para compartilhar, seleção de campos e Turnstile opcional.
- **Igreja ERP → Addons:** catálogo e ativação/desativação via gerenciador nativo de plugins. Instale o ZIP do addon após atualizar o Core. Desativar não exclui registros ou opções.
- Administrador WordPress, administrador pastoral e secretaria gerenciam membros/famílias. Apenas os dois primeiros configuram o cadastro público. Membros comuns não podem listar os demais cadastros.

## Formulário público

A URL é gerada com `home_url('/')` + `?igreja_cadastro=1`, inclusive em Multisite e instalações em subdiretório. Esse endereço entrega uma página independente do tema. Não libera busca, feeds, páginas internas, REST ou `admin-post.php`.

O formulário pede nome e ao menos um contato, permite dados pessoais opcionais e exige aceite do uso dos dados. Não pede senha nem cria usuário WordPress. Todo envio entra como **Visitante**: a secretaria confere, altera a situação e faz a vinculação familiar. Não há aprovação automática nem autorização de acesso. Cadastros repetidos com tokens distintos ainda podem ser enviados e devem ser conferidos pela secretaria.

No Cloudflare, crie um widget **Turnstile / Managed**, autorize o hostname exibido no painel e copie a **Site key** e a **Secret key** para o cadastro público. Não publique o segredo no GitHub. O segredo fica em opção não autoload do site; no formulário administrativo, campo vazio preserva o segredo e a opção de remoção o apaga. Sem Cloudflare, o formulário continua disponível e recebe dados com as proteções básicas. Habilitar o Turnstile no painel exige as duas chaves; falhas da validação impedem o envio sem ocultar os campos. Desabilitar o formulário retorna HTTP 503; desativar o addon remove a rota pública e restaura a exigência de login.

Quando habilitado, a confirmação do CAPTCHA ocorre no servidor, em `https://challenges.cloudflare.com/turnstile/v0/siteverify`: sucesso, hostname e action `member_registration` precisam corresponder. Há proteção de repetição por token, limite de 20 verificações a cada 10 minutos por IP de conexão, campo armadilha e nonce. Com Turnstile habilitado, indisponibilidade do Cloudflare bloqueia o envio. Com Turnstile desabilitado, o limite de tentativas continua ativo e nenhuma requisição ao Cloudflare é feita. IPs encaminhados por cabeçalhos não confiáveis são ignorados; atrás de proxy, configure o servidor para restaurar o IP real antes do PHP. Ambientes locais de teste usam respostas simuladas exclusivamente em `tests/`; o plugin não contém chave de teste ou modo de ignorar CAPTCHA.

O cadastro público registra aceite, data e IP no servidor, usa PRG após o envio e não inclui dados pessoais na URL. Sem cache e sem indexação. O texto de finalidade aparece antes do aceite; a igreja deve revisar seus procedimentos de cadastro e atendimento das solicitações.

## Serviços para addons

`IgrejaERP\Core\Membros` mantém o namespace publicado por compatibilidade e utiliza as tabelas do site atual. As classes agora são fornecidas pelo addon; verifique `did_action('igreja_erp_membros_loaded')` antes de usá-las. Não recebe tenant por parâmetro.

| Método | Retorno e autorização |
| --- | --- |
| `get_by_id(int $id)` | objeto ou null; permissão global ou próprio membro |
| `get_by_wp_user_id(int $id)` | objeto ou null; próprio usuário ou permissão global |
| `get_current_membro()` | próprio membro ou null |
| `get_familia(int $id)` | `['familia' => objeto, 'membros' => objetos]`; exige visualizar famílias; integrantes exigem visualizar membros |
| `listar_membros(array $filtros = [])` | `items`, `total`, `page`, `per_page`, `pages`; exige visualizar membros |
| `listar_familias(string $busca = '', int $pagina = 1)` | `items`, `total`, `page`, `pages`; exige visualizar famílias |
| `salvar_membro(array $dados)` | ID ou WP_Error; exige gerenciar membros; `id` ausente/0 cria; update parcial preserva campos omitidos |
| `salvar_familia(array $dados)` | ID ou WP_Error; exige gerenciar famílias; nome obrigatório |
| `cadastrar_visitante(array $dados, string $token)` | ID ou WP_Error; somente inclusão com campos públicos permitidos, contato quando exibido, aceite e CAPTCHA quando habilitado |

Filtros de membros: `busca`, `status`, `familia_id`, `departamento_id`, `page`, `per_page` (máximo 100). CPF é normalizado e validado; CPF e conta WP vazios ficam NULL. Vínculos verificam o site atual. Vincular `wp_user_id` não muda senha, role ou permissões. Campos internos JSON, foto e evidência de aceite não são editados por essa rotina.

Hooks após persistência: `igreja_erp_membro_salvo($id, $blog_id)` e `igreja_erp_familia_salva($id, $blog_id)`. Addons devem utilizar permissões próprias para operações adicionais. Jobs sem usuário autenticado devem definir explicitamente um usuário de serviço autorizado.

## Validação e publicação

O workflow existente valida PHP 8.1 / WordPress 6.5.5, PHP 8.3 / WordPress atual e Multisite. Executa migrações, RBAC, configurações, os testes de membros/Turnstile e navegador mobile/desktop. A simulação HTTP do Turnstile é instalada somente no WordPress descartável de CI, nunca no ZIP do plugin.

O pacote continua usando o atualizador nativo já existente. Push nesta branch não publica release. Core 0.4.0 e Membros 0.1.0 estão preparados neste PR, com seus metadados e notas. A versão estável em `main` permanece intacta até a integração; o workflow recusa substituir um ZIP já publicado com conteúdo diferente.

## Campos do cadastro público

Selecione os campos no painel: nome social/apelido, nascimento, e-mail, WhatsApp, profissão, sexo e estado civil. Nome completo e aceite são fixos e obrigatórios. Se nenhum contato estiver habilitado, é possível enviar somente nome e aceite. Se algum contato estiver habilitado, pelo menos um dos contatos exibidos precisa ser preenchido. Campos desabilitados são ignorados no servidor e não apagam dados existentes. O formulário administrativo mantém todos os campos.

## Integração de novos addons

Cada addon é um plugin WordPress em sua própria pasta, declara a dependência do Core e verifica a versão mínima antes de carregar seus recursos. Registre o catálogo pelo filtro `igreja_erp_addons` (nome, arquivo do plugin e descrição). A base não ativa addons automaticamente. Rotas públicas independentes podem ser registradas por plugins ativos com `Access::register_public_route($matches, $render)`: o teste deve reconhecer apenas a rota própria e o renderizador deve encerrar a resposta. O Core não libera páginas, feeds ou REST por uma flag genérica.

O Core consulta o atualizador do addon Membros instalado mesmo quando ele está desativado. Releases do Core usam `vX.Y.Z`; Membros usa `membros-vX.Y.Z`. Os dois ZIPs são produzidos pelo mesmo workflow, sem incluir os testes. No Multisite, um addon ativo na rede atende todos os sites; ative individualmente quando desejar escolher quais igrejas terão o recurso.
