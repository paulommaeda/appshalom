Igreja ERP Core 0.3.0 — Membros, famílias e cadastro público.

- Lista de membros com pesquisa, filtros, paginação e edição dos dados pessoais, contatos e vida na igreja.
- Cadastro de famílias, endereço compartilhado e vínculo por parentesco.
- Acesso para administrador WordPress, administrador pastoral e secretaria.
- Formulário público independente do tema, mantendo o restante do site privado.
- Cloudflare Turnstile ao final do formulário, obrigatório e validado no servidor.
- Chaves configuráveis por igreja; segredo não aparece no formulário ou nas respostas públicas.
- Envios entram como Visitante, com aceite registrado, sem criar conta ou vincular família automaticamente.
- Proteção de repetição, limite de tentativas, nonce e campo armadilha.
- Serviços de membros/famílias disponíveis para addons, com permissões e isolamento por site.

Configure Site key e Secret key em Igreja ERP → Cadastro público para liberar os envios.
Requer WordPress 6.5+ e PHP 8.1+. Mantém banco 1.0.0, dados, identidade e atualizador nativo existentes.

Esta versão é proposta na branch de desenvolvimento; o workflow só publica a release após integração em main e aprovação de todos os testes.
