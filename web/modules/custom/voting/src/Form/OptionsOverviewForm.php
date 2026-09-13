<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Draggable table with the options of a question ("Options" tab).
 */
final class OptionsOverviewForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_options_overview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?QuestionInterface $voting_question = NULL): array {
    $options = $voting_question?->getOptions() ?? [];

    $form['options'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Title'),
        $this->t('Description'),
        $this->t('Image'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No options yet. Add at least two options so users can vote.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'option-weight',
        ],
      ],
    ];

    foreach ($options as $option) {
      $form['options'][$option->id()] = $this->buildRow($option);
    }

    if ($options) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save order'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * Builds one draggable table row.
   */
  private function buildRow(OptionInterface $option): array {
    $row = [
      '#attributes' => ['class' => ['draggable']],
      '#weight' => $option->getWeight(),
    ];
    $row['title'] = ['#plain_text' => $option->getTitle()];
    $row['description'] = ['#plain_text' => Unicode::truncate($option->getDescription(), 80, TRUE, TRUE)];
    $row['image'] = $option->get('image')->isEmpty()
      ? ['#plain_text' => '—']
      : $option->get('image')->view([
        'label' => 'hidden',
        'type' => 'image',
        'settings' => ['image_style' => 'thumbnail', 'image_link' => ''],
      ]);
    $row['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for @title', ['@title' => $option->getTitle()]),
      '#title_display' => 'invisible',
      '#default_value' => $option->getWeight(),
      '#delta' => 50,
      '#attributes' => ['class' => ['option-weight']],
    ];
    $row['operations'] = [
      '#type' => 'operations',
      '#links' => [
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => $option->toUrl('edit-form'),
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => $option->toUrl('delete-form'),
        ],
      ],
    ];
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    foreach ($form_state->getValue('options', []) as $id => $values) {
      $option = $storage->load($id);
      if ($option instanceof OptionInterface && $option->getWeight() !== (int) $values['weight']) {
        $option->setWeight((int) $values['weight'])->save();
      }
    }
    $this->messenger()->addStatus($this->t('The option order has been saved.'));
  }

}
