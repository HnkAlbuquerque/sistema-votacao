<?php

declare(strict_types=1);

namespace Drupal\voting;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Typed access to the voting.settings configuration.
 */
final class VotingSettings {

  public const CONFIG_NAME = 'voting.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether voting is globally enabled (the "kill switch").
   */
  public function isEnabled(): bool {
    return (bool) $this->config()->get('enabled');
  }

  /**
   * Maximum vote attempts per user inside the flood window.
   */
  public function getFloodLimit(): int {
    return max(1, (int) $this->config()->get('flood.limit'));
  }

  /**
   * Flood window length in seconds.
   */
  public function getFloodWindow(): int {
    return max(1, (int) $this->config()->get('flood.window'));
  }

  /**
   * Cache tags to attach to anything that depends on these settings.
   *
   * @return string[]
   *   Cache tags.
   */
  public function getCacheTags(): array {
    return $this->config()->getCacheTags();
  }

  /**
   * The immutable configuration object.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
