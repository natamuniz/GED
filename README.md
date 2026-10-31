# GED — gestão eletrônica de documentos

Projeto pessoal de estudo de backend. API Laravel 13, PHP 8.4 e PostgreSQL 17, com frontend separado em Vue 3, TypeScript e Vite. O ambiente de desenvolvimento roda em Docker.

## Iniciar no Windows

Pré-requisito: Docker Desktop em execução, configurado para containers Linux. PHP, Composer e PostgreSQL locais não são necessários.

Na raiz do projeto, execute no PowerShell:

```powershell
./scripts/setup.ps1
docker compose exec app php artisan ged:create-admin
```

O setup cria os arquivos de ambiente quando ausentes, gera uma senha local do PostgreSQL, instala as dependências, gera a chave da aplicação quando ausente, aplica migrations/seeders e inicia API, worker e frontend. Não sobrescreve um `.env` existente, não recria o banco e não gera conta ou senha padrão de usuário.

- API: http://localhost:8000/api/v1
- Saúde: http://localhost:8000/up
- Frontend: http://localhost:5173

Os serviços usam a rede interna do Docker. A API fica disponível apenas no endereço de loopback da máquina; o PostgreSQL não publica porta no host. Para clientes de banco, use `docker compose exec postgres psql -U ged -d ged` ou configure conscientemente um mapeamento de porta local.

## Comandos diários

```powershell
docker compose up -d
docker compose logs -f app worker frontend
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app composer check-platform-reqs
docker compose exec frontend npm test
docker compose exec frontend npm run build
docker compose down
```

`docker compose down` preserva o volume do banco. Não use a opção `-v` se quiser preservar os dados. Arquivos privados ficam em `backend/storage/app/private`, preservados no bind mount do projeto. Backup exige tanto banco quanto arquivos.

## Inicialização manual em outros sistemas

Copie `.env.example` para `.env` e `backend/.env.example` para `backend/.env`. Defina uma senha local em `POSTGRES_PASSWORD` no primeiro arquivo e execute:

```sh
docker compose build app worker frontend
docker compose up -d --wait postgres
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose up -d --wait app worker
docker compose run --rm frontend npm ci
docker compose up -d --wait frontend
docker compose exec app php artisan ged:create-admin
```

Gere a chave apenas na primeira instalação. O Compose injeta as credenciais do banco nos processos da aplicação e do worker; não é necessário duplicar a senha no ambiente do backend.

## Implementação inicial

- 14 tabelas de domínio com chaves estrangeiras, índices e restrições PostgreSQL.
- Modelos e relacionamentos para unidades, usuários, permissões, documentos, versões, verificações, compartilhamentos e auditoria.
- Seeders dos perfis Administrador, Gestor, Colaborador e Leitor e do catálogo de permissões.
- Permissões herdadas do perfil, concessões individuais e bloqueios individuais, com prioridade ao bloqueio.
- Abrangência de unidades calculada por consulta recursiva; herança apenas para descendentes.
- Autenticação por sessão e Laravel Sanctum, com CORS para o frontend Vue separado.
- Cadastro de documento com versão inicial e SHA-256; compensação de arquivos quando a transação falha.
- Listagem e consulta com autorização, filtros, paginação e download privado auditado.
- Novas versões com bloqueio transacional do documento para atribuição do número.
- Verificação de integridade por job, diferenciando correspondência, divergência e falha de leitura.
- Dashboard com totais por abrangência, incluindo armazenamento de todas as versões.
- Administração de usuários, perfis, permissões e unidades em `/api/v1/admin`, com auditoria, exceções individuais, proteção do último administrador e serialização das alterações no PostgreSQL.
- Compartilhamento temporário por versão, com destinatários autenticados, agendamento, expiração, revogação e histórico de acessos. Tokens são retornados apenas na criação e persistidos somente como hash.
- Edição parcial de metadados, alteração controlada da unidade produtora, arquivamento e restauração com auditoria transacional, sem alteração dos arquivos ou hashes existentes.
- Consulta paginada da auditoria por documento e de todos os eventos autorizados, com filtros, controle de abrangência e detalhe individual.

