<?php

declare(strict_types=1);

namespace Drupal\voting\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Storage\VoteRepositoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Operational commands: integrity check and statistics.
 */
final class VotingCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly VoteRepositoryInterface $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Compares the denormalized counters with the raw vote rows.
   */
  #[CLI\Command(name: 'voting:integrity', aliases: ['vint'])]
  #[CLI\Option(name: 'repair', description: 'Rebuild the counters of every question that diverges.')]
  #[CLI\Usage(name: 'drush voting:integrity', description: 'Report divergences.')]
  #[CLI\Usage(name: 'drush voting:integrity --repair', description: 'Report and fix divergences.')]
  #[CLI\FieldLabels(labels: [
    'question' => 'Question',
    'counter' => 'Counter total',
    'raw' => 'Raw total',
    'status' => 'Status',
  ])]
  #[CLI\DefaultTableFields(fields: ['question', 'counter', 'raw', 'status'])]
  public function integrity(array $options = ['repair' => FALSE, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->loadQuestions() as $question) {
      $questionId = (int) $question->id();
      $counter = $this->repository->getTally($questionId);
      $raw = $this->repository->countVotesByOption($questionId);
      $consistent = $this->sameCounts($counter, $raw);

      $status = 'ok';
      if (!$consistent) {
        $status = 'mismatch';
        if ($options['repair']) {
          $this->repository->rebuildTally($questionId);
          $status = 'repaired';
        }
      }
      $rows[] = [
        'question' => $question->getIdentifier(),
        'counter' => array_sum($counter),
        'raw' => array_sum($raw),
        'status' => $status,
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Shows vote totals per question.
   */
  #[CLI\Command(name: 'voting:stats', aliases: ['vstats'])]
  #[CLI\FieldLabels(labels: [
    'question' => 'Question',
    'active' => 'Active',
    'options' => 'Options',
    'votes' => 'Votes',
  ])]
  #[CLI\DefaultTableFields(fields: ['question', 'active', 'options', 'votes'])]
  public function stats(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->loadQuestions() as $question) {
      $rows[] = [
        'question' => $question->getIdentifier(),
        'active' => $question->isPublished() ? 'yes' : 'no',
        'options' => count($question->getOptions()),
        'votes' => $this->repository->countVotes((int) $question->id()),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Loads every question.
   *
   * @return \Drupal\voting\Entity\QuestionInterface[]
   *   All questions.
   */
  private function loadQuestions(): array {
    return array_filter(
      $this->entityTypeManager->getStorage('voting_question')->loadMultiple(),
      static fn ($entity) => $entity instanceof QuestionInterface,
    );
  }

  /**
   * Compares two option => votes maps, ignoring zero entries.
   */
  private function sameCounts(array $a, array $b): bool {
    $a = array_filter($a);
    $b = array_filter($b);
    ksort($a);
    ksort($b);
    return $a === $b;
  }

}
