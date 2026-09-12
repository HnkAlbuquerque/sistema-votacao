<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when the user already has a vote for the question.
 */
final class AlreadyVotedException extends VotingException {

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return 'already_voted';
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return new TranslatableMarkup('You have already voted on this question.');
  }

}
