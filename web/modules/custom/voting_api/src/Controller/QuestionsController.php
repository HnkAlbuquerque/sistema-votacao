<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Service\VoteManagerInterface;
use Drupal\voting\VotingSettings;
use Drupal\voting_api\Response\ApiResponse;
use Drupal\voting_api\Serializer\QuestionSerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read endpoints: question list, single question and results.
 */
final class QuestionsController extends ControllerBase {

  public function __construct(
    private readonly VoteManagerInterface $voteManager,
    private readonly VotingSettings $settings,
    private readonly QuestionSerializer $serializer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('voting.vote_manager'),
      $container->get('voting.settings'),
      $container->get('voting_api.serializer'),
    );
  }

  /**
   * GET /api/v1/questions – active questions.
   */
  public function list(): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('title')
      ->execute();

    $data = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\voting\Entity\QuestionInterface $question */
      $data[] = $this->serializer->summary($question, count($question->getOptions()));
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['voting_question_list', 'voting_option_list'])
      ->addCacheTags($this->settings->getCacheTags())
      ->addCacheContexts(['user.permissions']);

    return ApiResponse::cacheable($data, [$cacheability], ['count' => count($data)]);
  }

  /**
   * GET /api/v1/questions/{id} – one question with its options.
   */
  public function show(QuestionInterface $voting_question): JsonResponse {
    $options = $voting_question->getOptions();

    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($voting_question)
      ->addCacheTags(['voting_option_list'])
      ->addCacheTags($this->settings->getCacheTags())
      ->addCacheContexts(['user.permissions']);
    foreach ($options as $option) {
      $cacheability->addCacheableDependency($option);
    }

    return ApiResponse::cacheable($this->serializer->detail($voting_question, $options), [$cacheability]);
  }

  /**
   * GET /api/v1/questions/{id}/results – totals, if the caller may see them.
   */
  public function results(QuestionInterface $voting_question): JsonResponse {
    $results = $this->voteManager->getResults($voting_question, $this->currentUser());
    return ApiResponse::uncacheable($this->serializer->results($results));
  }

}
