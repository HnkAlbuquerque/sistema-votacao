<?php

declare(strict_types=1);

namespace Drupal\voting\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A question users can vote on.
 */
interface QuestionInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Returns the unique, admin-defined identifier used in URLs and the API.
   */
  public function getIdentifier(): string;

  /**
   * Returns the question title.
   */
  public function getTitle(): string;

  /**
   * Returns the optional description.
   */
  public function getDescription(): string;

  /**
   * Whether vote totals are shown to users after they vote.
   */
  public function showsResults(): bool;

  /**
   * Returns the answer options, sorted by weight.
   *
   * @return \Drupal\voting\Entity\OptionInterface[]
   *   Options keyed by option ID.
   */
  public function getOptions(): array;

}
