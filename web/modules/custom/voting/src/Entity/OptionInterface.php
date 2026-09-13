<?php

declare(strict_types=1);

namespace Drupal\voting\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * An answer option that belongs to a single question.
 */
interface OptionInterface extends ContentEntityInterface {

  /**
   * Returns the ID of the question this option belongs to.
   */
  public function getQuestionId(): int;

  /**
   * Returns the parent question, if it still exists.
   */
  public function getQuestion(): ?QuestionInterface;

  /**
   * Returns the option title.
   */
  public function getTitle(): string;

  /**
   * Returns the short description.
   */
  public function getDescription(): string;

  /**
   * Returns the sort weight inside the question.
   */
  public function getWeight(): int;

  /**
   * Sets the sort weight inside the question.
   */
  public function setWeight(int $weight): static;

  /**
   * Returns the absolute URL of the image, or NULL when there is none.
   */
  public function getImageUrl(): ?string;

}
