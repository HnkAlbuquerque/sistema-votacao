<?php

declare(strict_types=1);

namespace Drupal\voting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for voting questions.
 *
 * Administrators can do everything. Everyone else may only view active
 * questions, and only with the "view voting questions" permission.
 */
final class QuestionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\voting\Entity\QuestionInterface $entity */
    if ($operation === 'view') {
      $public = AccessResult::allowedIf($entity->isPublished())
        ->andIf(AccessResult::allowedIfHasPermission($account, 'view voting questions'))
        ->addCacheableDependency($entity);
      return $public->orIf(AccessResult::allowedIfHasPermission($account, 'administer voting'));
    }
    // Update and delete: only the admin permission, handled by the parent.
    return parent::checkAccess($entity, $operation, $account);
  }

}
