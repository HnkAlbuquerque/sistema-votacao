# Arquitetura – Sistema de Votação Simples (Drupal 11)

> Documento de arquitetura **como implementado**. Atualizado em 15/09/2026 a partir do código
> em `web/modules/custom/`. A versão anterior deste arquivo era o plano feito antes de codar;
> a seção 13 lista o que mudou entre o plano e a implementação, e por quê.
> As justificativas de cada escolha estão em [DECISOES.md](DECISOES.md).

## 1. Visão geral

```
                 CMS (Olivero)                       Aplicação externa
        /votacao/{slug}  →  VoteForm            POST /api/v1/questions/{slug}/vote
                 │                                          │
                 └────────────► VoteManagerInterface ◄──────┘
                                (todas as regras de negócio)
                                         │
                                VoteRepositoryInterface
                                (único ponto que toca o banco de votos)
                                         │
                       MySQL 8: voting_vote  +  voting_result
                              UNIQUE(question_id, uid)   contadores
```

Dois módulos custom, sem contrib:

- **`voting`** – domínio: entidades `voting_question` e `voting_option`, tabelas de votos,
  serviço `VoteManager`, formulários e páginas do CMS, administração, drush, testes Kernel.
- **`voting_api`** – transporte HTTP: rotas `/api/v1`, controllers, serializer, envelope JSON,
  subscriber de exceções, testes Functional. Não contém regra de negócio.

Regra central: nenhum controller ou formulário toca o banco de votos. O formulário do CMS e o
endpoint da API chamam exatamente o mesmo `VoteManager::castVote()`.

## 2. Rastreabilidade dos requisitos

| # | Requisito (PDF) | Onde está no código |
|---|---|---|
| R1 | Cadastro de perguntas com identificador único | `Entity/Question.php`: content entity `voting_question`, campo `identifier` com `UniqueField` + regex; `Form/QuestionForm.php` transforma o campo em `machine_name` gerado do título e travado após criação. |
| R2 | Opções com imagem, título e descrição | `Entity/Option.php`: content entity `voting_option` com `question_id`, `title`, `description`, `image` (campo image do core, 2 MB, png/jpg/jpeg/gif/webp) e `weight`. |
| R3 | Exibir/ocultar total de votos após o voto, por pergunta | Campo `show_results` na pergunta. Lido em `VoteManager::countsVisibleFor()` e `getResults()`, usado pelo CMS e pela API. |
| R4 | Desabilitar a votação de forma geral (CMS e API) | Config `voting.settings.enabled` (`VotingSettings::isEnabled()`), formulário em `/admin/config/system/voting`. Access check `_voting_enabled` em todas as rotas da API; `VoteManager` verifica de novo; CMS mostra aviso. |
| R5 | Usuário acessa cada pergunta e vota | Rota `/votacao/{identifier}` → `QuestionPageController::page()` → `VoteForm` (radios). |
| R6 | Voto único e identificável por usuário e pergunta | Tabela `voting_vote` com `UNIQUE (question_id, uid)` em `voting.install`; `VoteRepository::recordVote()` converte violação em `AlreadyVotedException`. Só autenticados votam. |
| R7 | Após votar, resultado conforme config | `QuestionPageController::buildOutcome()`: tabela de resultados ou só agradecimento; API devolve `results_visible`. |
| R8 | API: listar perguntas | `GET /api/v1/questions` → `QuestionsController::list()` |
| R9 | API: exibir pergunta pelo identificador | `GET /api/v1/questions/{identifier}` → `QuestionsController::show()` |
| R10 | API: registrar voto | `POST /api/v1/questions/{identifier}/vote` → `VoteController::vote()` |
| R11 | API: resultados conforme config | `GET /api/v1/questions/{identifier}/results` → `QuestionsController::results()` |
| R12 | Sem JSON:API, endpoints manuais | `voting_api.routing.yml` + controllers com `JsonResponse`; `ApiExceptionSubscriber` converte exceções em JSON. |
| R13 | Não usar node | Entidades próprias + tabelas próprias. O módulo `node` está instalado apenas porque o perfil `minimal` o instala; nada do sistema o usa. |
| R14 | GitHub, dump, Lando, documentação, Postman | `.lando.yml`, `db/dump.sql.gz`, `config/sync`, `README.md`, `docs/`, `postman/`. |
| NF1 | Fácil para o admin | Lista em `/admin/content/voting` com contagem de votos, abas Edit / Options / Results / Delete na pergunta, tabela arrastável de opções, "Add option" com a pergunta pré-preenchida, página "Quick links" com todas as URLs. |
| NF2 | Segurança | Permissões granulares, Form API (CSRF), `_csrf_request_header_check` no POST, validação de payload, flood control, votos imutáveis, kill switch, logs de rejeição, `Cache-Control: no-store` em votos e resultados. |
| NF3 | Alto volume concorrente com integridade | Insert + `UPDATE votes = votes + 1` em uma transação, sem lock explícito; unique index; contadores pré-semeados; `drush voting:integrity --repair`. |
| NF4 | Observabilidade | Canal de log `voting` com contexto, `GET /api/v1/health`, aba Results com checagem contador × linhas brutas, `drush voting:stats` e `voting:integrity`. |

