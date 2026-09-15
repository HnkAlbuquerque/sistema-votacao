# Documento de estudo – por que cada escolha

Guia curto, em linguagem direta, para entender e defender as decisões do projeto.
Atualizado em 15/09/2026 para refletir o código implementado.
Cada seção segue o mesmo formato: **o que foi decidido**, **alternativas**, **por quê**, **o que perderíamos com a alternativa**.
A seção final lista perguntas que costumam aparecer em revisão, com a resposta curta.

---

## 1. Content entities customizadas em vez de node

**Decidido:** `voting_question` e `voting_option` são content entities próprias, com campos definidos em código (*base fields*), declaradas com o atributo PHP `#[ContentEntityType]` do Drupal 11.

**Alternativas:** node com content type; config entities; tabelas puras.

**Por quê:**
- O PDF proíbe node. Mas mesmo sem a proibição, node traz bagagem que não usamos: revisões, promoção para a home, comentários, menu, campos de body.
- Content entity ainda entrega o que importa da "melhor prática Drupal": CRUD com Entity API, formulários automáticos, access handler, cache tags, rotas de admin geradas pelo `AdminHtmlRouteProvider`, validação por constraints.
- *Base fields* em código ficam versionados junto com o módulo. Não dependem de exportar configuração de campo e não permitem que alguém apague um campo pela UI e quebre a API.

**O que perderíamos:** config entity não serve porque perguntas são conteúdo criado pelo admin em produção, não configuração de deploy. Tabela pura perderia formulários, validação e access control de graça.

**Observação:** o módulo `node` aparece habilitado porque o perfil `minimal` o instala. Nenhuma entidade, rota ou serviço do sistema o usa.

---

## 2. Opção de resposta como entidade separada, não como campo multivalor

**Decidido:** `voting_option` é uma entidade própria com referência à pergunta e campo `weight`. Tem storage próprio (`OptionStorage::loadByQuestion()`), access handler que delega à pergunta e formulários que sempre voltam para a aba Options.

**Alternativas:** campo multivalor customizado na pergunta com subcampos imagem, título e descrição; Paragraphs.

**Por quê:**
- O voto precisa apontar para "uma opção". Com entidade, o voto guarda um `option_id` estável. Com campo multivalor, guardaria um delta, que muda quando o admin reordena ou remove uma opção. Isso corromperia resultados.
- Imagem é um campo de arquivo. Usando o tipo `image` do core numa entidade, `file_usage`, validação de extensão/tamanho e image styles vêm de graça. Dentro de um campo composto customizado, isso vira trabalho manual.
- Paragraphs resolveria, mas é dependência contrib pesada para um teste focado em backend e em "código organizado".

---

## 3. Voto em tabela própria, não em entidade

**Decidido:** tabelas `voting_vote` e `voting_result` via Schema API, acessadas por `VoteRepository` (só `Connection`), atrás de `VoteRepositoryInterface`.

**Alternativas:** content entity `voting_vote`; módulo contrib Voting API.

**Por quê:**
- O voto é o registro de maior volume do sistema, é imutável e nunca é editado por formulário. Entity API cobra um preço a cada save: hooks, invalidação de cache tag de lista, carregamento de field definitions. Nada disso é útil aqui.
- Uma tabela própria permite declarar `UNIQUE (question_id, uid)` direto no schema. Essa constraint é a única garantia real de "um voto por usuário" quando duas requisições chegam no mesmo milissegundo. Verificar antes com um SELECT não basta: as duas requisições leem "ainda não votou" e as duas inserem.
- `voting_result` guarda o contador por opção. Cada voto faz `UPDATE ... votes = votes + 1`, que é atômico no InnoDB. Ler o resultado vira um SELECT de N linhas pela chave primária, em vez de um `COUNT(*) GROUP BY` sobre milhões de votos.

**O que perderíamos:** integração automática com Views e listagem de votos individuais na UI. Como votos individuais nunca são exibidos, não faz falta. Se um dia fizer, `hook_views_data` resolve.

---

## 4. Transação sem lock explícito, com pré-check barato e contador pré-semeado

