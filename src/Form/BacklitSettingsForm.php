<?php

declare(strict_types=1);

namespace Drupal\backlit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure which content types get Lit SSR.
 */
final class BacklitSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'backlit_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['backlit.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('backlit.settings');
    $enabled = $config->get('enabled_bundles') ?? [];

    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $options = [];
    foreach ($types as $type) {
      $options[$type->id()] = $type->label();
    }

    $form['enabled_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types with SSR enabled'),
      '#description' => $this->t('Web components on pages of the selected content types will be server-rendered with Declarative Shadow DOM.'),
      '#options' => $options,
      '#default_value' => $enabled,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = array_values(array_filter($form_state->getValue('enabled_bundles')));

    $this->config('backlit.settings')
      ->set('enabled_bundles', $enabled)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
