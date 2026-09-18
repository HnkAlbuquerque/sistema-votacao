# Batimento dos requisitos do PDF com o código

> Atualizado em 16/09/2026. Cada item do PDF "Teste de Sistema de Votação Simples" aparece na ordem do
> documento, com **status**, **como** está implementado (arquivo:linha, caminhos relativos a `web/`,
> exceto quando indicado) e **por quê** dessa forma. Linhas conferidas no commit atual da `main`.

Legenda de status: **Coberto** e **Coberto, além do pedido**.

---

## 1. Interface Administrativa

### 1.1 Cadastro de perguntas, cada uma com identificador único — Coberto

**Como**
- Content entity própria `voting_question`: `modules/custom/voting/src/Entity/Question.php:25`.
- Campo `identifier` (string, 64): `Question.php:127`; unicidade pela constraint `UniqueField`: `Question.php:132`; formato slug por regex `^[a-z0-9]+(?:[-_][a-z0-9]+)*$`: `Question.php:139`.
- No formulário o campo vira `machine_name` gerado a partir do título: `modules/custom/voting/src/Form/QuestionForm.php:25-28`; travado depois de salvo: `QuestionForm.php:27`.
- O slug resolve a pergunta na URL do site e da API pelo mesmo `ParamConverter`: `modules/custom/voting/src/ParamConverter/QuestionIdentifierConverter.php:36`.
- Cadastro em `/admin/content/voting` com rotas geradas pelo Entity API: `Question.php:55`.

**Por quê**
- Entidade própria e não node, como o PDF exige, mas ainda com tudo que o Entity API dá de graça: formulário, validação, access handler, cache tags (DECISOES §1).
- Slug definido pelo admin e imutável: legível na URL, no Postman e no log, e estável para clientes externos. `id` numérico mudaria entre ambientes; `uuid` é ilegível (DECISOES §7).

### 1.2 Várias opções por pergunta, com imagem, título e breve descrição — Coberto

**Como**
- Content entity `voting_option`: `modules/custom/voting/src/Entity/Option.php:25`, com referência à pergunta (`Option.php:119`), `title` (`:128`), `description` (`:137`), `image` do core com limite de 2 MB e extensões (`:146`) e `weight` (`:161`).
- Aba "Options" na pergunta com tabela arrastável para ordenar: `modules/custom/voting/src/Form/OptionsOverviewForm.php:54`; ordem aplicada na leitura: `modules/custom/voting/src/Storage/OptionStorage.php:22`.
- Botão "Add option" já com a pergunta fixada: `modules/custom/voting/src/Form/OptionForm.php:25`; ao criar a pergunta o admin cai direto na aba de opções: `QuestionForm.php:62`.
- Imagem renderizada no estilo `medium` do core: `modules/custom/voting/src/Theme/VotingThemePreprocess.php:18,43-44`.

**Por quê**
- Entidade separada, e não campo multivalor: o voto precisa apontar para um `option_id` estável; um delta de campo mudaria ao reordenar ou remover opções e corromperia resultados. O campo `image` do core resolve `file_usage`, validação e estilos de imagem sem código (DECISOES §2).

### 1.3 Configurar se o total de votos é exibido ou ocultado após o voto, por pergunta — Coberto

**Como**
- Campo booleano `show_results` na pergunta, padrão ligado: `Question.php:153`.
- Decisão centralizada no serviço: `modules/custom/voting/src/Service/VoteManager.php:138-139` (`countsVisibleFor`) e `:127` (`getResults` lança `results_hidden`).
- O DTO devolve contagens `NULL` quando ocultas, então site e API só omitem: `modules/custom/voting/src/Service/QuestionResults.php:41`.
- No site: tabela de barras ou só o agradecimento: `modules/custom/voting/src/Controller/QuestionPageController.php:169`. Na API: `results_visible` no JSON: `modules/custom/voting_api/src/Serializer/QuestionSerializer.php:80`.

**Por quê**
- A regra "pode ver os totais?" existe em um lugar só. Nenhum consumidor consegue vazar total por descuido (DECISOES §15).

### 1.4 Desabilitar a votação de forma geral, no CMS e no acesso externo — Coberto