**Decidido:** `recordVote()` faz insert do voto e incremento do contador dentro de uma transação. Nenhum `SELECT ... FOR UPDATE`. Antes disso, o `VoteManager` faz um `SELECT` indexado (`hasVoted()`) e sai cedo se o usuário já votou. A linha do contador é criada com zero quando a opção é salva (`hook_voting_option_insert`).

**Por quê:**
- O unique index já serializa a disputa entre dois votos do mesmo usuário. O segundo insert falha com `IntegrityConstraintViolationException`, que o repositório converte em `AlreadyVotedException`. A transação faz rollback e o contador não é tocado. O teste `testRepositoryRejectsDuplicateAndKeepsCounterConsistent` prova isso chamando o repositório direto, sem o pré-check.
- Lock explícito na linha da pergunta transformaria a pergunta num gargalo: todos os votos daquela pergunta ficariam em fila. O unique index só "tranca" pares (pergunta, usuário), que nunca colidem entre usuários diferentes.
- O incremento é um único UPDATE por linha de opção. O InnoDB trava só aquela linha por microssegundos.
- O pré-check `hasVoted()` não é a garantia, é otimização: no caso comum (duplo clique, refresh) evita pagar um insert que vai falhar mais o rollback. Custa um SELECT pelo unique index. Se perder a corrida, o insert falha do mesmo jeito e o log marca `@race = yes`.
- Pré-semear o contador quando a opção é criada faz o caminho quente ser sempre um `UPDATE` de uma linha. O `MERGE` só entra se a linha não existir (opção criada antes da instalação, contadores reconstruídos).
- A conexão MySQL roda em `READ COMMITTED`, padrão do Drupal, que reduz gap locks em inserts concorrentes.

**Rede de segurança:** `drush voting:integrity` recalcula os contadores a partir da tabela de votos e mostra divergências; `--repair` reconstrói. A aba Results do admin mostra a mesma comparação.

---

## 5. Dois módulos: `voting` e `voting_api`

**Decidido:** domínio em um módulo, transporte HTTP em outro.

**Alternativa:** um módulo só.

**Por quê:**
- O PDF avalia "separação de responsabilidades e modularidade". Separar em módulos torna isso visível na estrutura de pastas, não só nos comentários.
- `voting_api` não contém regra de negócio. Se amanhã trocarem a autenticação ou quiserem GraphQL, o domínio não muda.
- A regra prática: nenhum controller fala com o banco. Tudo passa por `VoteManagerInterface`, e o `VoteManager` fala com o banco só pelo `VoteRepositoryInterface`. O formulário do CMS e o endpoint da API chamam exatamente o mesmo método, então não existe "bug que só acontece na API".

---

## 6. Endpoints manuais com Symfony/Drupal routing

**Decidido:** rotas em `voting_api.routing.yml`, três controllers que devolvem `JsonResponse` montado por `ApiResponse`, um serializer "burro" (`QuestionSerializer`) e um `EventSubscriber` que converte exceções em JSON.

**Alternativas:** JSON:API (proibido), REST module do core com plugins de resource.

**Por quê:**
- O módulo REST do core seria tecnicamente "manual", mas seus plugins de resource, formatos e autenticação configurados via config são confusos para revisar e fáceis de errar. Controllers simples são mais legíveis.
- `ApiResponse` concentra o envelope (`data`/`meta` ou `error`) e os cabeçalhos de cache. Nenhum controller monta JSON à mão.
- O serializer só transforma entidades e o DTO de resultado em arrays. Não faz access check nem I/O, então é trivial de testar e de trocar.
- Um subscriber único traduz `AlreadyVotedException` em 409, `InvalidOptionException` em 422 e assim por diante. Controllers não têm `try/catch`, e a resposta de erro tem sempre o mesmo formato.
- A listagem é paginada por `page` e `limit` (padrão 20, máximo 100), com `meta` trazendo `total` e `pages`. Sem teto, um cliente poderia pedir milhares de perguntas com as opções contadas uma a uma; com o teto, o custo por requisição é limitado e cada página é uma entrada de cache própria.

---

## 7. Identificador como slug definido pelo admin

**Decidido:** campo `identifier` único, validado por regex e constraint `UniqueField`, editado com o elemento `machine_name` (gerado a partir do título, travado após criação).

**Alternativas:** usar o `id` numérico; usar o `uuid`.

