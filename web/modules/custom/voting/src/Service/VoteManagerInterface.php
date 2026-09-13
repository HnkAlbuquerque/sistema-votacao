<?php

declare(strict_types=1);

namespace Drupal\voting\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\voting\Entity\QuestionInterface;

/**
 * Application service that owns every voting business rule.
 *
 * Both the CMS form and the external API go through this interface, so the
 * rules are enforced once, regardless of the transport.
 */
interface VoteManagerInterface {

  /**
   * Registers a vote for the account.
   *
   * @throws \Drupal\voting\Exception\VotingDisabledException
   * @throws \Drupal\voting\Exception\AuthenticationRequiredException
   * @throws \Drupal\voting\Exception\QuestionInactiveException
   * @throws \Drupal\voting\Exception\InvalidOptionException
   * @throws \Drupal\voting\Exception\AlreadyVotedException
   * @throws \Drupal\voting\Exception\VoteRateLimitedException
   */
  public function castVote(QuestionInterface $question, int $optionId, AccountInterface $account): QuestionResults;

  /**
   * Whether the account already voted on the question.
   */
  public function hasVoted(QuestionInterface $question, AccountInterface $account): bool;

  /**
   * Results as the account is allowed to see them.
   *
   * Counts are only exposed when the question shows results and the account
   * has voted, or when the account may bypass those rules.
   *
   * @throws \Drupal\voting\Exception\VotingDisabledException
   * @throws \Drupal\voting\Exception\ResultsUnavailableException
   */
  public function getResults(QuestionInterface $question, AccountInterface $account): QuestionResults;

  /**
   * Whether the account may see vote totals for the question.
   */
  public function countsVisibleFor(QuestionInterface $question, AccountInterface $account): bool;

}
