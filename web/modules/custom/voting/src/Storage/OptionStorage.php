<?php

declare(strict_types=1);

namespace Drupal\voting\Storage;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\voting\Entity\QuestionInterface;

/**
 * Storage handler for voting options.
 */
class OptionStorage extends SqlContentEntityStorage implements OptionStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function loadByQuestion(QuestionInterface $question): array {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('question_id', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    return $ids ? $this->loadMultiple($ids) : [];
  }

}
