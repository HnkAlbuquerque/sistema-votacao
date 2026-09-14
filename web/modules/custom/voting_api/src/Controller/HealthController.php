<?php

declare(strict_types=1);

namespace Drupal\voting_api\Controller;

use Drupal\Core\Database\Connection;
use Drupal\voting\VotingSettings;
use Drupal\voting_api\Response\ApiResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * GET /api/v1/health – liveness/readiness probe for monitoring.
 */
final class HealthController implements ContainerInjectionInterface {

  public function __construct(
    private readonly Connection $connection,
    private readonly VotingSettings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('voting.settings'),
    );
  }

  /**
   * Reports database connectivity and the state of the kill switch.
   */
  public function health(): JsonResponse {
    $database = 'ok';
    try {
      $this->connection->query('SELECT 1')->fetchField();
    }
    catch (\Throwable) {
      $database = 'error';
    }

    $healthy = $database === 'ok';

    return ApiResponse::uncacheable([
      'status' => $healthy ? 'ok' : 'degraded',
      'database' => $database,
      'voting_enabled' => $this->settings->isEnabled(),
      'drupal' => \Drupal::VERSION,
      'timestamp' => gmdate('c'),
    ], $healthy ? 200 : 503);
  }

}
