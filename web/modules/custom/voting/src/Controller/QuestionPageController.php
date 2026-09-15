<?php

declare(strict_types=1);

namespace Drupal\voting\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Exception\VotingException;
use Drupal\voting\Form\VoteForm;
use Drupal\voting\Service\QuestionResults;
use Drupal\voting\Service\VoteManagerInterface;
use Drupal\voting\VotingSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public pages: question list and the voting page of a single question.
 *
 * Markup lives in the module templates (voting-question-list,
 * voting-question, voting-option-card, voting-results, voting-notice), so
 * this controller only assembles data and picks what the user sees.
 */
final class QuestionPageController extends ControllerBase {

  /**
   * Questions per page on the public list.
   */
  private const PAGE_SIZE = 12;

  public function __construct(
    private readonly VoteManagerInterface $voteManager,
    private readonly VotingSettings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('voting.vote_manager'),
      $container->get('voting.settings'),
    );
  }

  /**
   * Title callback for the question page.
   */
  public function title(QuestionInterface $voting_question): string {
    return $voting_question->getTitle();
  }

  /**
   * Lists the active questions.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('title')
      ->pager(self::PAGE_SIZE)
      ->execute();

    $questions = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\voting\Entity\QuestionInterface $question */
      $questions[] = [
        'title' => $question->getTitle(),
        'url' => Url::fromRoute('voting.question', ['voting_question' => $question->getIdentifier()])->toString(),
        'description' => $question->getDescription(),
        'options_count' => count($question->getOptions()),
        'show_results' => $question->showsResults(),
      ];
    }

    $build = [
      'list' => [
        '#theme' => 'voting_question_list',
        '#questions' => $questions,
        '#disabled' => !$this->settings->isEnabled(),
      ],
      // The pager element adds the "url.query_args.pagers:0" cache context.
      'pager' => [
        '#type' => 'pager',
        '#quantity' => 5,
      ],
    ];

    (new CacheableMetadata())
      ->addCacheTags(Cache::mergeTags(['voting_question_list', 'voting_option_list'], $this->settings->getCacheTags()))
      ->addCacheContexts(['user.permissions'])
      ->applyTo($build);

    return $build;
  }

  /**
   * Shows a question with its vote form, or the outcome for this user.
   */
  public function page(QuestionInterface $voting_question): array {
    $outcome = $this->buildOutcome($voting_question);
    // The vote form renders the option cards itself, as radio labels.
    $showCards = empty($outcome['#is_vote_form']);

    $build = [
      '#theme' => 'voting_question',
      '#description' => $voting_question->getDescription(),
      '#options' => $showCards ? $this->buildOptionCards($voting_question, $outcome['#user_option_id'] ?? NULL) : [],
      '#outcome' => $outcome,
    ];

    (new CacheableMetadata())
      ->addCacheableDependency($voting_question)
      ->addCacheTags(Cache::mergeTags(['voting_option_list'], $this->settings->getCacheTags()))
      ->addCacheContexts(['user'])
      ->applyTo($build);

    return $build;
  }

  /**
   * Builds one card per option, marking the one the user voted for.
   *
   * @return array[]
   *   Render arrays.
   */
  private function buildOptionCards(QuestionInterface $question, ?int $userOptionId): array {
    $cards = [];
    foreach ($question->getOptions() as $id => $option) {
      $cards[] = [
        '#theme' => 'voting_option_card',
        '#option' => $option,
        '#selected' => (int) $id === $userOptionId,
      ];
    }
    return $cards;
  }

  /**
   * Decides what this user sees below the options: form, results or notice.
   */
  private function buildOutcome(QuestionInterface $question): array {
    $account = $this->currentUser();

    if (!$this->settings->isEnabled()) {
      return $this->notice($this->t('Voting is currently disabled.'), 'warning');
    }
    if (!$account->isAuthenticated()) {
      $login = Url::fromRoute('user.login', [], ['query' => ['destination' => Url::fromRoute('voting.question', ['voting_question' => $question->getIdentifier()])->toString()]]);
      return $this->notice($this->t('<a href=":url">Log in</a> to vote on this question.', [':url' => $login->toString()]));
    }
    if (!$account->hasPermission('vote in voting questions')) {
      return $this->notice($this->t('Your account is not allowed to vote.'), 'warning');
    }
    if (!$this->voteManager->hasVoted($question, $account)) {
      $form = $this->formBuilder()->getForm(VoteForm::class, $question);
      $form['#is_vote_form'] = TRUE;
      return $form;
    }

    try {
      $results = $this->voteManager->getResults($question, $account);
    }
    catch (VotingException) {
      // Results hidden for this question: acknowledge the vote only.
      return $this->notice($this->t('Thank you, your vote has been recorded. The results of this question are not public.'), 'success');
    }

    return $this->buildResults($results) + ['#user_option_id' => $results->userOptionId];
  }

  /**
   * Renders the totals shown after voting.
   */
  private function buildResults(QuestionResults $results): array {
    $rows = [];
    foreach ($results->options as $id => $option) {
      $rows[] = [
        'title' => $option->getTitle(),
        'votes' => $results->getVotes($option),
        'percentage' => $results->getPercentage($option),
        'mine' => (int) $id === $results->userOptionId,
      ];
    }

    return [
      '#theme' => 'voting_results',
      '#rows' => $rows,
      '#total' => $results->getTotal(),
      // Totals change with every vote; never serve them from cache.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Wraps a message in the notice template.
   */
  private function notice(mixed $message, string $type = 'info'): array {
    return [
      '#theme' => 'voting_notice',
      '#message' => $message,
      '#type' => $type,
    ];
  }

}
