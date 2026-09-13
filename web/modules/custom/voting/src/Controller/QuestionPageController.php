<?php

declare(strict_types=1);

namespace Drupal\voting\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Exception\VotingException;
use Drupal\voting\Form\VoteForm;
use Drupal\voting\Service\QuestionResults;
use Drupal\voting\Service\VoteManagerInterface;
use Drupal\voting\VotingSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public pages: question list and the voting page of a single question.
 */
final class QuestionPageController extends ControllerBase {

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
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\voting\Entity\QuestionInterface $question */
      $items[] = Link::createFromRoute($question->getTitle(), 'voting.question', [
        'voting_question' => $question->getIdentifier(),
      ])->toRenderable();
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('There are no questions open for voting right now.'),
    ];
    if (!$this->settings->isEnabled()) {
      $build['#prefix'] = '<p>' . $this->t('Voting is currently disabled.') . '</p>';
    }

    (new CacheableMetadata())
      ->addCacheTags(Cache::mergeTags(['voting_question_list'], $this->settings->getCacheTags()))
      ->addCacheContexts(['user.permissions'])
      ->applyTo($build);

    return $build;
  }

  /**
   * Shows a question with its vote form, or the outcome for this user.
   */
  public function page(QuestionInterface $voting_question): array {
    $build = [];
    if ($voting_question->getDescription() !== '') {
      $build['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => nl2br(htmlspecialchars($voting_question->getDescription(), ENT_QUOTES)),
      ];
    }
    $build['options'] = $this->buildOptionList($voting_question);
    $build['outcome'] = $this->buildOutcome($voting_question);

    (new CacheableMetadata())
      ->addCacheableDependency($voting_question)
      ->addCacheTags(Cache::mergeTags(['voting_option_list'], $this->settings->getCacheTags()))
      ->addCacheContexts(['user'])
      ->applyTo($build);

    return $build;
  }

  /**
   * Renders the option cards (image, title, description).
   */
  private function buildOptionList(QuestionInterface $question): array {
    $items = [];
    foreach ($question->getOptions() as $option) {
      $items[] = $this->buildOptionCard($option);
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('This question has no options yet.'),
    ];
  }

  /**
   * Renders one option: title, description and image.
   */
  private function buildOptionCard(OptionInterface $option): array {
    $card = [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => htmlspecialchars($option->getTitle(), ENT_QUOTES),
      ],
    ];
    if ($option->getDescription() !== '') {
      $card['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => htmlspecialchars($option->getDescription(), ENT_QUOTES),
      ];
    }
    if (!$option->get('image')->isEmpty()) {
      $card['image'] = $option->get('image')->view([
        'label' => 'hidden',
        'type' => 'image',
        'settings' => ['image_style' => 'medium', 'image_link' => ''],
      ]);
    }
    return $card;
  }

  /**
   * Decides what this user sees below the options: form, results or notice.
   */
  private function buildOutcome(QuestionInterface $question): array {
    $account = $this->currentUser();

    if (!$this->settings->isEnabled()) {
      return $this->notice($this->t('Voting is currently disabled.'));
    }
    if (!$account->isAuthenticated()) {
      $login = Url::fromRoute('user.login', [], ['query' => ['destination' => Url::fromRoute('voting.question', ['voting_question' => $question->getIdentifier()])->toString()]]);
      return $this->notice($this->t('<a href=":url">Log in</a> to vote on this question.', [':url' => $login->toString()]));
    }
    if (!$account->hasPermission('vote in voting questions')) {
      return $this->notice($this->t('Your account is not allowed to vote.'));
    }
    if (!$this->voteManager->hasVoted($question, $account)) {
      return $this->formBuilder()->getForm(VoteForm::class, $question);
    }

    try {
      $results = $this->voteManager->getResults($question, $account);
    }
    catch (VotingException) {
      // Results hidden for this question: acknowledge the vote only.
      return $this->notice($this->t('Thank you, your vote has been recorded. The results of this question are not public.'));
    }

    return $this->buildResultsTable($results);
  }

  /**
   * Renders the totals table shown after voting.
   */
  private function buildResultsTable(QuestionResults $results): array {
    $rows = [];
    foreach ($results->options as $id => $option) {
      $title = $option->getTitle();
      if ((int) $id === $results->userOptionId) {
        $title = $this->t('@title (your vote)', ['@title' => $title]);
      }
      $rows[] = [
        $title,
        $results->getVotes($option),
        $this->t('@percent%', ['@percent' => $results->getPercentage($option)]),
      ];
    }

    return [
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Results'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Option'), $this->t('Votes'), $this->t('Share')],
        '#rows' => $rows,
        '#footer' => [[$this->t('Total'), $results->getTotal(), '']],
      ],
      // Totals change with every vote; never serve them from cache.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Wraps a message in a paragraph.
   */
  private function notice(mixed $message): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $message,
    ];
  }

}
