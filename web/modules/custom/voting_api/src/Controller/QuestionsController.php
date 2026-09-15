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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Read endpoints: question list, single question and results.
 */
final class QuestionsController extends ControllerBase {

  /**
   * Default and maximum number of questions per page on the list endpoint.
   */
  public const DEFAULT_LIMIT = 20;
  public const MAX_LIMIT = 100;

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
   * GET /api/v1/questions?page=1&limit=20 – active questions, paginated.
   *
   * Pages are 1-based; "limit" is capped at MAX_LIMIT. The "meta" object
   * carries count (items on this page), total, page, limit and pages.
   */
  public function list(Request $request): JsonResponse {
    $page = $this->readPositiveInt($request, 'page', 1);
    $limit = min($this->readPositiveInt($request, 'limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);

    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $total = (int) $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->count()
      ->execute();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('title')
      ->range(($page - 1) * $limit, $limit)
      ->execute();

    $data = [];
    foreach ($storage->loadMultiple($ids) as $question) {
      /** @var \Drupal\voting\Entity\QuestionInterface $question */
      $data[] = $this->serializer->summary($question, count($question->getOptions()));
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['voting_question_list', 'voting_option_list'])
      ->addCacheTags($this->settings->getCacheTags())
      ->addCacheContexts(['user.permissions', 'url.query_args:page', 'url.query_args:limit']);

    return ApiResponse::cacheable($data, [$cacheability], [
      'count' => count($data),
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => (int) ceil($total / $limit),
    ]);
  }

  /**
   * Reads a positive integer query parameter, or throws a 400.
   */
  private function readPositiveInt(Request $request, string $name, int $default): int {
    $value = $request->query->get($name);
    if ($value === NULL || $value === '') {
      return $default;
    }
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($int === FALSE) {
      throw new BadRequestHttpException(sprintf('The "%s" parameter must be a positive integer.', $name));
    }
    return $int;
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