**Por quê:**
- O PDF fala em "identificador único" cadastrado pelo admin. Um slug como `melhor-linguagem-2026` é legível na URL, no Postman e nos logs.
- `id` numérico vaza a ordem de criação e muda entre ambientes ao importar dump. `uuid` é estável mas ilegível.
- Travar após a criação protege os clientes externos: a URL de uma pergunta nunca muda depois de publicada.
- Um `ParamConverter` próprio (`voting_question:identifier`) carrega a pergunta pelo slug tanto para a rota do CMS quanto para as da API. Uma implementação, dois consumidores. As rotas de admin continuam usando o `id` numérico (`entity:voting_question`), que é o que o Entity API gera.

---

## 8. Só usuários autenticados votam

**Decidido:** anônimo vê perguntas, mas não vota. API autentica com `basic_auth` do core; cookie também é aceito (com header CSRF no POST) para chamadas feitas de dentro do site.

**Alternativas:** votar anônimo com cookie/IP; `simple_oauth`; API key com id de usuário externo.

**Por quê:**
- "Cada voto deve ser identificável e único por usuário." Sem um `uid`, não existe usuário. IP e cookie são triviais de burlar e não identificam ninguém.
- `basic_auth` é do core, não precisa de configuração de chaves e o Postman suporta nativamente. Para um teste, é o caminho mais curto sem inventar autenticação própria.
- O `_csrf_request_header_check` só é exigido quando a requisição chega com sessão (cookie). Basic Auth não tem sessão, então clientes externos não precisam de token CSRF.
- OAuth2 é a evolução natural para produção e está documentado como próximo passo. API key com id externo transferiria para o cliente a responsabilidade de garantir unicidade, o que o PDF pede que o Drupal garanta.

---

## 9. Kill switch em config, aplicado por access check

**Decidido:** `voting.settings.enabled`, lido por `VotingSettings::isEnabled()`. Um `AccessCheck` com `applies_to: _voting_enabled` bloqueia todas as rotas de negócio da API. No CMS, a página mostra aviso.

**Alternativa:** verificar dentro de cada controller.

**Por quê:**
- Access check é declarado na rota. Impossível esquecer em um endpoint novo: basta olhar o YAML.
- O `AccessResult` carrega a cache tag `config:voting.settings`. Ao desligar a votação, o cache invalida sozinho. Sem isso, páginas cacheadas continuariam aceitando votos.
- O `VoteManager` também verifica, para que o serviço seja seguro mesmo se alguém o chamar de um contexto sem rota (drush, fila, teste).
- Um detalhe do subscriber: quando a votação está desligada, o core transformaria o 403 de um cliente anônimo em 401 "faça login". O listener de prioridade 90 responde `voting_disabled` antes disso, porque a resposta certa é "está desligado", não "autentique-se". `/api/v1/health` fica fora do switch de propósito: o monitor precisa saber que o sistema está de pé e que a votação está desligada.

---

## 10. Resultados sem cache, perguntas com cache

**Decidido:** GET de pergunta e listagem usam `CacheableJsonResponse` com cache tags. Voto e resultados respondem com `Cache-Control: no-store, private`.

**Por quê:**
- Perguntas mudam raramente. As tags `voting_question_list` e `voting_option_list` são as tags de lista que o Entity API já invalida ao salvar ou excluir qualquer entidade do tipo, e a resposta de uma pergunta ainda carrega as tags das entidades individuais. Nunca fica obsoleta e não exigiu código de invalidação.
- Resultado muda a cada voto. Invalidar uma cache tag por voto é uma escrita na tabela `cachetags` a cada requisição, que em alto volume vira contenção. A consulta de resultado, por outro lado, é um SELECT indexado de N linhas. É mais barato consultar do que invalidar.
- `no-store` também garante que proxies e o navegador não guardem uma resposta que contém `your_vote`.

---

## 11. Flood control no voto

**Decidido:** serviço `flood` do core, evento `voting.cast_vote`, limite e janela configuráveis no admin (padrão 20 tentativas em 60 s por usuário).

