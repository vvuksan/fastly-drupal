<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Api;
use Drupal\fastly\State;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;


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
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * VclHandler.
   *
   * @var \Drupal\fastly\VclHandler
   */
  protected $vclHandler;

  /**
   * @var \Drupal\fastly\State
   */
  protected $state;

  /**
   * Constructs a \Drupal\fastly\Form object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\fastly\Api $api
   *   Fastly API for Drupal.
   * @param \Drupal\fastly\State $state
   *   Fastly state service for Drupal.
   * @param \Drupal\fastly\VclHandler
   *   Vcl handler
   */
  public function __construct(ConfigFactoryInterface $config_factory, Api $api, State $state, VclHandler $vclHandler) {
    parent::__construct($config_factory);
    $this->api = $api;
    $this->state = $state;
    $this->vclHandler = $vclHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.api'),
      $container->get('fastly.state'),
      $container->get('fastly.vclhandler')
    );
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

    $form['account_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Account settings'),
      '#open' => TRUE,
    ];

    $form['service_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Service settings'),
      '#open' => TRUE,
    ];
    $form['account_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $api_key,
      '#required' => TRUE,
      '#description' => t("You can find your personal API tokens on your Fastly Account Settings page. See <a href='https://docs.fastly.com/guides/account-management-and-security/using-api-tokens'>using API tokens</a> for more information. It is recommended that the token you provide has at least <em>global:read</em>, <em>purge_select</em>, and <em>purge_all</em> scopes. If you don't have an account yet, please visit <a href='https://www.fastly.com/signup'>https://www.fastly.com/signup</a> on Fastly."),
      // Update the listed services whenever the API key is modified.
      '#ajax' => [
        'callback' => '::updateServices',
        'wrapper' => 'edit-service-wrapper',
        'event' => 'change',
      ],
    ];

    $service_options = $this->getServiceOptions($api_key);

    $form['service_settings']['service_id'] = [
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

    $form['purge'] = [
      '#type' => 'details',
      '#title' => $this->t('Purge options'),
      '#open' => TRUE,
    ];


    $form['purge']['purge_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Purge method'),
      '#description' => $this->t("Switch between Fastly's Instant-Purge and Soft-Purge methods."),
      '#default_value' => $config->get('purge_method') ?: self::FASTLY_INSTANT_PURGE,
      '#options' => [
        self::FASTLY_INSTANT_PURGE => $this->t('Use instant purge'),
        self::FASTLY_SOFT_PURGE => $this->t('Use soft purge'),
      ],
    ];

    $form['stale_content'] = [
      '#type' => 'details',
      '#title' => $this->t('Stale content options'),
      '#open' => TRUE,
    ];

    $form['stale_content']['stale_while_revalidate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stale while revalidate'),
      '#description' => $this->t("Activate the stale-while-revalidate tag to improve experience for users attempting to access expired content."),
      '#default_value' => $config->get('stale_while_revalidate'),
    ];

    $form['stale_content']['stale_while_revalidate_value'] = [
      '#type' => 'number',
      '#description' => $this->t('Number of seconds to show stale content while revalidating cache. More details <a href="https://docs.fastly.com/guides/performance-tuning/serving-stale-content">here</a>.'),
      '#default_value' => $config->get('stale_while_revalidate_value') ?: 604800,
      '#states' => [
        'visible' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => true],
        ],
        'required' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => false],
        ],
      ],
    ];

    $form['stale_content']['stale_if_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stale if error'),
      '#description' => $this->t('Number of seconds to show stale content if the origin server becomes unavailable.'),
      '#default_value' => $config->get('stale_if_error'),
    ];

    $form['stale_content']['stale_if_error_value'] = [
      '#type' => 'number',
      '#description' => $this->t('Number of seconds to show stale content if the origin server becomes unavailable/returns errors. More details <a href="https://docs.fastly.com/guides/performance-tuning/serving-stale-content">here</a>.'),
      '#default_value' => $config->get('stale_if_error_value') ?: 604800,
      '#states' => [
        'visible' => [
          ':input[name="stale_if_error"]' => ['checked' => true],
        ],
        'required' => [
          ':input[name="stale_if_error"]' => ['checked' => false],
        ],
      ],
    ];

    $form['vcl'] = [
      '#type' => 'details',
      '#title' => $this->t('VCL update options'),
      '#open' => TRUE,
    ];


    $form['vcl']['vcl_snippets'] = [
      '#type' => 'button',
      '#value' => $this->t('Upload latest Fastly VCL snippets'),
      '#required' => false,
      '#description' => t('Uploads/updates custom VCL used to optimize Fastly services for Drupal. Not required however strongly encouraged.'),
      '#ajax' => [
        'callback' =>[$this, 'uploadVcls'],
        'event' => 'click-custom',
      ],
      '#attached' => [
        'library' => [
          'fastly/fastly',
        ],
      ],
      '#suffix' => '<span class="email-valid-message"></span>'
    ];


    $form['vcl']['vcl_snippets']['activate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate version on vcl upload'),
      '#default_value' => 1,
      '#attributes' => array('checked' => 'checked')
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Handles changing the API key.
   */
  public function updateServices($form, FormStateInterface $form_state) {
    return $form['service_settings']['service_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$this->api->validatePurgeCredentials($form_state->getValue('api_key'))) {
      $form_state->setErrorByName('api_key', $this->t('Invalid API token. Make sure the token you are trying has at least <em>global:read</em>, <em>purge_all</em>, and <em>purge_all</em> scopes.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set purge credentials state to TRUE if we have made it this far.
    $this->state->setPurgeCredentialsState(TRUE);

    $this->config('fastly.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('service_id', $form_state->getValue('service_id'))
      ->set('purge_method', $form_state->getValue('purge_method'))
      ->set('stale_while_revalidate', $form_state->getValue('stale_while_revalidate'))
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
    if (empty($this->api->apiKey)) {
      return [];
    }

    $services = $this->api->getServices();
    $service_options = [];
    foreach ($services as $service) {
      $service_options[$service->id] = $service->name;
    }

    ksort($service_options);
    return $service_options;
  }

  /**
   * Upload Vcls
   *
   * @param $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function uploadVcls($form, FormStateInterface $form_state) {
    $activate = $form_state->getValue("activate");
    $response = new AjaxResponse();
    $message = $this->vclHandler->execute($activate);
    $response->addCommand(new HtmlCommand('.email-valid-message', $message));
    return $response;
  }
}