Os seeders preservam alterações feitas nas permissões dos perfis existentes. Novas permissões adicionadas ao catálogo não serão automaticamente concedidas a perfis já existentes.

## Endpoints disponíveis

Todos usam o prefixo `/api/v1`.

| Método | Rota | Finalidade |
|---|---|---|
| POST | `/auth/login` | Iniciar sessão |
| POST | `/auth/logout` | Encerrar sessão |
| GET | `/auth/me` | Usuário, unidades e permissões efetivas |
| GET | `/dashboard` | Indicadores do acervo acessível |
| GET | `/units` | Unidades ativas acessíveis |
| GET | `/document-types` | Tipos ativos |
| GET | `/documents` | Listagem paginada |
| POST | `/documents` | Documento e arquivo inicial, multipart |
| GET | `/documents/{document}` | Metadados e versões |
| PATCH | `/documents/{document}` | Editar metadados |
| PATCH | `/documents/{document}/producing-unit` | Alterar unidade produtora |
| POST | `/documents/{document}/archive` | Arquivar |
| POST | `/documents/{document}/restore` | Restaurar |
| POST | `/documents/{document}/versions` | Nova versão, multipart |
| GET | `/versions/{version}/download` | Download autorizado |
| POST | `/versions/{version}/integrity-checks` | Agendar verificação, HTTP 202 |
| GET | `/versions/{version}/integrity-checks` | Histórico paginado de verificações |
| GET | `/audit-events` | Listar eventos visíveis ao usuário |
| GET | `/audit-events/{event}` | Consultar um evento autorizado |
| GET | `/documents/{document}/audit-events` | Histórico de auditoria do documento |

Filtros da listagem: `search`, `document_type_id`, `producing_unit_id`, `production_date_from`, `production_date_to`, `archived` (0 ou 1) e `page`. Por padrão, mostra documentos ativos. Compartilhamentos não ampliam a consulta normal nem o dashboard.

Os filtros de produção usam a data do documento, não a data de upload: `GET /api/v1/documents?production_date_from=2026-10-01&production_date_to=2026-10-31`. Ambos os limites são inclusivos e aceitam AAAA-MM-DD. É possível informar apenas o início ou apenas o fim; campos vazios são ignorados. Datas inválidas ou fim anterior ao início retornam 422. Como `production_date` é uma data sem horário, não há conversão de fuso. O período combina com os outros filtros e com a abrangência do usuário, inclusive ao consultar arquivados. Os links de paginação preservam os parâmetros.

Cadastro multipart: `title`, `document_type_id`, `production_date` (AAAA-MM-DD), `file`, e opcionalmente `producing_unit_id`, `description`, `author`, `interested_party`, `reference_number`. Omitir unidade torna o documento geral e exige permissão específica. Não se aceitam IDs de criador, hash ou número de versão enviados pelo cliente.

Upload inicial limitado a 100 MiB. Formatos: PDF, DOCX, XLSX, CSV, MP3, MP4, EML e MSG. Extensão e MIME detectado são validados; formatos contêiner podem apresentar MIME genérico. Esta validação não interpreta completamente o conteúdo nem substitui análise antimalware. Arquivos são privados e entregues como anexos, sem visualização inline nesta etapa.

## Manutenção de documentos

As operações abaixo usam o prefixo `/api/v1`, sessão autenticada, usuário ativo e as permissões efetivas dentro da abrangência organizacional.

PATCH `/documents/{document}` altera somente os metadados presentes no corpo:

```json
{
  "title": "Contrato atualizado",
  "description": null,
  "document_type_id": 2,
  "production_date": "2026-10-31",
  "author": "Organização ABC",
  "interested_party": "Empresa XYZ",
  "reference_number": "CT-015/2026"
}
```

Exige `documents.update`. Campos ausentes são preservados; campos opcionais podem ser limpos com `null`. Título, tipo e data de produção não podem ser vazios. Envie ao menos um dos sete metadados aceitos. Novos tipos devem estar ativos; o tipo atual pode ser mantido mesmo se tiver sido desativado. Arquivo, unidade produtora, responsáveis e campos de arquivamento não podem ser alterados nesse endpoint. Metadados não criam novas versões nem alteram os hashes dos arquivos.

