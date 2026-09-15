<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Service\VoteManagerInterface;
use Drupal\voting_api\Response\ApiResponse;
use Drupal\voting_api\Serializer\QuestionSerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /api/v1/questions/{id}/vote – casts a vote for the authenticated user.
 *
 * Body: {"option_id": 3} (JSON) or option_id=3 (form-encoded).
 */
final class VoteController extends ControllerBase {

  public function __construct(
    private readonly VoteManagerInterface $voteManager,
    private readonly QuestionSerializer $serializer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('voting.vote_manager'),
      $container->get('voting_api.serializer'),
    );
  }

  /**
   * Registers the vote and returns the results the caller may see.
   */
  public function vote(QuestionInterface $voting_question, Request $request): JsonResponse {
    $optionId = $this->readOptionId($request);
    $results = $this->voteManager->castVote($voting_question, $optionId, $this->currentUser());

    return ApiResponse::uncacheable($this->serializer->results($results), 201);
  }

  /**
   * Extracts and validates option_id from a JSON or form-encoded body.
   */
  private function readOptionId(Request $request): int {
    try {
      $value = $request->getPayload()->get('option_id');
    }
    catch (\JsonException) {
      throw new BadRequestHttpException((string) $this->t('The request body is not valid JSON.'));
    }

    if ($value === NULL || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === FALSE) {
      throw new BadRequestHttpException((string) $this->t('A positive integer "option_id" is required.'));
    }

    return (int) $value;
  }

}
