<?php

declare(strict_types=1);

namespace Drupal\voting\Storage;

/**
 * Low-level persistence of votes and vote counters.
 *
 * This is the only class that touches the voting_vote and voting_result
 * tables. It knows nothing about entities, accounts or permissions.
 */
interface VoteRepositoryInterface {

  /**
   * Whether the user already voted on the question.
   */
  public function hasVoted(int $questionId, int $uid): bool;

  /**
   * Returns the option the user voted for, or NULL when there is no vote.
   */
  public function getVotedOptionId(int $questionId, int $uid): ?int;

  /**
   * Records a vote and increments the counter in a single transaction.
   *
   * @throws \Drupal\voting\Exception\AlreadyVotedException
   *   When the unique (question, user) constraint rejects the insert.
   */
  public function recordVote(int $questionId, int $optionId, int $uid, int $timestamp): void;

  /**
   * Returns the denormalized counters of a question.
   *
   * @return array<int, int>
   *   Vote counts keyed by option ID. Options without a row are omitted.
   */
  public function getTally(int $questionId): array;

  /**
   * Counts votes straight from the vote rows (slow path, for auditing).
   *
   * @return array<int, int>
   *   Vote counts keyed by option ID.
   */
  public function countVotesByOption(int $questionId): array;

  /**
   * Total votes of a question, read from the counters.
   */
  public function countVotes(int $questionId): int;

  /**
   * Rebuilds the counters of a question from the raw vote rows.
   */
  public function rebuildTally(int $questionId): void;

  /**
   * Makes sure a zero counter row exists for the option.
   */
  public function ensureTallyRow(int $questionId, int $optionId): void;

  /**
   * Deletes every vote and counter of a question.
   *
   * @return int
   *   Number of deleted votes.
   */
  public function deleteByQuestion(int $questionId): int;

  /**
   * Deletes the votes and counter of a single option.
   *
   * @return int
   *   Number of deleted votes.
   */
  public function deleteByOption(int $questionId, int $optionId): int;

  /**
   * Returns the question IDs that have at least one counter row.
   *
   * @return int[]
   *   Question IDs.
   */
  public function getQuestionIdsWithVotes(): array;

}
