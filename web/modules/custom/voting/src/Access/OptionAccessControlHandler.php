<?php

declare(strict_types=1);

namespace Drupal\voting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for voting options: viewing follows the parent question.
 */
final class OptionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\voting\Entity\OptionInterface $entity */
    if ($operation === 'view') {
      $question = $entity->getQuestion();
      if ($question === NULL) {
        return AccessResult::forbidden('Orphan option.')->addCacheableDependency($entity);
      }
      return $question->access('view', $account, TRUE)->addCacheableDependency($entity);
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