**Por quê:**
- Um usuário só consegue votar uma vez por pergunta, mas pode varrer todas as perguntas em segundos ou disparar rajadas concorrentes. Flood control limita o custo sem infraestrutura extra.
- É o mesmo mecanismo que o core usa no login, então revisores conhecem.
- A checagem vem logo depois da autenticação, antes de validar pergunta e opção. Assim toda tentativa conta, inclusive as rejeitadas por opção inválida, pergunta inativa ou voto repetido. Sem isso, um usuário poderia martelar o endpoint com payload inválido sem nunca ser limitado, pagando só SELECTs e linhas de log.
- O `register()` acontece antes do insert, então a tentativa conta mesmo que o insert falhe na corrida do unique index.

**O que perderíamos com a ordem inversa:** mensagens mais específicas depois de estourar o limite. Com o flood primeiro, a 21ª tentativa em um minuto recebe 429 mesmo que a opção esteja errada. Cliente legítimo não erra 20 vezes por minuto, então o custo é aceitável. Testado em `testFloodControlCountsRejectedAttempts`.

---

## 12. Observabilidade: log com contexto, health, integridade

**Decidido:** canal de log `voting`, endpoint `/api/v1/health`, aba admin de resultados com verificação e comandos drush.

**Por quê:**
- Log com contexto estruturado (`@uid`, `@question`, `@option`, `@code`) permite filtrar no dblog ou em qualquer agregador quando estiver em syslog. Rejeições são `notice` (esperadas, sem backtrace); falhas inesperadas na API são `error`.
- Health endpoint responde "o Drupal sobe, o banco responde, a votação está ligada", que é o que um load balancer ou monitor precisa. Devolve 503 se o banco não responde.
- Divergência entre contador e votos brutos é o único bug silencioso possível nesse desenho. Por isso existe verificação visível na UI e por linha de comando, com reparo.

---

## 13. Strings em inglês com `t()`, tradução por `.po`, docs em português

**Decidido:** código e strings de origem em inglês; interface em português via `locale`, com o core traduzido por localize.drupal.org e os projetos próprios por arquivos `.po` versionados em `translations/`; documentação em português.

**Alternativas:** escrever as strings direto em português no código; traduzir pela UI do Drupal (`/admin/config/regional/translate`) e depender só do dump.

**Por quê:**
- É a convenção do Drupal e o que o padrão de código `Drupal` do phpcs espera. Strings em inglês são a chave de tradução; qualquer idioma entra sem tocar no código.
- Strings em português no código travariam o site num idioma e ficariam fora do fluxo do `locale`. Traduzir só pela UI deixaria a tradução presa ao banco: um deploy em ambiente novo perderia tudo.
- Com o `.po` no projeto e o `interface translation server pattern` no `info.yml`, o `locale:update` importa nossas traduções pelo mesmo caminho das do core. O arquivo é revisável em code review e o `msgfmt` valida a sintaxe.
- As mensagens da API seguem o mesmo mecanismo. O cliente programa contra o campo `code`, que é estável; a mensagem é para humanos e sai no idioma do site.
- Documentação em português porque quem avalia lê em português.

---

## 14. Perfil `minimal`, sem contrib

**Decidido:** instalação `minimal` com os módulos do core que o sistema usa. Nenhum módulo contrib.

**Por quê:**
- Cada módulo a mais é código que o avaliador precisa descontar do que foi feito por nós. Sem contrib, tudo que existe é core ou nosso.
- `minimal` evita instalar comment, search, taxonomy e outros que o PDF não pede. Ele instala `node`, `block`, `dblog`, `page_cache` e `dynamic_page_cache` por conta própria.
- Menos código carregado por request, o que conversa com o critério de performance.
- `navigation` (a sidebar nova do Drupal 11) foi ligado para o admin ter a experiência padrão da versão; ele puxa `layout_builder`, `contextual` e `breakpoint` como dependências. `field_ui` está ligado só por conveniência de inspeção; os campos são base fields e não dependem dele.

---

## 15. `VoteManager` devolve um DTO (`QuestionResults`)

**Decidido:** `castVote()` e `getResults()` devolvem um objeto imutável com pergunta, opções em ordem, contagens (ou `NULL` quando ocultas) e a opção em que o usuário votou.

**Alternativa:** devolver arrays; devolver contagens sempre e deixar cada consumidor decidir o que mostrar.

