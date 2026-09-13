<?php

declare(strict_types=1);

namespace Drupal\voting\Service;

use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;

/**
 * Immutable snapshot of a question's results for one account.
 *
 * Counts are NULL when the account is not allowed to see them, so callers
 * never have to re-implement the visibility rule.
 */
final class QuestionResults {

  /**
   * Constructs the snapshot.
   *
   * @param \Drupal\voting\Entity\QuestionInterface $question
   *   The question.
   * @param \Drupal\voting\Entity\OptionInterface[] $options
   *   Options keyed by ID, in display order.
   * @param array<int, int>|null $counts
   *   Votes keyed by option ID, or NULL when hidden.
   * @param int|null $userOptionId
   *   The option the account voted for, if any.
   */
  public function __construct(
    public readonly QuestionInterface $question,
    public readonly array $options,
    public readonly ?array $counts,
    public readonly ?int $userOptionId,
  ) {}

  /**
   * Whether vote totals are included.
   */
  public function countsVisible(): bool {
    return $this->counts !== NULL;
  }

  /**
   * Total number of votes, or NULL when hidden.
   */
  public function getTotal(): ?int {
    return $this->counts === NULL ? NULL : array_sum($this->counts);
  }

  /**
   * Votes for one option, or NULL when hidden.
   */
  public function getVotes(OptionInterface|int $option): ?int {
    if ($this->counts === NULL) {
      return NULL;
    }
    $id = $option instanceof OptionInterface ? (int) $option->id() : $option;
    return $this->counts[$id] ?? 0;
  }

  /**
   * Share of the total for one option, 0 to 100, or NULL when hidden.
   */
  public function getPercentage(OptionInterface|int $option): ?float {
    $total = $this->getTotal();
    if ($total === NULL) {
      return NULL;
    }
    if ($total === 0) {
      return 0.0;
    }
    return round($this->getVotes($option) * 100 / $total, 1);
  }

}
