<?php

declare(strict_types=1);

namespace Drupal\voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\voting\Access\QuestionAccessControlHandler;
use Drupal\voting\Form\QuestionForm;
use Drupal\voting\QuestionListBuilder;

/**
 * Defines the voting question entity.
 */
#[ContentEntityType(
  id: 'voting_question',
  label: new TranslatableMarkup('Voting question'),
  label_collection: new TranslatableMarkup('Voting questions'),
  label_singular: new TranslatableMarkup('voting question'),
  label_plural: new TranslatableMarkup('voting questions'),
  label_count: [
    'singular' => '@count voting question',
    'plural' => '@count voting questions',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'published' => 'status',
    'owner' => 'uid',
  ],
  handlers: [
    'list_builder' => QuestionListBuilder::class,
    'access' => QuestionAccessControlHandler::class,
    'form' => [
      'default' => QuestionForm::class,
      'add' => QuestionForm::class,
      'edit' => QuestionForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/voting',
    'add-form' => '/admin/content/voting/add',
    'edit-form' => '/admin/content/voting/{voting_question}/edit',
    'delete-form' => '/admin/content/voting/{voting_question}/delete',
  ],
  admin_permission: 'administer voting',
  base_table: 'voting_question',
)]
class Question extends ContentEntityBase implements QuestionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string {
    return (string) $this->get('identifier')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function showsResults(): bool {
    return (bool) $this->get('show_results')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(): array {
    if ($this->isNew()) {
      return [];
    }
    /** @var \Drupal\voting\Storage\OptionStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage('voting_option');
    return $storage->loadByQuestion($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The question shown to users.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ]);

    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Identifier'))
      ->setDescription(t('Unique identifier used in URLs and in the API. Lowercase letters, numbers, hyphens and underscores only.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ]);
    $fields['identifier']->addPropertyConstraints('value', [
      'Regex' => [
        'pattern' => '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
        'message' => 'The identifier may only contain lowercase letters, numbers, hyphens and underscores.',
      ],
    ]);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('Optional context shown together with the question.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 2,
        'settings' => ['rows' => 4],
      ]);

    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Show results after voting'))
      ->setDescription(t('When enabled, users see the vote totals right after casting their vote. Otherwise they only get a confirmation.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 3,
      ]);

    $fields['status']
      ->setLabel(t('Active'))
      ->setDescription(t('Only active questions are listed and accept votes.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 4,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
