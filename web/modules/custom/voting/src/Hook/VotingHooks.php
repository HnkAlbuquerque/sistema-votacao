<?php

declare(strict_types=1);

namespace Drupal\voting\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Storage\VoteRepositoryInterface;
use Drupal\voting\Theme\VotingThemePreprocess;

/**
 * Hook implementations for the Voting module.
 */
final class VotingHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly VoteRepositoryInterface $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.voting') {
      return NULL;
    }
    return '<p>' . $this->t('Questions with answer options that authenticated users vote on once. Results are shown according to each question. Manage questions under Content » Voting and the global switch under Configuration » System » Voting settings.') . '</p>';
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      // Public pages. The module owns the markup and the BEM class names;
      // themes override the templates or just style the classes.
      'voting_question_list' => [
        'variables' => ['questions' => [], 'disabled' => FALSE],
      ],
      'voting_question' => [
        'variables' => ['description' => '', 'options' => [], 'outcome' => []],
      ],
      'voting_option_card' => [
        'variables' => ['option' => NULL, 'selected' => FALSE, 'inline' => FALSE],
        'initial preprocess' => VotingThemePreprocess::class . ':preprocessOptionCard',
      ],
      'voting_results' => [
        'variables' => ['rows' => [], 'total' => 0],
      ],
      'voting_notice' => [
        'variables' => ['message' => '', 'type' => 'info'],
      ],
      'voting_links' => [
        'variables' => [
          'sections' => [],
          'questions' => [],
          'question_limit' => 0,
          'all_questions_url' => '',
          'accounts' => [],
          'commands' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for voting_option.
   *
   * Pre-seeds the counter row so the vote hot path is a single UPDATE.
   */
  #[Hook('voting_option_insert')]
  public function optionInsert(OptionInterface $option): void {
    $this->repository->ensureTallyRow($option->getQuestionId(), (int) $option->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for voting_option.
   */
  #[Hook('voting_option_delete')]
  public function optionDelete(OptionInterface $option): void {
    $deleted = $this->repository->deleteByOption($option->getQuestionId(), (int) $option->id());
    if ($deleted > 0) {
      $this->loggerFactory->get('voting')->warning('Option @option of question @question was deleted together with @count votes.', [
        '@option' => $option->id(),
        '@question' => $option->getQuestionId(),
        '@count' => $deleted,
      ]);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for voting_question.
   *
   * Cascades to options, votes and counters.
   */
  #[Hook('voting_question_delete')]
  public function questionDelete(QuestionInterface $question): void {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $options = $question->getOptions();
    if ($options) {
      $storage->delete($options);
    }
    $deleted = $this->repository->deleteByQuestion((int) $question->id());
    $this->loggerFactory->get('voting')->notice('Question @question deleted with @options options and @votes votes.', [
      '@question' => $question->getIdentifier(),
      '@options' => count($options),
      '@votes' => $deleted,
    ]);
  }

}
