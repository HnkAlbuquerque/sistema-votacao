<?php

declare(strict_types=1);

namespace Drupal\voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * One page with every relevant URL of the system, for demos and reviews.
 */
final class LinksController extends ControllerBase {

  /**
   * How many questions get their own row of links.
   */
  private const QUESTION_LIMIT = 20;

  /**
   * Builds the quick links page.
   */
  public function page(): array {
    $sections = [
      [
        'title' => $this->t('Administration'),
        'links' => [
          $this->link($this->t('Questions'), 'entity.voting_question.collection'),
          $this->link($this->t('Add question'), 'entity.voting_question.add_form'),
          $this->link($this->t('Voting settings (kill switch, rate limit)'), 'voting.settings'),
          $this->link($this->t('Permissions'), 'user.admin_permissions.module', ['modules' => 'voting']),
          $this->link($this->t('Log messages (type: voting)'), 'dblog.overview', [], ['type[]' => 'voting']),
        ],
      ],
      [
        'title' => $this->t('Public pages'),
        'links' => [
          $this->link($this->t('Question list'), 'voting.questions'),
          $this->link($this->t('Log in'), 'user.login'),
          $this->link($this->t('Log out'), 'user.logout'),
        ],
      ],
      [
        'title' => $this->t('API (5 routes, {id} is the question identifier, Basic Auth on all but health)'),
        'links' => [
          $this->link($this->t('GET health (public)'), 'voting_api.health'),
          $this->link($this->t('GET list questions'), 'voting_api.questions'),
          $this->pattern($this->t('GET question'), 'voting_api.question'),
          $this->pattern($this->t('POST vote (body option_id)'), 'voting_api.vote'),
          $this->pattern($this->t('GET results'), 'voting_api.results'),
        ],
      ],
    ];

    return [
      '#theme' => 'voting_links',
      '#sections' => $sections,
      '#questions' => $this->questionRows(),
      '#question_limit' => self::QUESTION_LIMIT,
      '#all_questions_url' => Url::fromRoute('entity.voting_question.collection')->toString(),
      '#accounts' => [
        ['admin', 'admin', $this->t('administrator')],
        ['alice', 'alice', $this->t('regular user')],
        ['bob', 'bob', $this->t('regular user')],
      ],
      '#commands' => [
        ['lando drush uli', $this->t('one-time admin login link')],
        ['lando drush voting:stats', $this->t('questions, options and votes')],
        ['lando drush voting:integrity --repair', $this->t('check and rebuild counters')],
        ['lando drush watchdog:show --type=voting', $this->t('voting log')],
        ['lando phpunit', $this->t('run the test suite')],
      ],
      '#cache' => [
        'tags' => ['voting_question_list'],
        'contexts' => ['url.site'],
      ],
    ];
  }

  /**
   * One row of links per question, newest first, capped at QUESTION_LIMIT.
   */
  private function questionRows(): array {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, self::QUESTION_LIMIT)
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\voting\Entity\QuestionInterface $question */
      $byId = ['voting_question' => $question->id()];
      $bySlug = ['voting_question' => $question->getIdentifier()];
      $rows[] = [
        'title' => $question->getTitle(),
        'identifier' => $question->getIdentifier(),
        'active' => $question->isPublished(),
        'links' => [
          $this->link($this->t('Vote page'), 'voting.question', $bySlug),
          $this->link($this->t('Edit'), 'entity.voting_question.edit_form', $byId),
          $this->link($this->t('Options'), 'voting.question.options', $byId),
          $this->link($this->t('Results'), 'voting.question.results', $byId),
          $this->link($this->t('API'), 'voting_api.question', $bySlug),
        ],
      ];
    }
    return $rows;
  }

  /**
   * Builds a [label, absolute URL] pair for the template.
   */
  private function link(mixed $label, string $route, array $parameters = [], array $query = []): array {
    $options = ['absolute' => TRUE];
    if ($query) {
      $options['query'] = $query;
    }
    return [$label, Url::fromRoute($route, $parameters, $options)->toString()];
  }

  /**
   * Builds a [label, URL pattern] pair with a literal "{id}" placeholder.
   *
   * The placeholder survives URL generation because it is not a valid
   * identifier, so the resulting string documents the route shape.
   */
  private function pattern(mixed $label, string $route): array {
    $url = Url::fromRoute($route, ['voting_question' => '{id}'], ['absolute' => TRUE])->toString();
    return [$label, rawurldecode($url)];
  }

}