**Como**
- Config `voting.settings: enabled`: `modules/custom/voting/config/install/voting.settings.yml:1`; leitura tipada: `modules/custom/voting/src/VotingSettings.php:24`; formulário em Configuration » System » Voting settings: `modules/custom/voting/src/Form/SettingsForm.php:34`.
- Access check de rota `_voting_enabled`: `modules/custom/voting/src/Access/VotingEnabledAccessCheck.php:31`, registrado em `modules/custom/voting/voting.services.yml:38`, aplicado a toda rota de negócio da API: `modules/custom/voting_api/voting_api.routing.yml:15,27,43` e seguintes.
- O serviço verifica de novo, para valer também fora de uma rota: `VoteManager.php:57` (voto) e `:115` (resultados).
- Site: aviso na lista e na pergunta: `QuestionPageController.php:83,148`.
- API: resposta `403 voting_disabled` mesmo para anônimo, antes do core transformar em desafio de login: `modules/custom/voting_api/src/EventSubscriber/ApiExceptionSubscriber.php:67`.

**Por quê**
- Access check declarado no YAML é impossível de esquecer num endpoint novo. O `AccessResult` carrega a cache tag da config (`VotingEnabledAccessCheck.php:32`), então desligar invalida o cache sozinho. A administração continua acessível (DECISOES §9).

---

## 2. Interface de Interação com o Usuário no próprio CMS

### 2.1 Acessar cada pergunta de forma independente e votar em uma opção — Coberto

**Como**
- Rota `/votacao/{identificador}`: `modules/custom/voting/voting.routing.yml:11` → `QuestionPageController::page()`: `QuestionPageController.php:103`.
- Formulário de voto com radios: `modules/custom/voting/src/Form/VoteForm.php:82`; cada radio tem como label o card completo da opção (imagem, título, descrição): `VoteForm.php:59-63`.
- O submit chama o mesmo serviço da API: `VoteForm.php:108`.
- Lista de perguntas ativas, paginada, em `/votacao`, que também é a página inicial.

**Por quê**
- O formulário não contém regra de negócio; só chama `VoteManager::castVote()`. Assim não existe "bug que só acontece no site" (DECISOES §5).

### 2.2 Voto identificável e único por usuário para cada pergunta — Coberto

**Como**
- Tabela `voting_vote` com `UNIQUE (question_id, uid)`: `modules/custom/voting/voting.install:48-49`.
- Insert em transação; violação da chave vira `AlreadyVotedException`: `modules/custom/voting/src/Storage/VoteRepository.php:47-52,78-80`.
- Só autenticado vota: `VoteManager.php:60`; pré-check barato antes do insert: `VoteManager.php:86`; corrida perdida é logada com `@race`: `VoteManager.php:94`.
- Cada voto guarda `uid`, `question_id`, `option_id` e `created` (`voting.install`), portanto é identificável; nunca é exposto individualmente.

**Por quê**
- A constraint do banco é a única garantia real quando duas requisições do mesmo usuário chegam no mesmo milissegundo; um SELECT antes não basta. Sem `uid` não existe "por usuário", por isso anônimo não vota (DECISOES §3, §4, §8). Provado em `modules/custom/voting/tests/src/Kernel/VoteManagerTest.php:115` chamando o repositório direto, sem o pré-check.

### 2.3 Após a votação, resultados conforme a configuração de cada questão — Coberto

**Como**
- `VoteManager::getResults()`: `VoteManager.php:114`; exige ter votado (`:125`, `vote_required`) e pergunta que permita (`:128`, `results_hidden`); a permissão `view voting results` ignora as duas regras.
- `castVote()` já devolve o resultado conforme a config, sem segunda chamada: `VoteManager.php:50`.
- Página decide o que mostrar: formulário, barras de resultado com "Seu voto", ou agradecimento: `QuestionPageController.php:145-178`.

**Por quê**
- "Após a votação" foi lido como premissa: quem não votou não vê total, para não "votar no vencedor". Admin tem bypass por permissão separada (DECISOES §17).

---

## 3. API para Aplicação Externa

