<?php

declare(strict_types=1);

namespace Drupal\voting_api\Response;

use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds responses with the API's uniform envelope.
 *
 * Success: {"data": ..., "meta": {...}}. Error: {"error": {"code", "message"}}.
 */
final class ApiResponse {

  /**
   * A response that Drupal may cache, tagged with the given dependencies.
   *
   * @param array $data
   *   The payload.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface[] $dependencies
   *   Entities, config or metadata objects the payload was built from.
   * @param array $meta
   *   Optional metadata (counts, pagination).
   */
  public static function cacheable(array $data, array $dependencies, array $meta = []): CacheableJsonResponse {
    $response = new CacheableJsonResponse(self::envelope($data, $meta));
    foreach ($dependencies as $dependency) {
      $response->addCacheableDependency($dependency);
    }
    return $response;
  }

  /**
   * A response that must never be cached (votes, results, health).
   */
  public static function uncacheable(array $data, int $status = 200, array $meta = []): JsonResponse {
    $response = new JsonResponse(self::envelope($data, $meta), $status);
    $response->setPrivate()->setMaxAge(0);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * An error response.
   */
  public static function error(string $code, string $message, int $status): JsonResponse {
    $response = new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Wraps the payload in the success envelope.
   */
  private static function envelope(array $data, array $meta): array {
    $body = ['data' => $data];
    if ($meta) {
      $body['meta'] = $meta;
    }
    return $body;
  }

}