## 3. Estrutura dos módulos

```
web/modules/custom/voting/
  voting.info.yml               depende de user, file, image
  voting.install                hook_schema: voting_vote, voting_result
  voting.permissions.yml        4 permissões (seção 7)
  voting.routing.yml            páginas públicas, aba Options, add/edit/delete de opção, aba Results, settings, Quick links
  voting.links.menu.yml         Content » Voting (toolbar e sidebar Navigation), Config » System » Voting settings
  voting.links.task.yml         abas Edit/Options/Results/Delete na pergunta; Questions/Quick links na lista
  voting.links.action.yml       botões "Add question" e "Add option"
  voting.services.yml           logger.channel.voting, voting.settings, voting.vote_repository, voting.vote_manager, param converter, access check
  config/install/voting.settings.yml   enabled: true, flood.limit: 20, flood.window: 60
  config/schema/voting.schema.yml
  templates/voting-links.html.twig     página Quick links
  templates/voting-question-list.html.twig, voting-question.html.twig,
            voting-option-card.html.twig, voting-results.html.twig, voting-notice.html.twig
                                       páginas públicas: o módulo define o markup e as classes BEM
  src/Theme/VotingThemePreprocess.php  preprocess do card (entidade → título, descrição, imagem)
  src/VotingSettings.php               acesso tipado à config (isEnabled, flood, cache tags)
  src/QuestionListBuilder.php          lista admin com identifier, status, resultados, votos e operações
  src/Entity/Question.php, QuestionInterface.php
  src/Entity/Option.php, OptionInterface.php
  src/Storage/VoteRepository.php, VoteRepositoryInterface.php   tabelas de votos (Connection)
  src/Storage/OptionStorage.php, OptionStorageInterface.php     loadByQuestion() ordenado por weight
  src/Service/VoteManager.php, VoteManagerInterface.php         regras de negócio
  src/Service/QuestionResults.php                               DTO imutável de resultado
  src/Exception/VotingException.php (+7 subclasses)             uma por regra violada, com código estável
  src/Event/VoteCastEvent.php                                   ponto de extensão após o voto
  src/Access/QuestionAccessControlHandler.php                   view = ativa + permissão, ou admin
  src/Access/OptionAccessControlHandler.php                     view segue a pergunta
  src/Access/VotingEnabledAccessCheck.php                       requirement _voting_enabled
  src/ParamConverter/QuestionIdentifierConverter.php            {voting_question} por slug (type voting_question:identifier)
  src/Form/QuestionForm.php            add/edit da pergunta (machine_name, redirect para Options)
  src/Form/OptionForm.php              add/edit da opção (pergunta oculta quando fixada pela rota)
  src/Form/OptionDeleteForm.php        confirmação avisando que votos serão removidos
  src/Form/OptionsOverviewForm.php     aba Options: tabela arrastável (tabledrag) + operações
  src/Form/SettingsForm.php            kill switch + limite/janela de flood (#config_target)
  src/Form/VoteForm.php                radios + botão Vote
  src/Controller/QuestionPageController.php   /votacao e /votacao/{slug}
  src/Controller/OptionController.php         cola entre rotas da pergunta e o form de opção
  src/Controller/AdminResultsController.php   aba Results com integridade
  src/Controller/LinksController.php          Quick links (demo)
  src/Hook/VotingHooks.php             help, theme, pré-semeadura do contador, cascata de exclusão
  src/Drush/Commands/VotingCommands.php   voting:integrity [--repair], voting:stats
  tests/src/Kernel/VoteManagerTest.php    11 testes

web/modules/custom/voting_api/
  voting_api.info.yml           depende de voting e basic_auth
  voting_api.routing.yml        5 rotas (seção 8)
  voting_api.services.yml       voting_api.serializer, voting_api.exception_subscriber
  src/Controller/QuestionsController.php   list(), show(), results()
  src/Controller/VoteController.php        vote() + validação do body
  src/Controller/HealthController.php      health()
  src/Serializer/QuestionSerializer.php    summary(), detail(), option(), results() – só arrays
  src/Response/ApiResponse.php             cacheable(), uncacheable(), error() – envelope único
  src/EventSubscriber/ApiExceptionSubscriber.php   exceções → JSON em /api/*
  tests/src/Functional/VotingApiTest.php   4 testes ponta a ponta com Basic Auth

web/themes/custom/votacao/          tema do site público (base theme: stable9)
  votacao.info.yml, votacao.libraries.yml, votacao.theme, logo.svg
  tokens/design-tokens.json         fonte única: cores HSL light/dark, espaçamento, raio, tipografia, sombras, breakpoints
  gulpfile.js, package.json         tokens → _tokens.generated.scss → sass → autoprefixer → css/main.css
  scss/abstracts/                   _tokens.generated.scss (gerado), _mixins.scss (color(), space(), up()…)
  scss/base/                        reset, tipografia
  scss/layout/                      page, header, footer
  scss/components/                  button, badge, card, notice, form, option-card, question, results, table
  css/main.css                      build versionado; o site não depende de Node em runtime
  templates/                        html, page, region, block de branding, menu de conta, abas locais
```

