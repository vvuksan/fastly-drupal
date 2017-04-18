<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a form to configure module settings.
 */
class FastlySettingsForm extends ConfigFormBase {

  /**
   * Constants for the values of instant and soft purge methods.
   */
  const FASTLY_INSTANT_PURGE = 'instant';
  const FASTLY_SOFT_PURGE = 'soft';

  /**
   * The Fastly API.
   *
   * @var \Drupal\Fastly\Api
   */
  protected $fastlyApi;

  /**
   * Constructs a \Drupal\fastly\Form object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->fastlyApi = \Drupal::service('fastly.api');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fastly_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fastly.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fastly.settings');

    $api_key = count($form_state->getValues()) ? $form_state->getValue('api_key') : $config->get('api_key');
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $api_key,
      '#required' => TRUE,
      '#description' => t("You can find your API key on the Fastly Account Settings page. If you don't have an account yet, please visit <a href='https://www.fastly.com/signup'>https://www.fastly.com/signup</a> on Fastly."),
      // Update the listed services whenever the API key is modified.
      '#ajax' => [
        'callback' => '::updateServices',
        'wrapper' => 'edit-service-wrapper',
        'event' => 'change',
      ],
    ];

    $service_options = $this->getServiceOptions($api_key);
    $form['service_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Service'),
      '#options' => $service_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $config->get('service_id'),
      '#required' => TRUE,
      '#description' => t('A Service represents the configuration for your website to be served through Fastly.'),
      // Hide while no API key is set.
      '#states' => [
        'invisible' => [
          'input[name="api_key"]' => ['empty' => TRUE],
        ],
      ],
      '#prefix' => '<div id="edit-service-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['purge_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Purge method'),
      '#description' => $this->t("Switch between Fastly's Instant-Purge and Soft-Purge methods."),
      '#default_value' => $config->get('purge_method') ?: self::FASTLY_INSTANT_PURGE,
      '#options' => [
        self::FASTLY_INSTANT_PURGE => $this->t('Use instant purge'),
        self::FASTLY_SOFT_PURGE => $this->t('Use soft purge'),
      ],
    ];

    $form['purge'] = [
      '#type' => 'details',
      '#title' => $this->t('Soft purge options'),
      '#open' => TRUE,
      '#states' => [
        'invisible' => [
          ':input[name="purge_method"]' => ['value' => self::FASTLY_INSTANT_PURGE],
        ],
        'required' => [
          ':input[name="purge_method"]' => ['value' => self::FASTLY_SOFT_PURGE],
        ],
      ],
    ];

    $form['purge']['stale_while_revalidate_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Stale while revalidate'),
      '#description' => $this->t('The number in seconds to show stale content while cache revalidation.'),
      '#default_value' => $config->get('stale_while_revalidate_value') ?: 604800,
    ];

    $form['purge']['stale_if_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stale if error'),
      '#description' => $this->t("Activate the stale-if-error tag for serving stale content if the origin server becomes unavailable."),
      '#default_value' => $config->get('stale_if_error'),
    ];

    $form['purge']['stale_if_error_value'] = [
      '#type' => 'number',
      '#description' => $this->t('The number in seconds to show stale content if the origin server becomes unavailable.'),
      '#default_value' => $config->get('stale_if_error_value') ?: 604800,
      '#states' => [
        'invisible' => [
          ':input[name="stale_if_error"]' => ['checked' => FALSE],
        ],
        'required' => [
          ':input[name="stale_if_error"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Handles changing the API key.
   */
  public function updateServices($form, FormStateInterface $form_state) {
    return $form['service_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$this->isValidApiKey($form_state->getValue('api_key'))) {
      $form_state->setErrorByName('api_key', $this->t('Invalid API key.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fastly.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('service_id', $form_state->getValue('service_id'))
      ->set('purge_method', $form_state->getValue('purge_method'))
      ->set('stale_while_revalidate_value', $form_state->getValue('stale_while_revalidate_value'))
      ->set('stale_if_error', $form_state->getValue('stale_if_error'))
      ->set('stale_if_error_value', $form_state->getValue('stale_if_error_value'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves options for the Fastly service.
   *
   * @param string $api_key
   *   API key.
   *
   * @return array
   *   Array of service ids mapped to service names.
   */
  protected function getServiceOptions($api_key) {
    if (empty($this->fastlyApi->apiKey)) {
      return [];
    }

    $services = $this->fastlyApi->getServices();
    $service_options = [];
    foreach ($services as $service) {
      $service_options[$service->id] = $service->name;
    }

    ksort($service_options);
    return $service_options;
  }

  /**
   * Provides indicator that user entered credentials are valid.
   *
   * @param string $api_key
   *   API key.
   *
   * @return bool
   *   TRUE if API key is valid. FALSE otherwise.
   */
  protected function isValidApiKey($api_key) {
    if (empty($api_key)) {
      return FALSE;
    }

    $this->fastlyApi->setApiKey($api_key);
    return $this->fastlyApi->validateApiKey();
  }

}
