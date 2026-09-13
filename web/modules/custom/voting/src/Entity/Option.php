<?php

declare(strict_types=1);

namespace Drupal\voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\voting\Access\OptionAccessControlHandler;
use Drupal\voting\Form\OptionDeleteForm;
use Drupal\voting\Form\OptionForm;
use Drupal\voting\Storage\OptionStorage;

/**
 * Defines the voting option entity.
 *
 * Routes for the edit and delete forms are declared in voting.routing.yml
 * because they live under the parent question in the admin UI.
 */
#[ContentEntityType(
  id: 'voting_option',
  label: new TranslatableMarkup('Voting option'),
  label_collection: new TranslatableMarkup('Voting options'),
  label_singular: new TranslatableMarkup('voting option'),
  label_plural: new TranslatableMarkup('voting options'),
  label_count: [
    'singular' => '@count voting option',
    'plural' => '@count voting options',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'storage' => OptionStorage::class,
    'access' => OptionAccessControlHandler::class,
    'form' => [
      'default' => OptionForm::class,
      'add' => OptionForm::class,
      'edit' => OptionForm::class,
      'delete' => OptionDeleteForm::class,
    ],
  ],
  links: [
    'edit-form' => '/admin/content/voting/option/{voting_option}/edit',
    'delete-form' => '/admin/content/voting/option/{voting_option}/delete',
  ],
  admin_permission: 'administer voting',
  base_table: 'voting_option',
)]
class Option extends ContentEntityBase implements OptionInterface {

  /**
   * {@inheritdoc}
   */
  public function getQuestionId(): int {
    return (int) $this->get('question_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): ?QuestionInterface {
    $question = $this->get('question_id')->entity;
    return $question instanceof QuestionInterface ? $question : NULL;
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
  public function getWeight(): int {
    return (int) $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight(int $weight): static {
    $this->set('weight', $weight);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getImageUrl(): ?string {
    $file = $this->get('image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    return $file->createFileUrl(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['question_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
      ]);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ]);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('A short explanation of this option.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 2,
        'settings' => ['rows' => 3],
      ]);

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Image'))
      ->setSettings([
        'file_directory' => 'voting/options',
        'file_extensions' => 'png jpg jpeg gif webp',
        'max_filesize' => '2 MB',
        'alt_field' => TRUE,
        'alt_field_required' => FALSE,
        'title_field' => FALSE,
      ])
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 3,
      ]);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('Options are shown in ascending weight order.'))
      ->setDefaultValue(0);

    return $fields;
  }

}