## 4. Modelo de dados

### Content entities (sem bundles, base fields em código)

**`voting_question`** (`Entity/Question.php`)

| Campo | Tipo | Observação |
|---|---|---|
| `id`, `uuid` | serial, uuid | nativos |
| `title` | string(255) | label da entidade |
| `identifier` | string(64) | único (`UniqueField`), regex `^[a-z0-9]+(?:[-_][a-z0-9]+)*$`, usado nas URLs e na API |
| `description` | string_long | opcional |
| `show_results` | boolean | default TRUE |
| `status` | boolean | `EntityPublishedTrait`; rótulo "Active"; só ativas listam e aceitam voto |
| `uid` | entity_reference user | `EntityOwnerTrait` |
| `created`, `changed` | timestamps | |

Handlers: `QuestionListBuilder`, `QuestionAccessControlHandler`, `QuestionForm`, `ContentEntityDeleteForm`,
`AdminHtmlRouteProvider` (gera collection/add/edit/delete em `/admin/content/voting`).

**`voting_option`** (`Entity/Option.php`)

| Campo | Tipo | Observação |
|---|---|---|
| `id`, `uuid` | | nativos |
| `question_id` | entity_reference voting_question | obrigatório |
| `title` | string(255) | |
| `description` | string_long | |
| `image` | image | diretório `voting/options`, 2 MB, alt opcional |
| `weight` | integer | ordem de exibição |

Handlers: `OptionStorage` (`loadByQuestion()`), `OptionAccessControlHandler`, `OptionForm`, `OptionDeleteForm`.
As rotas de edit/delete ficam em `voting.routing.yml` porque vivem sob a pergunta no admin.

### Tabelas próprias (`voting.install`, Schema API)

```
voting_vote                                   voting_result
  id           serial PK                        question_id  int unsigned
  question_id  int unsigned                     option_id    int unsigned
  option_id    int unsigned                     votes        int unsigned default 0
  uid          int unsigned                     PRIMARY KEY (question_id, option_id)
  created      int
  UNIQUE KEY question_uid (question_id, uid)
  INDEX question_option (question_id, option_id)
  INDEX uid (uid)
```

