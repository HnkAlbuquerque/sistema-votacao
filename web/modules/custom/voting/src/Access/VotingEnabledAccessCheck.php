<?php

declare(strict_types=1);

namespace Drupal\voting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\voting\VotingSettings;

/**
 * Route access check for the global kill switch.
 *
 * Add `_voting_enabled: 'TRUE'` to a route's requirements to make it
 * unavailable while voting is disabled. The result carries the config cache
 * tag, so toggling the switch invalidates cached responses automatically.
 */
final class VotingEnabledAccessCheck implements AccessInterface {

  public function __construct(
    private readonly VotingSettings $settings,
  ) {}

  /**
   * Checks whether voting is enabled.
   */
  public function access(): AccessResultInterface {
    $result = $this->settings->isEnabled()
      ? AccessResult::allowed()
      : AccessResult::forbidden('Voting is currently disabled.');
    return $result->addCacheTags($this->settings->getCacheTags());
  }

}
