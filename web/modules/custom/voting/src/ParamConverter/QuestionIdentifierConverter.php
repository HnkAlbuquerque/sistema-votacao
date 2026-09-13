<?php

declare(strict_types=1);

namespace Drupal\voting\ParamConverter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\voting\Entity\QuestionInterface;
use Symfony\Component\Routing\Route;

/**
 * Upcasts a question identifier (slug) in a route path to the entity.
 *
 * Use `type: 'voting_question:identifier'` in the route parameter options.
 * Shared by the public pages and the API so both resolve questions the
 * same way.
 */
final class QuestionIdentifierConverter implements ParamConverterInterface {

  public const TYPE = 'voting_question:identifier';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults): ?QuestionInterface {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $questions = $this->entityTypeManager
      ->getStorage('voting_question')
      ->loadByProperties(['identifier' => $value]);
    $question = reset($questions);

    return $question instanceof QuestionInterface ? $question : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    return ($definition['type'] ?? NULL) === self::TYPE;
  }

}
