# Sistema de Votação Simples – Drupal 11

Sistema de votação em Drupal 11 onde o administrador cadastra perguntas com opções de resposta, usuários autenticados votam uma única vez por pergunta e os resultados são exibidos conforme a configuração de cada pergunta. Inclui uma API JSON escrita à mão para aplicações externas.

Documentos complementares:

- [docs/ARQUITETURA.md](docs/ARQUITETURA.md) – rastreabilidade requisito a requisito, modelo de dados, fluxo de voto, API.
- [docs/DECISOES.md](docs/DECISOES.md) – por que cada escolha foi feita e o que foi descartado.

## Stack

| Item | Versão |
|---|---|
| Drupal | 11.4 (perfil `minimal`, sem módulos contrib) |
| PHP | 8.3 |
| MySQL | 8.0 |
| Lando | 3.x |
| Drush | 13 |
| Node | 20 (só para compilar o tema) |

## Subindo o ambiente

```bash
# via SSH
git clone git@github.com:HnkAlbuquerque/sistema-votacao.git votacao
# ou via HTTPS
git clone https://github.com/HnkAlbuquerque/sistema-votacao.git votacao

cd votacao
lando start
lando composer install
lando db-import db/dump.sql.gz
lando drush cr
```

URLs após o `lando start`: `https://votacao.lndo.site` (ou a porta local que o Lando imprimir).

Alternativa sem o dump, instalando do zero a partir da configuração exportada:

```bash
lando drush site:install minimal --existing-config --account-pass=admin -y
```

### Credenciais do dump

| Usuário | Senha | Papel |
|---|---|---|
| admin | admin | administrador |
| alice | alice | usuário comum |
| bob | bob | usuário comum |
| ana, bruno, carla, diego, elena, fabio, gabriela, henrique, isabela, joao | igual ao nome de usuário | usuários comuns |

O dump traz duas perguntas de exemplo com votos, `melhor-linguagem` (resultados visíveis) e `framework-favorito` (resultados ocultos), mais 100 perguntas de conhecimentos gerais com quatro alternativas cada, em dez temas (geografia, história, ciências, artes, esportes, matemática, tecnologia, literatura, cinema e música). Dessas, uma em cada dez oculta os totais e as três últimas estão inativas, para demonstrar cada estado.

## Uso no CMS

**Administração** (`/admin/content/voting`, requer `administer voting`):

1. **Add question**: título, identificador (gerado do título, travado depois de salvo), descrição, "Show results after voting" e "Active".
2. Ao salvar, o admin cai na aba **Options** da pergunta: tabela arrastável para ordenar, ação **Add option** com título, descrição e imagem.
3. Aba **Results**: total por opção, percentual e verificação de integridade dos contadores.
4. **Configuration » System » Voting settings** (`/admin/config/system/voting`): chave geral para desabilitar a votação e limite de tentativas por usuário.

**Votação** (`/votacao` e `/votacao/{identificador}`): a lista mostra 12 perguntas por página. O usuário logado vê as opções como cards selecionáveis e um botão de voto. Depois de votar, vê as barras de resultado com a própria escolha destacada, ou apenas a confirmação, conforme a pergunta. Anônimo vê a pergunta e um link de login.

## Tema e build do front-end

O site público usa o tema custom `votacao` (`web/themes/custom/votacao`), baseado no `stable9` do core. O admin continua no Claro.

- **Design tokens** em `tokens/design-tokens.json`: cores em HSL (modelo do shadcn/ui: `background`, `foreground`, `primary`, `muted`, `border`, `ring`…), espaçamento, raio, tipografia, sombras, breakpoints. O build gera `scss/abstracts/_tokens.generated.scss` com as custom properties para light e dark.
- **SCSS em BEM**: um arquivo por bloco em `scss/components/` (`option-card`, `results`, `question-list`, `button`, `badge`, `notice`, `form`…), com `layout/` e `base/`. Os nomes de classe são emitidos pelas templates do módulo `voting`, então o tema só estiliza.
- **Dark mode**: segue o sistema por padrão e tem um botão no cabeçalho para alternar. A escolha fica em `localStorage` e é aplicada antes da primeira pintura, sem piscar. É o único JavaScript do tema.
- **Sem dependência em runtime**: nenhuma fonte externa, CDN ou framework. O CSS compilado (`css/main.css`) é versionado, então `lando start` já entrega o tema pronto.

