# Cadastro de membros e famílias

Implementação na branch `chatgpt/cadastro-membros`. A integração em `main` deve passar pela revisão do pull request, preservando o trabalho do outro desenvolvedor.

## Uso

- **Igreja ERP → Membros:** pesquisa por nome, apelido, e-mail ou WhatsApp; filtro de situação; paginação; criação e edição dos dados pessoais, contato e vida na igreja.
- **Igreja ERP → Famílias:** cadastro e edição do endereço compartilhado e das observações; visualização dos integrantes. No membro, procure a família pelo nome, selecione e informe o parentesco. É possível desvincular sem apagar a pessoa.
- **Igreja ERP → Cadastro público:** link pronto para compartilhar, habilitação e chaves Cloudflare Turnstile.
- Administrador WordPress, administrador pastoral e secretaria gerenciam membros/famílias. Apenas os dois primeiros configuram o cadastro público. Membros comuns não podem listar os demais cadastros.

## Formulário público

A URL é gerada com `home_url('/')` + `?igreja_cadastro=1`, inclusive em Multisite e instalações em subdiretório. Esse endereço entrega uma página independente do tema. Não libera busca, feeds, páginas internas, REST ou `admin-post.php`.

O formulário pede nome e ao menos um contato, permite dados pessoais opcionais e exige aceite do uso dos dados. Não pede senha nem cria usuário WordPress. Todo envio entra como **Visitante**: a secretaria confere, altera a situação e faz a vinculação familiar. Não há aprovação automática nem autorização de acesso. Cadastros repetidos com tokens distintos ainda podem ser enviados e devem ser conferidos pela secretaria.

No Cloudflare, crie um widget **Turnstile / Managed**, autorize o hostname exibido no painel e copie a **Site key** e a **Secret key** para o cadastro público. Não publique o segredo no GitHub. O segredo fica em opção não autoload do site; no formulário administrativo, campo vazio preserva o segredo e a opção de remoção o apaga. Sem as duas chaves, ou se desabilitado, o formulário retorna HTTP 503 e não recebe dados.

A confirmação ocorre no servidor, em `https://challenges.cloudflare.com/turnstile/v0/siteverify`: sucesso, hostname e action `member_registration` precisam corresponder. Há proteção de repetição por token, limite de 20 verificações a cada 10 minutos por IP de conexão, campo armadilha e nonce. Indisponibilidade do Cloudflare bloqueia o envio. IPs encaminhados por cabeçalhos não confiáveis são ignorados; atrás de proxy, configure o servidor para restaurar o IP real antes do PHP. Ambientes locais de teste usam respostas simuladas exclusivamente em `tests/`; o plugin não contém chave de teste ou modo de ignorar CAPTCHA.

O cadastro público registra aceite, data e IP no servidor, usa PRG após o envio e não inclui dados pessoais na URL. Sem cache e sem indexação. O texto de finalidade aparece antes do aceite; a igreja deve revisar seus procedimentos de cadastro e atendimento das solicitações.

## Serviços para addons

`IgrejaERP\Core\Membros` utiliza as tabelas do site atual. Não recebe tenant por parâmetro.

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
| `cadastrar_visitante(array $dados, string $token)` | ID ou WP_Error; somente inclusão com campos públicos permitidos, contato, aceite e CAPTCHA obrigatório |

Filtros de membros: `busca`, `status`, `familia_id`, `departamento_id`, `page`, `per_page` (máximo 100). CPF é normalizado e validado; CPF e conta WP vazios ficam NULL. Vínculos verificam o site atual. Vincular `wp_user_id` não muda senha, role ou permissões. Campos internos JSON, foto e evidência de aceite não são editados por essa rotina.

Hooks após persistência: `igreja_erp_membro_salvo($id, $blog_id)` e `igreja_erp_familia_salva($id, $blog_id)`. Addons devem utilizar permissões próprias para operações adicionais. Jobs sem usuário autenticado devem definir explicitamente um usuário de serviço autorizado.

## Validação e publicação

O workflow existente valida PHP 8.1 / WordPress 6.5.5, PHP 8.3 / WordPress atual e Multisite. Executa migrações, RBAC, configurações, os testes de membros/Turnstile e navegador mobile/desktop. A simulação HTTP do Turnstile é instalada somente no WordPress descartável de CI, nunca no ZIP do plugin.

O pacote continua usando o atualizador nativo já existente. Push nesta branch não publica release. A versão 0.3.0 está preparada neste PR, com seus metadados e notas. A versão estável em `main` permanece intacta até a integração; o workflow recusa substituir um ZIP já publicado com conteúdo diferente.
