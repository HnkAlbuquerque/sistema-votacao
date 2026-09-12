<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when the question is unpublished.
 */
final class QuestionInactiveException extends VotingException {

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return 'question_inactive';
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return new TranslatableMarkup('This question is not open for voting.');
  }

}