Rotas em `modules/custom/voting_api/voting_api.routing.yml`, todas sob `/api/v1`, com Basic Auth do core e `_voting_enabled` (`:1-3`). Envelope único `{data, meta}` / `{error: {code, message}}`: `modules/custom/voting_api/src/Response/ApiResponse.php:27,38,48,57`.

### 3.1 Obtenção das perguntas — Coberto, além do pedido

**Como**
- `GET /api/v1/questions`: `voting_api.routing.yml:8`, autenticada (`:13`) → `QuestionsController::list()`: `modules/custom/voting_api/src/Controller/QuestionsController.php:53`.
- Paginação `page`/`limit` (padrão 20, máximo 100) com `meta {count, total, page, limit, pages}`: `QuestionsController.php:67`; parâmetro inválido → 400.
- Resposta cacheável por tags de lista, config e página: `QuestionsController.php:77-79`.

**Por quê**
- Só perguntas ativas, com contagem de opções, para o cliente escolher. Paginação limita o custo por requisição. Cache por tag de entidade nunca fica obsoleto (DECISOES §6, §10).

### 3.2 Exibição da pergunta selecionada pelo identificador, com as opções — Coberto

**Como**
- `GET /api/v1/questions/{identificador}`: `voting_api.routing.yml:20`, autenticada (`:25`) → `QuestionsController::show()`: `QuestionsController.php:108`; opções com `id`, `title`, `description`, `image_url`: `QuestionSerializer.php`.
- Identificador convertido pelo mesmo `ParamConverter` do site: `QuestionIdentifierConverter.php:36`; desconhecido → `404 not_found`.
- Cache com as tags da pergunta e de cada opção: `QuestionsController.php:113-115`.

### 3.3 Registro dos votos — Coberto

**Como**
- `POST /api/v1/questions/{identificador}/vote`: `voting_api.routing.yml:35`, exige login, permissão `vote in voting questions`, `_voting_enabled` e header CSRF quando a sessão é por cookie (`:40-44`).
- Body JSON ou form-encoded, `option_id` inteiro positivo: `modules/custom/voting_api/src/Controller/VoteController.php:42,54,60`.
- Chama `VoteManager::castVote()` e responde `201` com o resultado conforme a config; erros viram `400/401/403/409/422/429` pelo subscriber: `ApiExceptionSubscriber.php:42-49,93`.

**Por quê**
- Controller sem `try/catch` e sem regra: o subscriber mapeia exceção de domínio → status HTTP em uma tabela (DECISOES §6, §16). `Cache-Control: no-store` na resposta: `ApiResponse.php:41`.

### 3.4 Exibição dos resultados conforme a configuração — Coberto

**Como**
- `GET /api/v1/questions/{identificador}/results`: `voting_api.routing.yml:52` → `QuestionsController::results()`: `QuestionsController.php:126` → `VoteManager::getResults()`.
- `200` com `votes`/`percentage`/`total_votes`/`your_vote`, ou `403 vote_required` / `403 results_hidden`: `QuestionSerializer.php:64-80`.

**Por quê**
- Resultado nunca é cacheado: invalidar tag a cada voto custa mais que um SELECT indexado de N linhas (DECISOES §10).

### 3.5 Extra: `GET /api/v1/health` — Coberto, além do pedido
- `voting_api.routing.yml:67`, pública de propósito para monitor e load balancer: `modules/custom/voting_api/src/Controller/HealthController.php:40,49` (503 se o banco não responde).

---

## 4. Requisitos Não Funcionais

### 4.1 Fácil de usar e intuitivo para o administrador — Coberto

**Como**
- Lista administrativa com identificador, status, modo de resultado, total de votos e operações: `modules/custom/voting/src/QuestionListBuilder.php:42`.
- Abas Edit / Options / Results / Delete na pergunta e Questions / Quick links na lista: `modules/custom/voting/voting.links.task.yml:4-31`; botões "Add question" e "Add option": `voting.links.action.yml:3,8`.
- Fluxo guiado: salvar a pergunta leva à aba de opções (`QuestionForm.php:62`); a opção nasce com a pergunta fixada (`OptionForm.php:25`); tabela arrastável para ordenar (`OptionsOverviewForm.php:54`).
- Página "Quick links" com todas as URLs, contas e comandos: `modules/custom/voting/src/Controller/LinksController.php:13`.
- Interface em português do Brasil via `locale` + `.po` do projeto (402 linhas): `modules/custom/voting/translations/voting.pt-br.po`.

