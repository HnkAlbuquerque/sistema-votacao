<?php

declare(strict_types=1);

namespace Drupal\voting\Storage;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\voting\Entity\QuestionInterface;

/**
 * Storage handler for voting options.
 */
interface OptionStorageInterface extends ContentEntityStorageInterface {

  /**
   * Loads the options of a question, ordered by weight.
   *
   * @return \Drupal\voting\Entity\OptionInterface[]
   *   Options keyed by ID.
   */
  public function loadByQuestion(QuestionInterface $question): array;

}
