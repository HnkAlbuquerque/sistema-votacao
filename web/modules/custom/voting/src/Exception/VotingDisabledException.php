<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when the global kill switch is off.
 */
final class VotingDisabledException extends VotingException {

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return 'voting_disabled';
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Voting is currently disabled.');
  }

}
