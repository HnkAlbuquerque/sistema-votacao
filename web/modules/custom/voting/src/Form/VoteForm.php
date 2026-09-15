<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Exception\VotingException;
use Drupal\voting\Service\VoteManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public form used to cast a vote on a question.
 *
 * Each radio label is a full option card (image, title, description), so
 * the user picks the card itself instead of a bare title.
 */
final class VoteForm extends FormBase {

  public function __construct(
    private readonly VoteManagerInterface $voteManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('voting.vote_manager'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_vote_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?QuestionInterface $question = NULL): array {
    if ($question === NULL) {
      throw new \InvalidArgumentException('A question is required to build the vote form.');
    }

    $choices = [];
    foreach ($question->getOptions() as $id => $option) {
      $card = [
        '#theme' => 'voting_option_card',
        '#option' => $option,
        '#inline' => TRUE,
      ];
      // The card is rendered in isolation because radio labels only accept
      // strings; its output is template-escaped, so it is safe as markup.
      $choices[$id] = Markup::create((string) $this->renderer->renderInIsolation($card));
    }

    if (!$choices) {
      $form['empty'] = [
        '#theme' => 'voting_notice',
        '#message' => $this->t('This question has no options yet.'),
      ];
      return $form;
    }

    $form['#attributes']['class'][] = 'vote-form';
    // A container carries the layout classes: attributes set on the radios
    // element itself would be copied onto every <input>.
    $form['options'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vote-form__options']],
    ];
    $form['options']['option_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Your answer'),
      '#options' => $choices,
      '#required' => TRUE,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['vote-form__actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Vote'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\voting\Entity\QuestionInterface $question */
    $question = $form_state->getBuildInfo()['args'][0];

    try {
      $this->voteManager->castVote($question, (int) $form_state->getValue('option_id'), $this->currentUser());
      $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
    }
    catch (VotingException $e) {
      $this->messenger()->addError($e->getUserMessage());
    }

    $form_state->setRedirect('voting.question', ['voting_question' => $question->getIdentifier()]);
  }

}
