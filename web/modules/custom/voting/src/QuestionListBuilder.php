<?php

declare(strict_types=1);

namespace Drupal\voting;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\voting\Storage\VoteRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin listing of voting questions.
 */
final class QuestionListBuilder extends EntityListBuilder {

  /**
   * The vote repository, used for the per-question vote totals.
   */
  private VoteRepositoryInterface $repository;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()));
    $instance->repository = $container->get('voting.vote_repository');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'title' => $this->t('Title'),
      'identifier' => $this->t('Identifier'),
      'status' => $this->t('Status'),
      'show_results' => $this->t('Results'),
      'votes' => $this->t('Votes'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\voting\Entity\QuestionInterface $entity */
    return [
      'title' => $entity->toLink($entity->label(), 'edit-form'),
      'identifier' => $entity->getIdentifier(),
      'status' => $entity->isPublished() ? $this->t('Active') : $this->t('Inactive'),
      'show_results' => $entity->showsResults() ? $this->t('Shown after vote') : $this->t('Hidden'),
      'votes' => $this->repository->countVotes((int) $entity->id()),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $operations['options'] = [
      'title' => $this->t('Options'),
      'weight' => 5,
      'url' => Url::fromRoute('voting.question.options', ['voting_question' => $entity->id()]),
    ];
    $operations['results'] = [
      'title' => $this->t('Results'),
      'weight' => 6,
      'url' => Url::fromRoute('voting.question.results', ['voting_question' => $entity->id()]),
    ];
    if ($entity->access('view')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 7,
        'url' => Url::fromRoute('voting.question', ['voting_question' => $entity->getIdentifier()]),
      ];
    }
    return $operations;
  }

}
