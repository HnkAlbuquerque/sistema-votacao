<?php

declare(strict_types=1);

namespace Drupal\voting\Theme;

use Drupal\file\FileInterface;
use Drupal\voting\Entity\OptionInterface;

/**
 * Preprocess callbacks for the templates provided by the Voting module.
 */
final class VotingThemePreprocess {

  /**
   * Image style used for the option picture.
   */
  public const IMAGE_STYLE = 'medium';

  /**
   * Prepares variables for the option card template.
   *
   * Turns the option entity into plain variables so the template, and any
   * theme overriding it, never touches the entity API.
   */
  public function preprocessOptionCard(array &$variables): void {
    $option = $variables['option'];
    if (!$option instanceof OptionInterface) {
      $variables['title'] = '';
      $variables['description'] = '';
      $variables['image'] = NULL;
      return;
    }

    $variables['title'] = $option->getTitle();
    $variables['description'] = $option->getDescription();
    $variables['image'] = NULL;

    $item = $option->get('image')->first();
    $file = $item?->entity;
    if ($file instanceof FileInterface) {
      $variables['image'] = [
        '#theme' => 'image_style',
        '#style_name' => self::IMAGE_STYLE,
        '#uri' => $file->getFileUri(),
        '#alt' => $item->alt ?: $option->getTitle(),
        '#width' => $item->width,
        '#height' => $item->height,
      ];
    }
  }

}
