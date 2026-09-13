<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Url;

/**
 * Delete confirmation for a voting option.
 */
final class OptionDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('Votes already cast for this option will be removed as well. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->questionOptionsUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    return $this->questionOptionsUrl();
  }

  /**
   * URL of the "Options" tab of the parent question.
   */
  private function questionOptionsUrl(): Url {
    /** @var \Drupal\voting\Entity\OptionInterface $option */
    $option = $this->entity;
    return Url::fromRoute('voting.question.options', ['voting_question' => $option->getQuestionId()]);
  }

}