Só quem for alterar o SCSS precisa do Node:

```bash
lando npm install          # uma vez
lando gulp build           # tokens → SCSS → css/main.css
lando gulp watch           # recompila ao salvar
```

Fora do Lando, com Node 20 instalado, `npm install` e `npx gulp build` dentro de `web/themes/custom/votacao` fazem o mesmo.

## Permissões

| Permissão | Padrão no dump | Para quê |
|---|---|---|
| `administer voting` | administrador | CRUD, settings, aba de resultados |
| `view voting questions` | anônimo, autenticado | ver perguntas no site e na API |
| `vote in voting questions` | autenticado | votar no site e na API |
| `view voting results` | ninguém (além do admin) | ver totais mesmo com resultados ocultos e sem ter votado |

## API externa

Prefixo `/api/v1`. Respostas sempre em JSON com envelope:

```json
{ "data": { ... }, "meta": { ... } }
{ "error": { "code": "already_voted", "message": "You have already voted on this question." } }
```

Autenticação: **HTTP Basic** com usuário e senha do Drupal (módulo `basic_auth` do core). Leituras públicas não exigem credenciais.

| Método | Rota | Auth | Descrição |
|---|---|---|---|
| GET | `/api/v1/questions` | não | Perguntas ativas |
| GET | `/api/v1/questions/{id}` | não | Pergunta com opções |
| POST | `/api/v1/questions/{id}/vote` | sim | Registra o voto. Body `{"option_id": 3}` ou `option_id=3` |
| GET | `/api/v1/questions/{id}/results` | sim | Resultados, se a pergunta permitir e o usuário já tiver votado |
| GET | `/api/v1/health` | não | Estado do banco e da chave geral |

`{id}` é o identificador (slug) da pergunta.

### Exemplos

```bash
curl https://votacao.lndo.site/api/v1/questions
curl https://votacao.lndo.site/api/v1/questions/melhor-linguagem
curl -u alice:alice -X POST -H 'Content-Type: application/json' \
     -d '{"option_id": 2}' https://votacao.lndo.site/api/v1/questions/melhor-linguagem/vote
curl -u alice:alice https://votacao.lndo.site/api/v1/questions/melhor-linguagem/results
```

Resposta do voto (`201 Created`):

```json
{
  "data": {
    "question_id": "melhor-linguagem",
    "results_visible": true,
    "total_votes": 3,
    "your_vote": 2,
    "options": [
      { "id": 1, "title": "PHP", "votes": 1, "percentage": 33.3 },
      { "id": 2, "title": "Go", "votes": 2, "percentage": 66.7 }
    ]
  }
}
```

Quando a pergunta oculta os resultados, `results_visible` é `false`, `total_votes` é `null` e as opções vêm sem `votes`.

### Códigos de erro

| HTTP | `code` | Quando |
|---|---|---|
| 400 | `invalid_payload` | body não é JSON válido ou `option_id` ausente/inválido |
| 401 | `authentication_required` | rota exige login e não veio credencial |
| 401 | `invalid_credentials` | usuário ou senha incorretos |
| 403 | `voting_disabled` | chave geral desligada (vale para todas as rotas, exceto health) |
| 403 | `vote_required` | resultados pedidos antes de votar |
| 403 | `results_hidden` | pergunta configurada para ocultar resultados |
| 403 | `question_inactive` | pergunta inativa |
| 403 | `access_denied` | sem permissão |
| 404 | `not_found` | identificador desconhecido |
| 405 | `method_not_allowed` | método errado |
| 409 | `already_voted` | usuário já votou nessa pergunta |
| 422 | `invalid_option` | `option_id` não pertence à pergunta |
| 429 | `rate_limited` | limite de tentativas excedido |
| 500 | `server_error` | erro inesperado (detalhes só no log) |

