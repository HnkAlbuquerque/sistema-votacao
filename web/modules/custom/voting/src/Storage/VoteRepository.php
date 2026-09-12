<?php

declare(strict_types=1);

namespace Drupal\voting\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\voting\Exception\AlreadyVotedException;

/**
 * Database implementation of the vote repository.
 */
final class VoteRepository implements VoteRepositoryInterface {

  public const VOTE_TABLE = 'voting_vote';
  public const RESULT_TABLE = 'voting_result';

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hasVoted(int $questionId, int $uid): bool {
    return $this->getVotedOptionId($questionId, $uid) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getVotedOptionId(int $questionId, int $uid): ?int {
    $optionId = $this->connection->select(self::VOTE_TABLE, 'v')
      ->fields('v', ['option_id'])
      ->condition('question_id', $questionId)
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    return $optionId === FALSE ? NULL : (int) $optionId;
  }

  /**
   * {@inheritdoc}
   */
  public function recordVote(int $questionId, int $optionId, int $uid, int $timestamp): void {
    $transaction = $this->connection->startTransaction();
    try {
      // The unique key (question_id, uid) is the real guard against double
      // voting: a concurrent duplicate fails here and rolls everything back.
      $this->connection->insert(self::VOTE_TABLE)
        ->fields([
          'question_id' => $questionId,
          'option_id' => $optionId,
          'uid' => $uid,
          'created' => $timestamp,
        ])
        ->execute();

      // Hot path: a single atomic UPDATE on the counter row.
      $updated = $this->connection->update(self::RESULT_TABLE)
        ->expression('votes', '[votes] + 1')
        ->condition('question_id', $questionId)
        ->condition('option_id', $optionId)
        ->execute();

      // Counter row missing (option created before the module was installed,
      // or rebuilt tallies): create it, tolerating a racing insert.
      if ($updated === 0) {
        $this->connection->merge(self::RESULT_TABLE)
          ->keys(['question_id' => $questionId, 'option_id' => $optionId])
          ->fields(['votes' => 1])
          ->expression('votes', '[votes] + 1')
          ->execute();
      }
    }
    catch (IntegrityConstraintViolationException $e) {
      $transaction->rollBack();
      throw new AlreadyVotedException($e);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTally(int $questionId): array {
    $rows = $this->connection->select(self::RESULT_TABLE, 'r')
      ->fields('r', ['option_id', 'votes'])
      ->condition('question_id', $questionId)
      ->execute()
      ->fetchAllKeyed();

    return array_map('intval', $rows);
  }

  /**
   * {@inheritdoc}
   */
  public function countVotesByOption(int $questionId): array {
    $query = $this->connection->select(self::VOTE_TABLE, 'v')
      ->fields('v', ['option_id'])
      ->condition('question_id', $questionId)
      ->groupBy('option_id');
    $query->addExpression('COUNT(*)', 'votes');

    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * {@inheritdoc}
   */
  public function countVotes(int $questionId): int {
    $query = $this->connection->select(self::RESULT_TABLE, 'r')
      ->condition('question_id', $questionId);
    $query->addExpression('COALESCE(SUM([votes]), 0)', 'total');

    return (int) $query->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function rebuildTally(int $questionId): void {
    $counts = $this->countVotesByOption($questionId);

    $transaction = $this->connection->startTransaction();
    try {
      $this->connection->delete(self::RESULT_TABLE)
        ->condition('question_id', $questionId)
        ->execute();

      if ($counts) {
        $insert = $this->connection->insert(self::RESULT_TABLE)
          ->fields(['question_id', 'option_id', 'votes']);
        foreach ($counts as $optionId => $votes) {
          $insert->values([$questionId, $optionId, $votes]);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function ensureTallyRow(int $questionId, int $optionId): void {
    $this->connection->merge(self::RESULT_TABLE)
      ->keys(['question_id' => $questionId, 'option_id' => $optionId])
      ->insertFields(['question_id' => $questionId, 'option_id' => $optionId, 'votes' => 0])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByQuestion(int $questionId): int {
    $deleted = $this->connection->delete(self::VOTE_TABLE)
      ->condition('question_id', $questionId)
      ->execute();
    $this->connection->delete(self::RESULT_TABLE)
      ->condition('question_id', $questionId)
      ->execute();

    return (int) $deleted;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByOption(int $questionId, int $optionId): int {
    $deleted = $this->connection->delete(self::VOTE_TABLE)
      ->condition('question_id', $questionId)
      ->condition('option_id', $optionId)
      ->execute();
    $this->connection->delete(self::RESULT_TABLE)
      ->condition('question_id', $questionId)
      ->condition('option_id', $optionId)
      ->execute();

    return (int) $deleted;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestionIdsWithVotes(): array {
    $ids = $this->connection->select(self::VOTE_TABLE, 'v')
      ->fields('v', ['question_id'])
      ->distinct()
      ->execute()
      ->fetchCol();

    return array_map('intval', $ids);
  }

}