PATCH `/documents/{document}/producing-unit` altera a unidade, exigindo `documents.move` e acesso à unidade de origem e à de destino:

```json
{"producing_unit_id": 4}
```

O campo é obrigatório; o destino deve ser uma unidade ativa. Para tornar um documento de acesso geral, envie explicitamente `{"producing_unit_id": null}`. Tanto tornar geral quanto restringir um documento geral exigem também `documents.manage-general`. Depois da mudança, as consultas passam a respeitar a nova unidade. Links existentes permanecem registrados, mas deixam de conceder acesso enquanto o criador não tiver autorização sobre a nova unidade.

POST `/documents/{document}/archive` e POST `/documents/{document}/restore` não exigem corpo. Ambos exigem `documents.archive` e acesso normal ao documento. A manutenção de documentos gerais, incluindo edição, arquivamento e restauração, exige também `documents.manage-general`.

Arquivar registra responsável e data; preserva arquivos, versões, hashes, consulta, download, verificações de integridade e links existentes. Bloqueia edição dos metadados, troca de unidade, novas versões e novos compartilhamentos até a restauração. Restaurar limpa `archived_at` e `archived_by`; os dados anteriores permanecem na auditoria. Documentos arquivados ficam fora da listagem padrão, podem ser consultados com `archived=1` e continuam nos indicadores de armazenamento.

As quatro operações retornam HTTP 200 com `data`, contendo o documento, tipo, unidade e versão atual. Operações sem alteração efetiva não geram evento nem atualizam responsável ou data de alteração; repetir arquivamento/restauração é seguro. Alterações reais registram `updated_by` e auditoria com valores anteriores e novos, dentro da mesma transação. Dados inválidos retornam 422; falta de permissão, abrangência ou tentativa de editar documento arquivado retornam 403; documento inexistente retorna 404.

O lock PostgreSQL coordena essas operações com mudanças administrativas e compartilhamentos. A inclusão de novas versões obtém o lock compartilhado antes de bloquear o documento e revalida o usuário, evitando a criação de versões com estado ou autorização desatualizados.

## Frontend Vue

O código fica em `frontend/`, separado do Laravel. Vue 3 com Composition API, TypeScript, Vue Router, Pinia e Axios; Vite executa o servidor de desenvolvimento. Node 24 e as dependências rodam no container `frontend`, sem depender do Node antigo instalado no Windows.

Telas disponíveis:

- `/login`: autenticação, validação por campo, opção de mostrar senha e recuperação de sessão.
- `/`: indicadores reais do dashboard, distribuição por tipo e abrangência organizacional.
- `/documentos`: busca, tipo, unidade, ativos/arquivados, período de produção, paginação, download e painel de metadados com histórico de versões e ações de manutenção.
- `/documentos/novo`: cadastro dos metadados, seleção explícita da unidade produtora e upload do arquivo original.
- `/documentos/:id/editar`: edição de metadados, preservando a unidade produtora e os arquivos.
- `/documentos/:id/nova-versao`: upload de uma nova versão, com observação opcional sobre as alterações.
- `/documentos/:id/versoes/:version/compartilhamentos`: seleção paginada de usuários, início opcional, expiração obrigatória, geração do link e histórico com revogação por versão.
- `/compartilhados`: compartilhamentos recebidos pelo usuário autenticado, com paginação e situação de acesso.
- `/compartilhado/:token` e `/compartilhamentos/:share`: consulta e download exclusivamente da versão compartilhada, inclusive entre unidades diferentes. O login preserva o destino do link.

Nos detalhes do documento, use **Gerenciar acessos** na versão desejada. Os horários usam o fuso do dispositivo e são enviados à API com fuso explícito. A busca de destinatários preserva a seleção entre páginas, com limite de 100 usuários. O link é exibido somente após a criação e deve ser copiado nesse momento; o token permanece apenas em memória nessa tela, sem armazenamento local. A interface não envia mensagens automaticamente. Destinatários também encontram o acesso em **Compartilhados comigo**. Ações e listagens respeitam `shares.create`, `shares.revoke-own` e `shares.revoke-any`; a criação também exige download e documento ativo. A API revalida autorização, destinatário e validade a cada consulta e download, e a interface apresenta acessos agendados, ativos, expirados, revogados e indisponíveis.

