<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Exception\VotingException;
use Drupal\voting\Service\VoteManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public form used to cast a vote on a question.
 */
final class VoteForm extends FormBase {

  public function __construct(
    private readonly VoteManagerInterface $voteManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('voting.vote_manager'));
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
      $choices[$id] = $option->getTitle();
    }

    if (!$choices) {
      $form['empty'] = ['#markup' => $this->t('This question has no options yet.')];
      return $form;
    }

    $form['option_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Your answer'),
      '#options' => $choices,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
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