### Postman

Importe `postman/voting-api.postman_collection.json` e `postman/voting-local.postman_environment.json`. A collection tem testes automáticos em cada requisição e encadeia `question_id` e `option_id` a partir das respostas.

## Estrutura do código

```
web/modules/custom/
├── voting/        domínio: entidades, storage de votos, serviço, CMS, admin
│   ├── templates/             Twig das páginas públicas (lista, pergunta, card, resultados, aviso)
│   ├── src/Theme/             preprocess do card de opção
│   ├── src/Entity/            Question, Option (content entities, sem node)
│   ├── src/Storage/           VoteRepository (tabelas voting_vote e voting_result), OptionStorage
│   ├── src/Service/           VoteManager (todas as regras de negócio), QuestionResults (DTO)
│   ├── src/Exception/         uma exceção por regra violada, com código estável
│   ├── src/Access/            access handlers e o access check do kill switch
│   ├── src/Form/              formulários admin, aba de opções, settings, formulário de voto
│   ├── src/Controller/        páginas públicas e aba de resultados
│   ├── src/Hook/              hooks (cascata de exclusão, pré-semeadura dos contadores)
│   ├── src/Drush/Commands/    voting:integrity, voting:stats
│   └── tests/src/Kernel/      regras do VoteManager
└── voting_api/    transporte HTTP, sem regra de negócio
    ├── src/Controller/        endpoints
    ├── src/Serializer/        entidades e resultados para arrays
    ├── src/Response/          envelope JSON e cacheabilidade
    ├── src/EventSubscriber/   exceções para JSON com status HTTP correto
    └── tests/src/Functional/  API ponta a ponta com Basic Auth

web/themes/custom/votacao/   tema do site público
├── tokens/design-tokens.json  fonte única de cores, espaçamento, tipografia
├── scss/                      abstracts, base, layout, components (BEM)
├── css/main.css               build versionado
├── templates/                 html, page, region, branding, menu de conta, abas
└── gulpfile.js                tokens → SCSS → CSS
```

Regra central: nenhum controller ou formulário toca o banco. Tudo passa por `VoteManagerInterface`, e o `VoteManager` fala com o banco só pelo `VoteRepositoryInterface`.

## Concorrência e integridade

- `voting_vote` tem `UNIQUE (question_id, uid)`. Dois votos simultâneos do mesmo usuário: o segundo falha no banco, a transação sofre rollback e a API devolve 409.
- `voting_result` guarda contadores por opção, incrementados com `UPDATE ... votes = votes + 1` na mesma transação do insert. Ler resultados nunca faz `COUNT(*)`.
- Teste realizado localmente: 20 requisições paralelas do mesmo usuário resultaram em 1 aceito e 19 rejeitados; 200 usuários votando em paralelo terminaram com contadores iguais às linhas brutas.

## Observabilidade

- Canal de log `voting`: `info` em voto registrado, `notice` em rejeição (com código), `warning`/`notice` em exclusões, `error` em falhas inesperadas da API. Ver em `/admin/reports/dblog` filtrando o tipo `voting`, ou `lando drush watchdog:show --type=voting`.
- `GET /api/v1/health` para monitoramento.
- Aba **Results** de cada pergunta compara contadores com as linhas brutas.
- Comandos:

```bash
lando drush voting:stats              # perguntas, opções e votos
lando drush voting:integrity          # detecta divergência entre contador e votos
lando drush voting:integrity --repair # reconstrói os contadores divergentes
```

## Testes e qualidade

```bash
lando phpunit    # Kernel + Functional
lando phpcs      # padrões Drupal e DrupalPractice nos módulos e no tema (phpcs.xml.dist)
```

## Exportar configuração e dump

```bash
lando drush cex -y                    # config/sync
lando drush sql:dump --gzip --result-file=/app/db/dump.sql \
  --structure-tables-list='cache*,flood,sessions,batch,queue' \
  --extra-dump=--no-tablespaces       # gera db/dump.sql.gz sem dados de cache
```
