<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Base class for every business-rule violation in the voting domain.
 *
 * Each subclass carries a stable machine-readable code so that transports
 * (forms, API) can map it to messages and HTTP statuses without inspecting
 * the exception class.
 */
abstract class VotingException extends \RuntimeException {

  public function __construct(?\Throwable $previous = NULL) {
    parent::__construct((string) $this->getUserMessage()->getUntranslatedString(), 0, $previous);
  }

  /**
   * Stable machine-readable error code, e.g. "already_voted".
   */
  abstract public function getErrorCode(): string;

  /**
   * Message safe to show to end users.
   */
  abstract public function getUserMessage(): TranslatableMarkup;

}
