<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add and edit form for voting options.
 */
final class OptionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // The question is fixed by the route the form was opened from.
    /** @var \Drupal\voting\Entity\OptionInterface $option */
    $option = $this->entity;
    if ($option->getQuestionId() > 0) {
      $form['question_id']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    /** @var \Drupal\voting\Entity\OptionInterface $option */
    $option = $this->entity;

    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Option %title added.', ['%title' => $option->label()])
      : $this->t('Option %title updated.', ['%title' => $option->label()]));

    $form_state->setRedirect('voting.question.options', ['voting_question' => $option->getQuestionId()]);

    return $result;
  }

}