- `voting_vote` é a fonte de verdade. Um voto por (pergunta, usuário) é garantido pelo banco.
- `voting_result` é o contador denormalizado. Ler resultado é um `SELECT` de N linhas por PK.
- A linha do contador é criada com `votes = 0` quando a opção é salva (`VotingHooks::optionInsert()`),
  então o caminho quente do voto é um único `UPDATE`.
- O MySQL do Lando roda com `isolation_level: READ COMMITTED` (padrão do Drupal para MySQL, em `settings.php`).

## 5. Fluxo de voto (`VoteManager::castVote()`)

Antes de chegar ao controller da API, a própria rota já exige: usuário logado, permissão
`vote in voting questions`, acesso `view` à pergunta (ativa ou admin), `_voting_enabled` e, para
sessões por cookie, o header CSRF. Dentro do serviço, na ordem:

| Passo | Falha → exceção | HTTP |
|---|---|---|
| 1. `voting.settings.enabled`? | `VotingDisabledException` | 403 `voting_disabled` |
| 2. Conta autenticada? | `AuthenticationRequiredException` | 401 `authentication_required` |
| 3. `flood->isAllowed()` por uid, depois `flood->register()`: toda tentativa conta, válida ou não | `VoteRateLimitedException` | 429 `rate_limited` |
| 4. Pergunta ativa? | `QuestionInactiveException` | 403 `question_inactive` |
| 5. `option_id` existe e pertence à pergunta? | `InvalidOptionException` | 422 `invalid_option` |
| 6. Já votou? (`SELECT` indexado, saída barata) | `AlreadyVotedException` | 409 `already_voted` |
| 7. `VoteRepository::recordVote()` em transação: `INSERT voting_vote`; `UPDATE voting_result SET votes = votes + 1`; se 0 linhas, `MERGE` cria a linha | violação do unique → rollback → `AlreadyVotedException` (corrida perdida, logada com `@race`) | 409 |
| 8. `logger->info()` com `@question`, `@option`, `@uid` | | |
| 9. `dispatch(VoteCastEvent)` | | |
| 10. Retorna `QuestionResults` com contagens só se `show_results` ou permissão `view voting results` | | 201 |

Toda rejeição passa por `reject()`, que grava `notice` com o código do erro antes de lançar.

Sem `SELECT ... FOR UPDATE`: o unique index serializa apenas pares (pergunta, usuário), que
nunca colidem entre usuários diferentes; o `UPDATE ... + 1` é atômico no InnoDB e trava só a
linha da opção. O passo 5 evita, no caso comum (duplo clique), pagar o insert falho e o rollback,
mas a garantia real é o passo 7. O flood vem antes das validações de pergunta e opção de propósito: uma
rajada de requisições malformadas custa o mesmo limite que votos válidos.

## 6. Visibilidade de resultados (`VoteManager::getResults()`)

| Situação | API `GET .../results` | CMS `/votacao/{slug}` | `POST .../vote` |
|---|---|---|---|
| Votação desligada | 403 `voting_disabled` | aviso | 403 |
| Não votou, `show_results` qualquer | 403 `vote_required` | formulário de voto | – |
| Votou, `show_results = true` | 200 com `votes`/`percentage`/`total_votes` | tabela com "(your vote)" | 201, `results_visible: true` |
| Votou, `show_results = false` | 403 `results_hidden` | "Thank you… results are not public" | 201, `results_visible: false`, `total_votes: null`, opções sem `votes` |
| Tem `view voting results` | 200 sempre, mesmo sem votar | tabela | 201 com contagens |

`QuestionResults` carrega `counts = NULL` quando escondido, então quem consome nunca reimplementa a regra.

## 7. Permissões e papéis

| Permissão | No dump | Uso |
|---|---|---|
| `administer voting` (restrict access) | administrator | CRUD de perguntas/opções, settings, abas Results e Quick links |
| `view voting questions` | anonymous, authenticated | `/votacao*` e GETs da API |
| `vote in voting questions` | authenticated | `VoteForm` e `POST .../vote` |
| `view voting results` | ninguém (admin tem por ser admin) | ver contagens mesmo com `show_results = false` e sem ter votado |

