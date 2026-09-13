<?php

declare(strict_types=1);

namespace Drupal\voting\Event;

use Drupal\Core\Session\AccountInterface;
use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a vote has been committed to the database.
 *
 * Extension point for notifications, analytics or external integrations,
 * without touching the voting flow itself.
 */
final class VoteCastEvent extends Event {

  public function __construct(
    public readonly QuestionInterface $question,
    public readonly OptionInterface $option,
    public readonly AccountInterface $account,
  ) {}

}
