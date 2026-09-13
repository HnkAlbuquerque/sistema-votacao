<?php

declare(strict_types=1);

namespace Drupal\voting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\voting\VotingSettings;

/**
 * Global voting settings: kill switch and rate limit.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [VotingSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Voting enabled'),
      '#description' => $this->t('When unchecked, nobody can vote or read results, on the site or through the API. Administration keeps working.'),
      '#config_target' => VotingSettings::CONFIG_NAME . ':enabled',
    ];
    $form['flood'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate limiting'),
      '#open' => TRUE,
    ];
    $form['flood']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum vote attempts per user'),
      '#min' => 1,
      '#config_target' => VotingSettings::CONFIG_NAME . ':flood.limit',
    ];
    $form['flood']['window'] = [
      '#type' => 'number',
      '#title' => $this->t('Window (seconds)'),
      '#min' => 1,
      '#field_suffix' => $this->t('seconds'),
      '#config_target' => VotingSettings::CONFIG_NAME . ':flood.window',
    ];

    return parent::buildForm($form, $form_state);
  }

}