### 4.2 Segurança: proteção dos votos e prevenção de manipulações — Coberto

**Como**
- Permissões granulares, `administer voting` com `restrict access`: `modules/custom/voting/voting.permissions.yml:1-11`.
- Access handler: só pergunta ativa é visível a não-admin: `modules/custom/voting/src/Access/QuestionAccessControlHandler.php:27`.
- Toda a API autenticada, exceto health: `voting_api.routing.yml:13,25,40` e `:1`; Basic Auth do core, cookie exige header CSRF (`:44`).
- Validação: opção precisa pertencer à pergunta (`VoteManager.php:79`), payload inteiro positivo (`VoteController.php:60`), pergunta ativa (`VoteManager.php:74`).
- Flood control por usuário, contando toda tentativa: `VoteManager.php:68-71` (20 tentativas / 60 s, ajustável no admin).
- Votos imutáveis: não existe rota de update ou delete de voto; contagens só agregadas.
- Identificador travado após criação (`QuestionForm.php:27`); Form API com token CSRF no site; `no-store` em voto e resultado (`ApiResponse.php:41,50`); erros 500 não vazam detalhes (`ApiExceptionSubscriber.php:137`).

**Por quê**
- Cada camada trata um vetor: permissão (quem), access check (o quê), validação (dados), unique key (duplicidade), flood (volume), imutabilidade (adulteração). Ver DECISOES §8, §9, §11.

---

## 5. Requisitos Técnicos

### 5.1 Endpoints da API implementados manualmente, sem JSON:API — Coberto
- `config/sync/core.extension.yml` não lista `jsonapi` nem `rest`; a API depende só de `voting` e `basic_auth`: `modules/custom/voting_api/voting_api.info.yml:9`.
- Rotas em YAML, controllers próprios, serializer sem regra, subscriber de exceções: seção 3 acima e DECISOES §6.

### 5.2 Não utilizar node para as entidades — Coberto
- `Question` e `Option` estendem `ContentEntityBase`: `Question.php:8`, `Option.php:8`; votos em tabelas próprias via Schema API: `voting.install`.
- O módulo `node` aparece em `core.extension.yml:21` apenas porque o perfil `minimal` o instala; nada do sistema o usa (DECISOES §1, §14).

### 5.3 Repositório no GitHub, dump do banco e ambiente via Lando — Coberto
- Repositório: `github.com/HnkAlbuquerque/sistema-votacao`, histórico granular por feature.
- `.lando.yml` (recipe `drupal11`, PHP 8.3, MySQL 8, serviço Node para o tema, tooling `phpunit`, `phpcs`, `gulp`): `.lando.yml:2`.
- `db/dump.sql.gz`, gzip válido, com 13 usuários, 102 perguntas, traduções e configuração do tema; `config/sync` exportado.

### 5.4 Documentação mínima — Coberto, além do pedido
- `README.md` (instalação, uso, idioma, tema, API, erros, Postman, observabilidade, testes), `docs/ARQUITETURA.md` (as-built), `docs/DECISOES.md` (por quê de cada escolha), este `docs/REQUISITOS.md`.

### 5.5 Collection do Postman — Coberto
- `postman/voting-api.postman_collection.json` (11 requests com testes automáticos, Basic Auth herdada, encadeia `question_id` e `option_id`) e `postman/voting-local.postman_environment.json`. Executável sem o Postman: `npx newman run …` (README).

---

## 6. Critérios de avaliação

### 6.1 Funcionalidades conforme os requisitos — Coberto
- Seções 1 a 3 acima, cada uma com arquivo e linha.

### 6.2 Estrutura planejada, separação de responsabilidades e modularidade — Coberto
- Dois módulos: `voting` (domínio) e `voting_api` (transporte), a API sem regra de negócio (DECISOES §5).
- Camadas dentro do domínio: entidades → `VoteRepositoryInterface` (`modules/custom/voting/src/Storage/`) → `VoteManagerInterface` (`src/Service/`) → formulários/controllers. Nenhum controller toca o banco.
- Markup no módulo, aparência no tema: theme hooks e templates em `modules/custom/voting/templates/`, tema `themes/custom/votacao` (DECISOES §21, §22).
- Uma exceção por regra com código estável: `modules/custom/voting/src/Exception/`.