O envio aceita um arquivo de até 100 MB nos formatos PDF, DOCX, XLSX, CSV, MP3, MP4, EML e MSG, por seleção ou arrastar e soltar. A API valida o conteúdo e registra o SHA-256. O formulário mostra progresso, aguarda a confirmação do servidor e bloqueia envios duplicados. Falhas de validação preservam o preenchimento; sair com alterações solicita confirmação. Uploads não são repetidos automaticamente após falhas de rede. Acesso geral aparece somente com `documents.manage-general`; documentos arquivados não permitem manutenção.

Filtros e página ficam na URL; voltar/avançar no navegador recupera a consulta. Requisições de listagem antigas são canceladas ao trocar filtros. Erros, carregamento e acervo vazio têm estados próprios. Menus e downloads respeitam as permissões retornadas pela API; a autorização efetiva continua no Laravel.

A interface usa uma identidade de verde profundo e tons de papel, tipografia legível, foco de teclado e movimento reduzido. No celular, a listagem vira cartões, os filtros avançados são recolhíveis e o menu mantém foco e bloqueia o conteúdo ao fundo. Foram aplicadas as orientações das skills `emil-design-eng` e `mobile-native`, incluindo hover por capacidade, áreas de toque, inputs de 16px em ponteiros coarse, viewport dinâmico e safe areas. A inspeção responsiva no navegador não substitui a validação de teclado virtual, notch e gestos em aparelho físico.

O frontend usa `VITE_API_URL` (padrão `http://localhost:8000`). O Compose configura essa URL e as origens permitidas da API a partir de `APP_PORT` e `FRONTEND_PORT`. Após alterar portas/ambiente, recrie os serviços. Ao atualizar dependências, execute `docker compose run --rm frontend npm ci` para sincronizar o volume de `node_modules`. O comando `npm run build` verifica TypeScript e gera `frontend/dist`; o Docker atual continua sendo de desenvolvimento.

Para trabalhar sem Docker no frontend, use Node 22.12+ compatível com as dependências (o projeto é verificado com Node 24): copie `frontend/.env.example` para `frontend/.env`, execute `npm ci` e `npm run dev` dentro de `frontend/`.

As prévias em `docs/screenshots/` usam dados fictícios de validação. A fixture `scripts/seed-frontend-smoke.php` só funciona com o banco `ged_test` e o cookie de sessão `ged_qa_session`; não integra o setup normal. A validação em navegador utilizou containers temporários, origem `127.0.0.1` e um volume de arquivos separado. Esses containers/arquivos foram removidos ao terminar, preservando o banco e os arquivos principais.

## Autenticação do Vue

A configuração local usa o mesmo hostname `localhost`, com portas diferentes. No cliente HTTP, habilite cookies e envio do cabeçalho XSRF. Com Axios, configure `withCredentials: true` e `withXSRFToken: true`.

1. `GET http://localhost:8000/sanctum/csrf-cookie`.
2. `POST /api/v1/auth/login` com e-mail e senha, cookies e cabeçalho `X-XSRF-TOKEN`.
3. Consumir a API com cookies e cabeçalhos `Accept: application/json` e `Origin: http://localhost:5173`.
4. `POST /api/v1/auth/logout` para encerrar a sessão.

Não há emissão de JWT nem token salvo no localStorage. Para outros domínios, revise CORS, domínios stateful e configuração de cookies. A imagem atual usa o servidor de desenvolvimento do Laravel; a implantação de produção exigirá configuração própria de servidor HTTP, HTTPS e execução dos processos.

## Compartilhamento temporário

Rotas com prefixo `/api/v1`, todas exigindo usuário autenticado e ativo:

