<?php

declare(strict_types=1);

namespace Drupal\voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\voting\Entity\QuestionInterface;

/**
 * Glue between the question routes and the option entity forms.
 */
final class OptionController extends ControllerBase {

  /**
   * Opens the "add option" form with the question already set.
   */
  public function add(QuestionInterface $voting_question): array {
    $option = $this->entityTypeManager()
      ->getStorage('voting_option')
      ->create(['question_id' => $voting_question->id()]);

    return $this->entityFormBuilder()->getForm($option, 'add');
  }

  /**
   * Title callback for the add form.
   */
  public function addTitle(QuestionInterface $voting_question): TranslatableMarkup {
    return $this->t('Add option to %question', ['%question' => $voting_question->label()]);
  }

  /**
   * Title callback for the options tab.
   */
  public function overviewTitle(QuestionInterface $voting_question): TranslatableMarkup {
    return $this->t('Options of %question', ['%question' => $voting_question->label()]);
  }

}