**Por quê:**
- A regra "pode ver os totais?" é decidida uma vez, no serviço. Quando as contagens vêm `NULL`, o CMS e o serializer só omitem. Nenhum dos dois consegue vazar total por descuido.
- Total e percentual são calculados no DTO, então a tabela do CMS e o JSON da API mostram exatamente os mesmos números.

---

## 16. Uma exceção por regra, com código estável

**Decidido:** `VotingException` abstrata com `getErrorCode()` e `getUserMessage()`; oito subclasses (`AlreadyVoted`, `InvalidOption`, `QuestionInactive`, `VotingDisabled`, `AuthenticationRequired`, `VoteRateLimited`, `ResultsUnavailable` com dois códigos, e a base).

**Alternativa:** uma exceção genérica com string; retornar códigos de erro em vez de lançar.

**Por quê:**
- O subscriber da API mapeia classe → status HTTP em uma tabela; o `VoteForm` mostra `getUserMessage()` direto. Nenhum dos dois inspeciona mensagens.
- O código (`already_voted`, `vote_required`…) aparece no JSON, no log e na collection do Postman. Cliente externo programa contra o código, não contra o texto.

---

## 17. Resultado só para quem votou, com permissão de bypass

**Decidido:** `getResults()` exige que a conta tenha votado (`vote_required`) e que a pergunta permita (`results_hidden`). A permissão `view voting results` ignora as duas regras.

**Alternativa:** qualquer autenticado vê o resultado de qualquer pergunta que permita.

**Por quê:**
- O PDF diz "após a votação, os resultados devem ser mostrados". Ler o total antes de votar permitiria "votar no vencedor" e esvaziaria o sentido da configuração por pergunta.
- Admins e analistas precisam ver totais de perguntas com resultado oculto sem votar nelas. Uma permissão separada resolve sem abrir exceção no código.
- No CMS a regra é a mesma, só muda a apresentação: quem não votou vê o formulário; quem votou em pergunta oculta vê o agradecimento.

---

## 18. Hooks orientados a objeto e atributos PHP

**Decidido:** hooks em `src/Hook/VotingHooks.php` com `#[Hook('voting_option_insert')]` etc.; entidades declaradas com `#[ContentEntityType]`; comandos drush com atributos `#[CLI\Command]`.

**Alternativa:** `voting.module` com funções procedurais e anotações `@ContentEntityType`.

**Por quê:**
- É a forma recomendada no Drupal 11. A classe de hooks recebe serviços por injeção e é testável; não há `\Drupal::service()` espalhado.
- Mostra ao avaliador domínio das práticas atuais, um dos critérios explícitos.

---

## 19. Exclusão em cascata, sem soft delete

**Decidido:** apagar uma opção apaga seus votos e contador; apagar uma pergunta apaga opções, votos e contadores. O formulário de exclusão avisa, e a ação gera log.

**Alternativa:** impedir exclusão com votos; marcar como inativa em vez de apagar.

**Por quê:**
- Votos órfãos (apontando para opção inexistente) quebrariam a integridade que o resto do sistema promete.
- Para "tirar do ar" sem perder dados já existe o status `Active` da pergunta: inativa não lista nem aceita voto, mas mantém tudo.
- O admin recebe o aviso na confirmação e o log registra quantos votos foram embora.

---

## 20. Página "Quick links" no admin

**Decidido:** aba `/admin/content/voting/links` com todas as URLs do sistema, contas do dump e comandos úteis.

**Por quê:**
- O avaliador vai abrir o sistema uma vez. Uma página com tudo (rotas do CMS, rotas da API com o padrão `{id}`, permissões, log, comandos) reduz o tempo até ele ver o que importa.
- Custa um controller e um template; não toca o domínio.

---

## 21. Markup no módulo, aparência no tema

**Decidido:** as páginas públicas são montadas por theme hooks do módulo `voting` (`voting_question_list`, `voting_question`, `voting_option_card`, `voting_results`, `voting_notice`) com templates Twig que emitem as classes BEM. O tema só carrega CSS e sobrescreve templates de layout.

**Alternativa:** render arrays genéricos (`item_list`, `html_tag`) no controller e todo o markup no tema.

