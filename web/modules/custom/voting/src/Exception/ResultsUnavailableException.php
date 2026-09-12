<?php

declare(strict_types=1);

namespace Drupal\voting\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Thrown when the results of a question may not be shown to the account.
 */
final class ResultsUnavailableException extends VotingException {

  public const VOTE_REQUIRED = 'vote_required';
  public const RESULTS_HIDDEN = 'results_hidden';

  public function __construct(
    private readonly string $reason,
  ) {
    parent::__construct();
  }

  /**
   * The user has to vote before seeing the results.
   */
  public static function voteRequired(): self {
    return new self(self::VOTE_REQUIRED);
  }

  /**
   * The question is configured to hide the totals.
   */
  public static function hidden(): self {
    return new self(self::RESULTS_HIDDEN);
  }

  /**
   * {@inheritdoc}
   */
  public function getErrorCode(): string {
    return $this->reason;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): TranslatableMarkup {
    return match ($this->reason) {
      self::VOTE_REQUIRED => new TranslatableMarkup('Vote on this question to see the results.'),
      default => new TranslatableMarkup('The results of this question are not public.'),
    };
  }

}
