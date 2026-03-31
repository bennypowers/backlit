<?php

declare(strict_types=1);

namespace Drupal\backlit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Backlit SSR settings.
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

    $form['render_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Rendering mode'),
      '#options' => [
        'post_render' => $this->t('Render array (#post_render) -- integrates with Drupal render cache, recommended'),
        'response' => $this->t('Response subscriber -- processes the full response HTML, bypasses render cache'),
      ],
      '#default_value' => $config->get('render_mode') ?? 'post_render',
      '#description' => $this->t('The render array mode caches DSD-enhanced markup per entity. The response mode processes the entire page on every cache miss.'),
    ];

    $enabled = $config->get('enabled_bundles') ?? [];

    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $options = [];
    foreach ($types as $type) {
      $options[$type->id()] = $type->label();
    }

    $form['minify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Minify shadow roots'),
      '#description' => $this->t('Remove HTML and CSS comments from rendered shadow roots. Lit SSR markers required for hydration are preserved.'),
      '#default_value' => $config->get('minify') ?? FALSE,
    ];

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
      ->set('render_mode', $form_state->getValue('render_mode'))
      ->set('minify', (bool) $form_state->getValue('minify'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