Anônimo vê perguntas e recebe link de login; não vota porque sem `uid` não há "um voto por usuário".

Usuários do dump: `admin/admin` (administrador), `alice/alice`, `bob/bob` e mais dez usuários comuns
(`ana`, `bruno`, `carla`, `diego`, `elena`, `fabio`, `gabriela`, `henrique`, `isabela`, `joao`) com senha igual
ao nome. Perguntas: `melhor-linguagem` (resultados visíveis) e `framework-favorito` (ocultos), já com votos,
mais 100 perguntas de conhecimentos gerais com quatro alternativas, das quais dez ocultam totais e três estão inativas.

## 8. API (`voting_api`)

Prefixo `/api/v1`. Todas as rotas exceto `health` têm `_auth: [basic_auth, cookie]` e `_voting_enabled`.

| Método | Rota | Requisitos | Resposta |
|---|---|---|---|
| GET | `/questions?page=1&limit=20` | `view voting questions` | `data: [{id, title, description, show_results, options_count}]`, `meta: {count, total, page, limit, pages}`; `limit` máximo 100; parâmetro inválido → 400 |
| GET | `/questions/{id}` | `voting_question.view` | `data: {id, title, description, show_results, options: [{id, title, description, image_url}]}` |
| POST | `/questions/{id}/vote` | logado, `vote in voting questions`, view, CSRF header (só cookie) | 201 + resultado (formato abaixo) |
| GET | `/questions/{id}/results` | logado, view | 200 + resultado, ou 403 `vote_required` / `results_hidden` |
| GET | `/health` | público | `{status, database, voting_enabled, drupal, timestamp}`; 503 se o banco não responde |

`{id}` é o `identifier` (slug), resolvido pelo `QuestionIdentifierConverter` (o mesmo do CMS).

Body do voto: `{"option_id": 3}` (JSON) ou `option_id=3` (form-encoded). Resposta:

```json
{ "data": { "question_id": "melhor-linguagem", "results_visible": true, "total_votes": 3,
            "your_vote": 2, "options": [ { "id": 1, "title": "PHP", "votes": 1, "percentage": 33.3 } ] } }
```

Envelope de erro: `{ "error": { "code": "already_voted", "message": "..." } }`. Tabela completa de
códigos no README. Mapeamento em `ApiExceptionSubscriber`, com três listeners:

1. prioridade 90: `AccessDeniedHttpException` com a votação desligada → 403 `voting_disabled`
   (antes do core transformar o 403 de anônimo em desafio 401);
2. prioridade 60: `VotingException` → status da tabela `STATUS_MAP` + `getErrorCode()`;
3. prioridade 40 (depois do log do core): outras `HttpException` (400/401/403/404/405…), com
   `invalid_credentials` quando veio `Authorization` mas o usuário continuou anônimo; qualquer
   outra exceção vira 500 `server_error` e é logada com `error`.

Cache:

- `list` e `show`: `CacheableJsonResponse` com tags `voting_question_list`, `voting_option_list`
  (tags de lista que o Entity API invalida sozinho ao salvar/excluir), tags das entidades
  carregadas e `config:voting.settings`; contexto `user.permissions` e, na lista, `url.query_args:page`
  e `url.query_args:limit`, para cada página ser uma entrada de cache própria.
- `vote`, `results`, `health`: `JsonResponse` com `Cache-Control: no-store, private`.

## 9. CMS

Público (tema `votacao`):

- `/votacao` – página inicial do site (`system.site: page.front`). Lista de perguntas ativas em cards, 12 por página com o pager do core, badges de
  quantidade de opções e de visibilidade dos resultados (cache por `voting_question_list` + config
  + contexto do pager).
- `/votacao/{slug}` – descrição, cards das opções (imagem em estilo `medium`) e o desfecho da
  seção 6, com link "All questions" de volta à lista. No formulário, cada card é a label de um
  radio; após o voto, o card escolhido fica destacado e os resultados aparecem como barras. Contexto de cache `user`; os resultados têm `max-age: 0`.