### 6.3 Otimização para alta performance e eficiência — Coberto
- Voto = 1 INSERT + 1 UPDATE atômico, sem lock explícito: `VoteRepository.php:52,62-63`; contador pré-semeado ao criar a opção para o caminho quente ser um único UPDATE: `modules/custom/voting/src/Hook/VotingHooks.php:84-85`.
- Resultado lido por chave primária, nunca `COUNT(*)`: `VoteRepository.php:91`.
- Cache de perguntas por tag de entidade e de lista; resultados sem cache por escolha (DECISOES §10); perfil `minimal` sem contrib (DECISOES §14); paginação no site e na API.

### 6.4 Melhores práticas do Drupal — Coberto
- Content entities com base fields, `AdminHtmlRouteProvider`, access handlers, `ParamConverter`, access check de rota, Form API, `ConfigFormBase` com `#config_target`, Schema API, `flood`, `logger.channel`, event dispatcher (`VoteManager.php:98`), hooks orientados a objeto com `#[Hook]` e `initial preprocess` (`VotingHooks.php:55-57`), Drush 13 com atributos, `locale` com `.po` por projeto, tema base `stable9`.
- `lando phpcs` com `Drupal` + `DrupalPractice` limpo (`phpcs.xml.dist`).

### 6.5 Alto volume de votações concorrentes com integridade — Coberto
- Unique key + transação + rollback: `voting.install:48`, `VoteRepository.php:47-83`.
- Incremento atômico na linha da opção, sem serializar a pergunta: `VoteRepository.php:63`; `MERGE` só como fallback (`:71`).
- Conexão em `READ COMMITTED`: `sites/default/settings.php:913`.
- Reconciliação: `VoteRepository::rebuildTally()` (`:128`), `drush voting:integrity --repair` (`modules/custom/voting/src/Drush/Commands/VotingCommands.php:32,55`), aba Results com comparação (`modules/custom/voting/src/Controller/AdminResultsController.php:42,57`).
- Testes: duplicata concorrente sem pré-check (`VoteManagerTest.php:115`), flood (`:222,237`), cascata (`:258`). Ver DECISOES §4.

### 6.6 Observabilidade efetiva — Coberto
- Canal `logger.channel.voting`: `voting.services.yml:2`; `info` por voto registrado (`VoteManager.php:97`), `notice` por rejeição com o código (`:173`), `warning`/`notice` em exclusões (`VotingHooks.php:95,116`), `error` em falha inesperada da API (`ApiExceptionSubscriber.php:136`). Sempre com contexto estruturado (`@uid`, `@question`, `@option`, `@code`).
- `GET /api/v1/health` com estado do banco e da chave geral: `HealthController.php:49`.
- Aba Results com integridade contador × linhas brutas; `drush voting:stats` e `voting:integrity` (`VotingCommands.php:32,72`).

### 6.7 Foco em backend; interface sem guia de estilo — Coberto, além do pedido
- Nenhum requisito visual foi trocado por lógica: o tema `votacao` é bônus, sem contrib, sem dependência em runtime, com CSS versionado (DECISOES §22). O backend é coberto por 18 testes automatizados (`modules/custom/voting/tests/src/Kernel/VoteManagerTest.php:83-258`, `modules/custom/voting_api/tests/src/Functional/VotingApiTest.php:77-213`).

---

## Resumo

| Bloco do PDF | Itens | Cobertos |
|---|---|---|
| Interface administrativa | 4 | 4 |
| Interação no CMS | 3 | 3 |
| API externa | 4 | 4, mais health e paginação |
| Não funcionais | 2 | 2 |
| Técnicos | 5 | 5 |
| Critérios de avaliação | 7 | 7 |

Nenhuma lacuna aberta. Premissas assumidas onde o PDF é omisso: anônimo não vota; resultado só para quem votou; toda a API autenticada exceto health; identificador é um slug definido pelo admin.