**Por quê:**
- É a divisão de responsabilidades do Drupal: o módulo define a estrutura, o tema define a aparência. Qualquer tema, inclusive o Olivero, mostra o sistema funcional.
- Com a classe definida uma vez na template do módulo, o SCSS do tema tem um contrato estável para estilizar. Trocar o tema não exige tocar em PHP.
- O `initial preprocess` (API do Drupal 11.3) converte a entidade em variáveis simples antes da template, então template e tema nunca tocam o Entity API.
- No formulário de voto, cada card é a label do radio: o usuário clica no card, não em um texto solto. A template usa `span` nesse caso porque `label` só aceita conteúdo de frase.

---

## 22. Tema próprio com design tokens, SCSS em BEM e build gulp

**Decidido:** tema `votacao` com `base theme: stable9`, tokens em JSON gerando CSS custom properties, SCSS organizado por bloco BEM, gulp para compilar e CSS versionado.

**Alternativas:** manter o Olivero; gerar pelo `starterkit_theme`; Tailwind; carregar fontes ou CSS de CDN.

**Por quê:**
- O PDF não avalia estilo, mas a demonstração fica mais convincente com uma interface coerente. A regra foi não deixar isso custar nada ao que é avaliado: sem contrib, sem JS de framework, sem requisição externa, e o CSS compilado entra no git para que `lando start` já mostre o tema sem Node.
- `stable9` dá markup limpo e nenhum CSS de opinião; o `starterkit` copiaria dezenas de arquivos CSS que seriam apagados em seguida.
- Tokens em JSON são a fonte única. O modelo semântico do shadcn/ui (`background`, `foreground`, `primary`, `muted`, `border`, `ring`, `radius`) resolve light e dark com as mesmas variáveis, e a paleta Zinc + Indigo dá identidade sem competir com o conteúdo. Trocar a cor primária é editar uma linha e rodar o build.
- BEM deixa cada componente autocontido e legível: `.option-card__title`, `.results__row--mine`. Nenhum seletor depende da estrutura do DOM do Drupal além das classes que o próprio módulo emite.
- Gulp é suficiente para o pipeline tokens → sass → autoprefixer. Sem bundler.
- O único JavaScript do tema é o botão de light/dark: sem escolha salva o site segue o sistema; com escolha, `data-theme` no `<html>` sobrepõe. Um script inline no `<head>` aplica a escolha antes do CSS pintar, para não piscar. Os tokens são gerados já com as três condições (padrão claro, `prefers-color-scheme: dark` sem forçar claro, `data-theme="dark"`).

**O que perderíamos com Tailwind ou CDN:** dependência em runtime ou classes utilitárias espalhadas pelas templates do módulo, o que quebraria a separação da seção 21.

---

## Perguntas que costumam aparecer

- **"E se dois votos do mesmo usuário chegarem juntos?"** O segundo insert viola `UNIQUE (question_id, uid)`, a transação faz rollback e a API responde 409. Testado sem o pré-check em `testRepositoryRejectsDuplicateAndKeepsCounterConsistent`.
- **"Por que não usar entidade para o voto?"** Volume, imutabilidade, unique index nativo e zero invalidação de cache por voto. Seção 3.
- **"Como o resultado escala?"** `voting_result` é lido por chave primária; nenhum `COUNT(*)`. Divergência é detectável e reparável. Seções 3, 4 e 12.
- **"Por que não cachear resultados?"** Invalidar tag a cada voto custa mais do que um SELECT de N linhas. Seção 10.
- **"Por que o módulo node está ligado?"** Perfil `minimal`. Nada do sistema o usa. Seção 14.
- **"Anônimo pode votar?"** Não: sem `uid` não há "um voto por usuário". Seção 8.
- **"Por que Basic Auth?"** Core, sem config, suportado pelo Postman. OAuth2 é o próximo passo. Seção 8.
- **"Onde estão as regras de negócio?"** Só em `VoteManager`. CMS e API são transportes. Seção 5.
- **"Como sei que o sistema está saudável?"** `/api/v1/health`, log `voting`, aba Results, `drush voting:integrity`. Seção 12.
- **"O que acontece ao desligar a votação?"** Access check em toda rota da API, aviso no CMS, cache invalidado pela tag da config, health continua respondendo. Seção 9.
- **"O tema precisa de Node para rodar?"** Não. O CSS compilado está no repositório; Node só para quem alterar o SCSS. Seção 22.
