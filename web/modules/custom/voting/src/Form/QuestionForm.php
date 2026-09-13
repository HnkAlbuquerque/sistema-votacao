<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Add and edit form for voting questions.
 */
final class QuestionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Turn the identifier into a machine name generated from the title. It is
    // locked after creation because external clients rely on it.
    $identifier = &$form['identifier']['widget'][0]['value'];
    $identifier['#type'] = 'machine_name';
    $identifier['#maxlength'] = 64;
    $identifier['#disabled'] = !$this->entity->isNew();
    $identifier['#machine_name'] = [
      'exists' => [$this, 'identifierExists'],
      'source' => ['title', 'widget', 0, 'value'],
      'replace_pattern' => '[^a-z0-9_-]+',
      'replace' => '-',
      'label' => $this->t('Identifier'),
      'error' => $this->t('The identifier may only contain lowercase letters, numbers, hyphens and underscores.'),
    ];

    return $form;
  }

  /**
   * Machine name "exists" callback.
   */
  public function identifierExists(string $value): bool {
    $query = $this->entityTypeManager->getStorage('voting_question')->getQuery()
      ->accessCheck(FALSE)
      ->condition('identifier', $value);
    if (!$this->entity->isNew()) {
      $query->condition('id', $this->entity->id(), '<>');
    }
    return (bool) $query->count()->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%title' => $this->entity->label()];

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Question %title created. Now add its answer options.', $args));
      $form_state->setRedirect('voting.question.options', ['voting_question' => $this->entity->id()]);
    }
    else {
      $this->messenger()->addStatus($this->t('Question %title updated.', $args));
      $form_state->setRedirectUrl(Url::fromRoute('entity.voting_question.collection'));
    }

    return $result;
  }

}
