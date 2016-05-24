<?php
/**
 * @file
 * This is the Fastly admin settings form to set specific Fastly service.
 * Contains \Drupal\fastly\Form.
 */

namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to configure module settings.
 */
class FastlySettingsForm extends ConfigFormBase {

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
  public function getFormID() {
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
    $form['api_key'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $api_key,
      '#required' => TRUE,
      '#description' => t("You can find your API key on the Fastly Account Settings page. If you don't have an account yet, please visit <a href='https://www.fastly.com/signup'>https://www.fastly.com/signup</a> on Fastly."),
      // Update the listed services whenever the API key is modified.
      '#ajax' => array(
        'callback' => '::updateServices',
        'wrapper' => 'edit-service-wrapper',
        'event' => 'change',
      ),
    );

    $service_options = $this->getServiceOptions($api_key);
    $form['service_id'] = array(
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
    );

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
      ->save();

    parent::submitForm($form, $form_state);
  }

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

  protected function isValidApiKey($api_key) {
    if (empty($api_key)) {
      return FALSE;
    }

    $this->fastlyApi->setApiKey($api_key);
    return $this->fastlyApi->validateApiKey();
  }

}