| Método | Rota | Finalidade |
|---|---|---|
| GET | `/share-recipients` | Buscar usuários ativos para selecionar destinatários; exige `shares.create` |
| POST | `/versions/{version}/shares` | Compartilhar uma versão específica |
| GET | `/versions/{version}/shares` | Listar links próprios daquela versão; quem pode revogar outros links vê todos dentro da abrangência |
| GET | `/shares/received` | Compartilhados comigo, com paginação e situação |
| GET | `/shared/{token}` | Consultar documento e versão pelo link |
| GET | `/shared/{token}/download` | Baixar a versão pelo link |
| GET | `/shares/{share}` | Consultar compartilhamento recebido por ID |
| GET | `/shares/{share}/download` | Download de compartilhamento recebido por ID |
| POST | `/shares/{share}/revoke` | Revogar antecipadamente; operação idempotente |

POST de criação:

```json
{
  "recipient_ids": [2, 3],
  "starts_at": "2026-11-01T09:00:00-03:00",
  "expires_at": "2026-11-02T18:00:00-03:00"
}
```

IDs e datas são ilustrativos. Informe de 1 a 100 usuários ativos, sem duplicações. `starts_at` é opcional e assume o momento atual. Datas exigem formato ISO 8601 com segundos inteiros e fuso, como nos exemplos (também aceita `Z` para UTC); a persistência usa UTC com precisão de segundos. A expiração precisa ser futura e posterior ao início. O período inclui o instante inicial e exclui o instante de expiração.

A resposta HTTP 201 contém `data` com o compartilhamento e, no nível superior, `token`, `access_url` e `download_url`. O token original não pode ser recuperado pelas listagens: copie o link ao criá-lo ou gere outro. A área de recebidos oferece acesso por ID, sempre conferindo o destinatário autenticado; conhecer o ID ou possuir o token não concede acesso por si só. Os URLs retornados são endpoints da API; a página Vue será implementada separadamente.

Somente quem possui acesso normal ao documento, download e `shares.create` pode gerar links. Documentos arquivados não recebem novos compartilhamentos. Arquivar preserva links existentes, mas cada consulta/download revalida o criador: conta ativa, permissões necessárias e abrangência sobre o documento. A autorização é verificada novamente no download, mesmo após abrir os metadados. Se o criador recuperar uma autorização perdida antes da expiração, um link não revogado volta a funcionar.

O destinatário pode consultar os metadados e baixar somente a versão compartilhada; não recebe acesso às outras versões, à unidade, à auditoria, nem permissão de edição ou de novo compartilhamento. As permissões normais de quem já possui acesso ao documento continuam independentes do link. Compartilhamentos recebidos não ampliam a listagem normal de documentos nem os indicadores do dashboard.

O criador com `shares.revoke-own` pode revogar o próprio link, inclusive após perder abrangência da unidade. `shares.revoke-any` permite revogar links de terceiros apenas dentro da abrangência normal do usuário. Revogação repetida não cria eventos duplicados. Não há edição de prazo/destinatários: revogue e crie outro link.

Listagens aceitam `page` e `per_page` (1 a 100, padrão 20); busca de destinatários também aceita `search`. O histórico de recebidos conserva uma entrada mínima com situação e datas, mas omite metadados, versão e URLs de download enquanto o acesso estiver indisponível. Situações: `active`, `scheduled`, `expired`, `revoked` e `unavailable` (criador sem autorização).

Sem sessão: 401. Usuário não selecionado, início futuro ou criador sem autorização: 403. Expirado ou revogado para um destinatário selecionado: 410. Token inexistente ou arquivo ausente: 404. Dados inválidos: 422. Consulta de metadados e download registram eventos vinculados ao compartilhamento; o evento de download indica solicitação autorizada, não comprova conclusão da transferência. A expiração e a revogação não recolhem arquivos já baixados nem cancelam transferências já autorizadas em andamento.

As transações de compartilhamento coordenam autorização e revogação com o lock administrativo PostgreSQL. Respostas de compartilhamento e downloads usam `Cache-Control: private, no-store`; os arquivos permanecem no armazenamento privado.

## Consulta da auditoria

As três rotas de consulta usam o prefixo `/api/v1`, exigem sessão autenticada, usuário ativo e pelo menos uma permissão de auditoria (`audit.view` ou `audit.view-global`). São somente de leitura; não há endpoints para criar, alterar ou excluir eventos. A própria consulta não gera novos eventos.

