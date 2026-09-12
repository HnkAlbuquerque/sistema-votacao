<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when the flood control limit is exceeded.
 */
final class VoteRateLimitedException extends VotingException {

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return 'rate_limited';
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Too many vote attempts. Please try again later.');
  }

}
