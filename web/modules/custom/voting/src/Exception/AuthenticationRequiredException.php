<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when an anonymous account tries to vote.
 */
final class AuthenticationRequiredException extends VotingException {

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return 'authentication_required';
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return new TranslatableMarkup('You need to be logged in to vote.');
  }

}