- O markup e as classes BEM vêm das templates do módulo (`voting-*.html.twig`); o tema só
  estiliza e pode sobrescrever qualquer template. Tokens de design em JSON geram as custom
  properties de light e dark mode; o CSS compilado é versionado. O modo segue o sistema, com um
  botão no cabeçalho que força light ou dark (`data-theme` no `<html>`, guardado em `localStorage`).

Administração (tema Claro; link em Content » Voting no toolbar e na sidebar Navigation):

- `/admin/content/voting` – lista com título, identifier, status, modo de resultado, votos e operações
  (Edit, Options, Results, View, Delete). Aba "Quick links" (`/admin/content/voting/links`) com todas
  as URLs, contas e comandos, para demo.
- `/admin/content/voting/add` – ao salvar, redireciona para a aba Options.
- `/admin/content/voting/{id}/edit` – abas Edit / Options / Results / Delete.
- `.../{id}/options` – tabela arrastável; `.../options/add`, `/admin/content/voting/option/{id}/edit|delete`.
- `.../{id}/results` – contador × linhas brutas por opção, com aviso se divergir.
- `/admin/config/system/voting` – kill switch e flood (`limit`, `window`).

Exclusão em cascata (`VotingHooks`): apagar uma opção apaga seus votos e contador (log `warning`);
apagar uma pergunta apaga opções, votos e contadores (log `notice`).

## 10. Segurança

- Form API com token CSRF no CMS; `_csrf_request_header_check` no POST da API para sessões por cookie
  (Basic Auth não tem sessão, então não é afetado).
- Validação do body: JSON inválido → 400; `option_id` ausente ou não inteiro positivo → 400;
  opção de outra pergunta → 422.
- Flood control por `uid` (`voting.cast_vote`, 20 tentativas / 60 s por padrão, ajustável no admin), contando
  toda tentativa de voto, inclusive as rejeitadas por opção inválida ou voto repetido.
- Votos imutáveis: não há rota de update/delete de voto; contagens só agregadas, sem expor `uid` alheio.
- Kill switch avaliado na rota (com cache tag da config) e de novo no serviço.
- Identificador travado após a criação: clientes externos podem confiar na URL.
- `hash_salt` e credenciais em `settings.php` são as do Lando local, não de produção.

## 11. Observabilidade

- Canal `logger.channel.voting`: `info` voto registrado; `notice` rejeição com `@code`;
  `warning` opção excluída com votos; `notice` pergunta excluída; `error` exceção inesperada na API.
  Ver em `/admin/reports/dblog` (tipo `voting`) ou `lando drush watchdog:show --type=voting`.
- `GET /api/v1/health` para load balancer / monitor.
- Aba Results compara `voting_result` com `COUNT(*)` de `voting_vote`.
- `lando drush voting:stats` e `lando drush voting:integrity [--repair]`.

## 12. Ambiente, entrega e testes

- Lando recipe `drupal11`, PHP 8.3, MySQL 8.0, Composer 2, tooling `lando phpunit`.
- Drupal 11.4, perfil `minimal`, Drush 13, `drupal/core-dev` para testes. Nenhum módulo contrib.
- Módulos habilitados além dos custom: `basic_auth` (API), `file` + `image` (imagem da opção),
  `dblog` (logs), `field_ui` (conveniência de admin), `navigation` (sidebar do Drupal 11, que puxa
  `block`, `layout_builder`, `layout_discovery`, `contextual`, `breakpoint`), e os que o perfil
  `minimal` instala: `node`, `block`, `dblog`, `page_cache`, `dynamic_page_cache`. `node` não é
  usado por nada do sistema. Temas: `votacao` (site, custom, base `stable9`), Claro (admin).
- Serviço `node:20` no Lando com tooling `lando npm` e `lando gulp`, usado só para compilar o tema.
- Config exportada em `config/sync` (`lando drush cex`), dump em `db/dump.sql.gz`, Postman em
  `postman/` (collection com testes automáticos por request + environment local).