- Eventos vinculados a documentos exigem também `documents.view` e acesso normal ao documento: unidade atual, subordinadas ou acesso geral. `access.global` amplia a abrangência documental, mas não substitui as permissões de consulta e auditoria.
- Eventos administrativos sem documento, como alterações de usuários, perfis e unidades, exigem simultaneamente `audit.view-global` e `access.global`.
- `audit.view-global` não concede acesso automático a outras unidades. Sem `access.global`, a consulta continua restrita aos documentos acessíveis e não inclui eventos administrativos.
- As permissões de auditoria são alternativas para a consulta documental: bloquear uma não remove uma concessão válida da outra. Bloquear ambas impede a consulta. Bloquear `documents.view` impede os eventos documentais mesmo com permissão global de auditoria.
- Documentos arquivados preservam o acesso à auditoria. Compartilhamentos temporários não concedem acesso ao histórico interno.

A abrangência acompanha a **unidade atual do documento**, incluindo seus eventos antigos. Após uma movimentação, quem perde acesso à nova unidade deixa de consultar esse histórico; os auditores da nova unidade passam a consultá-lo. O responsável pelo evento pode pertencer a outra unidade: sua vinculação não determina o acesso ao documento auditado.

As duas listagens aceitam filtros combinados:

| Parâmetro | Comportamento |
|---|---|
| `action` | Ação exata, como `document.archived` |
| `actor_id` | Usuário responsável |
| `document_id` | Documento |
| `document_share_id` | Compartilhamento associado |
| `producing_unit_id` | Unidade produtora atual exata do documento, sem incluir automaticamente as subordinadas |
| `subject_type`, `subject_id` | Entidade afetada, como `document_version` e seu ID |
| `date_from`, `date_to` | Datas no formato AAAA-MM-DD, incluindo os dias inteiros em UTC |
| `page`, `per_page` | Paginação; tamanho padrão 20, máximo 100 |

Exemplo: `GET /api/v1/audit-events?action=document.archived&date_from=2026-11-01&date_to=2026-11-30&per_page=20`.

Filtros apenas restringem o conjunto autorizado. IDs inexistentes ou fora da abrangência em filtros produzem uma lista vazia; não há validação que revele sua existência. Na rota por documento, informar um `document_id` diferente no filtro não troca o documento da rota. A ordenação é por data decrescente, com desempate pelo ID decrescente, e os links de paginação preservam os filtros.

As respostas incluem identificação, ação, entidade afetada, responsável (somente ID e nome), referência ao documento/compartilhamento, data, alterações anteriores/novas e contexto. Nome do responsável, título e unidade exibidos nas referências são os atuais; os valores históricos registrados em `changes` permanecem preservados. Eventos automáticos podem ter responsável nulo. Credenciais, tokens, URLs de acesso e caminhos internos são omitidos recursivamente dos JSONs da resposta, sem modificar o registro armazenado.

Sem autenticação: 401. Sem permissão ou sem acesso à rota de histórico de um documento: 403. Evento fora da abrangência ou inexistente no detalhe individual: 404. Filtros inválidos: 422. As respostas usam `Cache-Control: private, no-store` e a leitura mantém o lock compartilhado PostgreSQL enquanto autoriza, consulta e serializa os resultados. Há índices compostos para consultas por documento/responsável e ordem cronológica; execute `php artisan migrate` nas outras instalações ao atualizar.

## Testes

Os testes usam PostgreSQL real, em `ged_test`, separado de `ged`. Esse banco é criado pelo script de inicialização do volume. Caso utilize um volume já existente sem ele, crie-o uma vez:

```sh
docker compose exec postgres createdb -U ged ged_test
```

Não altere o nome do banco de testes para o banco utilizado pela aplicação. `RefreshDatabase` reconstrói as tabelas do banco de testes.

## Próximas telas do MVP

- Alteração de unidade produtora, arquivamento e restauração.
- Auditoria e administração de usuários, perfis e unidades.

O hash detecta alterações em relação ao valor registrado; não comprova autoria. Assinaturas digitais e processamento automático de e-mails ficam para versões futuras.

Veja também [a validação conceitual dos fluxos](docs/validacao-modelagem.md).

Rotas, exemplos de payloads e regras administrativas estão em [API de administração](docs/api-administracao.md).