- Testes (`lando phpunit`, 17 no total):
  - Kernel `VoteManagerTest` (12): voto grava linha e contador; segundo voto rejeitado; unique key
    segura mesmo sem o pré-check e mantém o contador; opção de outra pergunta; anônimo; pergunta
    inativa; kill switch bloqueia voto e resultado; resultado exige voto; resultado oculto continua
    oculto para votante e visível para quem tem bypass; flood limita votos válidos; flood conta
    tentativas rejeitadas; cascata de exclusão.
  - Functional `VotingApiTest` (5): list/show/404/inativa; paginação da lista; fluxo completo do voto (401, credencial
    errada, 400, 422, `vote_required`, 201, 409, results 200 com `no-store`); resultados ocultos;
    kill switch em todas as rotas menos health.
- `lando phpcs` (ruleset `phpcs.xml.dist`: Drupal + DrupalPractice em módulos e tema) limpo.

## 13. O que mudou entre o plano inicial e a implementação

| Planejado | Implementado | Motivo |
|---|---|---|
| `Service/VoteRepository.php` e `Service/ResultsCalculator.php` | `Storage/VoteRepository.php` + DTO `Service/QuestionResults.php` | Storage separado do serviço deixa a fronteira com o banco visível; percentual e total são triviais e ficaram no DTO. |
| `ListBuilder/OptionListBuilder.php` | `Form/OptionsOverviewForm.php` | Tabela arrastável precisa ser um form (tabledrag + submit); ListBuilder não serve. |
| `ResultsController` + `ResultsSerializer` na API | `QuestionsController::results()` + `QuestionSerializer::results()` | Menos classes com a mesma responsabilidade; um serializer cobre pergunta, opção e resultado. |
| 3 exceções | 8 exceções com `getErrorCode()` e `getUserMessage()` | Uma por regra, para que o subscriber e o form mapeiem sem `if` por classe. |
| Só kill switch nas settings | Kill switch + `flood.limit` + `flood.window` | Limite de flood precisa ser ajustável sem deploy. |
| Sem pré-check de voto | `hasVoted()` antes do insert | Evita insert falho + rollback no caso comum; o unique index continua sendo a garantia. |
| Contador criado no primeiro voto (`MERGE`) | Linha pré-semeada com 0 ao salvar a opção; `MERGE` só como fallback | Caminho quente vira um único `UPDATE`. |
| Basic Auth só nas rotas de escrita | `_auth: [basic_auth, cookie]` em todas as rotas de negócio | Clientes autenticados também precisam ver perguntas inativas que administram; cookie permite uso interno. |
| – | `Response/ApiResponse.php` | Envelope e cabeçalhos de cache num único lugar. |
| – | `VotingSettings.php` | Config tipada; evita strings `'voting.settings'` espalhadas. |
| – | `OptionStorage`, `OptionAccessControlHandler`, `OptionController`, `OptionDeleteForm` | Opção vive sob a pergunta no admin e o acesso de leitura segue a pergunta. |
| – | `Hook/VotingHooks.php` com `#[Hook]` | Hooks orientados a objeto do Drupal 11, injetáveis e testáveis. |
| – | `LinksController` + template | Página única com todas as URLs, para demo e revisão. |
| – | Premissa "resultado só para quem votou" (`vote_required`) | O PDF diz "após a votação"; sem isso qualquer usuário veria totais sem participar. |
| – | Módulo `navigation` | Sidebar padrão do Drupal 11; o link Content » Voting aparece nela e no toolbar. |
| `postman/voting.postman_collection.json` | `voting-api.postman_collection.json` + `voting-local.postman_environment.json` | Environment separado para `base_url` e credenciais. |
| Render arrays genéricos (`item_list`, `html_tag`) nas páginas públicas | Theme hooks e templates Twig próprios no módulo | Sem template não há o que um tema estilizar; o módulo passa a ser dono do markup e das classes. |
| Tema Olivero | Tema custom `votacao` com design tokens, SCSS em BEM e build gulp | Dar identidade visual ao sistema sem contrib e sem dependência em runtime. |

## 14. Limitações conhecidas e próximos passos

- `node` instalado pelo perfil `minimal` sem uso; candidato a desinstalação.
- Autenticação por Basic Auth é adequada para o teste; em produção, `simple_oauth` ou API key por cliente.
- O teste de concorrência citado no README foi manual (requisições paralelas via shell), não está automatizado.
