<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Api;
use Drupal\fastly\CacheTagsHashInterface;
use Drupal\fastly\State;
use Drupal\fastly\VclHandler;
use Drupal\fastly\Services\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class FastlySettingsForm Defines a form to configure module settings.
 *
 * @package Drupal\fastly\Form
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
   * The Fastly VclHandler.
   *
   * @var \Drupal\fastly\VclHandler
   */
  protected $vclHandler;

  /**
   * Tracks validity of credentials associated with Fastly Api.
   *
   * @var \Drupal\fastly\State
   */
  protected $state;

  /**
   * The Fastly webhook service.
   *
   * @var \Drupal\fastly\Services\Webhook
   */
  protected $webhook;

  /**
   * Host of current request.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * Constructs a FastlySettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\fastly\Api $api
   *   Fastly API for Drupal.
   * @param \Drupal\fastly\State $state
   *   Fastly state service for Drupal.
   * @param \Drupal\fastly\VclHandler $vclHandler
   *   Vcl handler.
   * @param \Drupal\fastly\Services\Webhook $webhook
   *   The Fastly webhook service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Api $api, State $state, VclHandler $vclHandler, Webhook $webhook, RequestStack $requestStack) {
    parent::__construct($config_factory);
    $this->api = $api;
    $this->state = $state;
    $this->vclHandler = $vclHandler;
    $this->webhook = $webhook;
    $this->baseUrl = $requestStack->getCurrentRequest()->getHost();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.api'),
      $container->get('fastly.state'),
      $container->get('fastly.vclhandler'),
      $container->get('fastly.services.webhook'),
      $container->get('request_stack')
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

    // Validate API credentials set directly in settings files.
    $purge_credentials_are_valid = $this->api->validatePurgeCredentials();

    if(getenv('FASTLY_API_TOKEN')) {
      $api_key = getenv('FASTLY_API_TOKEN');
    }
    else {
      $api_key = count($form_state->getValues()) ? $form_state->getValue('api_key') : $config->get('api_key');
    }

    $form['account_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Account settings'),
      '#open' => TRUE,
    ];
    $form['account_settings']['site_id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Site ID'),
      '#default_value' => $config->get('site_id'),
      '#required' => FALSE,
      '#description' => $this->t("Site identifier which is being prepended to cache tags. Use this if you have multiple sites in the same service in Fastly. Note: You can use the environment variable <code>FASTLY_SITE_ID</code> to set this also. If nothing is set in either config or env variable 'site1' will be added by default."),
    ];
    $purge_credentials_status_message = $purge_credentials_are_valid
      ? $this->t("An <em>API key</em> and <em>Service Id</em> pair are set that can perform purge operations. These credentials may not be adequate to performs all operations on this form. Can be overridden by FASTLY_API_TOKEN environment variable")
      : $this->t("You can find your personal API tokens on your Fastly Account Settings page. See <a href=':using_api_tokens'>using API tokens</a> for more information. If you don't have an account yet, please visit <a href=':signup'>https://www.fastly.com/signup</a> on Fastly. Can be overridden by FASTLY_API_TOKEN environment variable", [':using_api_tokens' => 'https://docs.fastly.com/guides/account-management-and-security/using-api-tokens', ':signup' => 'https://www.fastly.com/signup']);

    $form['service_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Service settings'),
      '#open' => TRUE,
    ];
    $form['account_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $api_key,
      '#required' => !$purge_credentials_are_valid,
      '#description' => $purge_credentials_status_message,
      // Update the listed services whenever the API key is modified.
      '#ajax' => [
        'callback' => '::updateServices',
        'wrapper' => 'edit-service-wrapper',
        'event' => 'change',
      ],
    ];

    $service_options = $this->getServiceOptions();

    $form['service_settings']['service_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Service'),
      '#options' => $service_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => getenv('FASTLY_API_SERVICE') ?: $config->get('service_id'),
      '#required' => !$purge_credentials_are_valid,
      '#description' => $this->t('A Service represents the configuration for your website to be served through Fastly. You can override this with FASTLY_API_SERVICE environment variable'),
      // Hide while no API key is set.
      '#states' => [
        'invisible' => [
          'input[name="api_key"]' => ['empty' => TRUE],
        ],
      ],
      '#prefix' => '<div id="edit-service-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['vcl'] = [
      '#type' => 'details',
      '#title' => $this->t('VCL update options'),
      '#open' => TRUE,
      '#description' => $this->t('Upload Fastly VCL snippets that improve cacheability of the site. Note: VCL assumes Drupal is the only app running. Please test in staging before applying in production.'),
    ];

    $form['vcl']['vcl_snippets'] = [
      '#type' => 'button',
      '#title' => 'Upload latest Fastly VCL snippets',
      '#value' => $this->t('Upload latest Fastly VCL snippets'),
      '#ajax' => [
        'callback' => [$this, 'uploadVcls'],
        'event' => 'click-custom',
      ],
      '#attached' => [
        'library' => [
          'fastly/fastly',
        ],
      ],
      '#suffix' => '<span class="email-valid-message"></span>',
    ];

    $form['vcl']['activate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate version on vcl upload'),
      '#default_value' => 1,
      '#attributes' => ['checked' => 'checked'],
    ];

    $form['vcl']['error_maintenance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Error/Maintenance'),
      '#default_value' => $config->get('error_maintenance'),
      '#required' => FALSE,
      '#description' => $this->t('Custom error / maintenance page content'),
      '#prefix' => '<div id="edit-maintenance-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['vcl']['upload_error_maintenance'] = [
      '#type' => 'button',
      '#value' => $this->t('Upload error maintenance page'),
      '#required' => FALSE,
      '#description' => $this->t('Upload error maintenance page'),
      '#ajax' => [
        'callback' => [$this, 'uploadMaintenance'],
        'event' => 'click-custom-upload-error-maintenance',
      ],
      '#attached' => [
        'library' => [
          'fastly/fastly',
        ],
      ],
      '#suffix' => '<span class="error-maintenance-message"></span>',
    ];

    $form['purge'] = [
      '#type' => 'details',
      '#title' => $this->t('Purging'),
      '#open' => TRUE,
    ];

    $form['purge']['purge_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Purge options'),
      '#open' => TRUE,
    ];

    $key_length = (int) $config->get('cache_tag_hash_length') ?: CacheTagsHashInterface::CACHE_TAG_HASH_LENGTH;
    $form['purge']['purge_options']['cache_tag_hash_length'] = [
      '#type' => 'number',
      '#min' => 4,
      '#max' => 5,
      '#title' => $this->t('Cache tag hash length'),
      '#description' => $this->t('For larger sites, it may be necessary to increase the length of the hashed cache tags (eg. <code>d0f</code>) that are used for the <code>Surrogate-Key</code> header and when purging content. This is due to <a href=":hash_collisions">hash collisions</a> which will result in excessive purging of content if the key length is too short. The current key length of <strong>%key_length</strong> can provide %hash_total unique cache keys. Note that this number should not be as large as the total number of cache tags in your site, just high enough to avoid most collisions during purging. Also you can override this with environment variable FASTLY_CACHE_TAG_HASH_LENGTH.', [':hash_collisions' => 'https://en.wikipedia.org/wiki/Hash_table#Collision_resolution', '%key_length' => $key_length, '%hash_total' => pow(64, $key_length)]),
      '#default_value' => $key_length,
    ];


    $form['purge']['purge_options']['purge_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Purge method'),
      '#description' => $this->t("Switch between Fastly's Instant-Purge and Soft-Purge methods."),
      '#default_value' => $config->get('purge_method') ?: self::FASTLY_INSTANT_PURGE,
      '#options' => [
        self::FASTLY_INSTANT_PURGE => $this->t('Use instant purge'),
        self::FASTLY_SOFT_PURGE => $this->t('Use soft purge'),
      ],
    ];

    $form['purge']['purge_actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Purge actions'),
      '#description' => $this->t('Purge / invalidate all site content: affects this site only. <br><b>WARNING: PURGE WHOLE SERVICE ACTION WILL DESTROY THE ENTIRE CACHE FOR ALL SITES IN THE CURRENT FASTLY SERVICE.</b>'),
      '#open' => TRUE,
    ];

    $form['purge']['purge_actions']['purge_all_keys'] = [
      '#type' => 'button',
      '#value' => $this->t('Purge / Invalidate all site content'),
      '#required' => FALSE,
      '#description' => $this->t('Purge all'),
      '#ajax' => [
        'callback' => [$this, 'purgeAllByKeys'],
        'event' => 'click-custom-purge-all-keys',
      ],
      '#attached' => [
        'library' => [
          'fastly/fastly',
        ],
      ],
      '#suffix' => '<span class="purge-all-message-keys"></span>',
    ];

    $form['purge']['purge_actions']['purge_all'] = [
      '#type' => 'button',
      '#value' => $this->t('Purge whole service'),
      '#required' => FALSE,
      '#description' => $this->t('Purge whole service'),
      '#ajax' => [
        'callback' => [$this, 'purgeAll'],
        'event' => 'click-custom-purge-all',
      ],
      '#attached' => [
        'library' => [
          'fastly/fastly',
        ],
      ],
      '#suffix' => '<span class="purge-all-message"></span>',
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
      '#description' => $this->t('Number of seconds to show stale content while revalidating cache. More details <a href=":serving_stale_content">here</a>.', [':serving_stale_content' => 'https://docs.fastly.com/guides/performance-tuning/serving-stale-content']),
      '#default_value' => $config->get('stale_while_revalidate_value') ?: 604800,
      '#states' => [
        'visible' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => FALSE],
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
      '#description' => $this->t('Number of seconds to show stale content if the origin server becomes unavailable/returns errors. More details <a
href=":serving_stale_content">here</a>.', [':serving_stale_content' => 'https://docs.fastly.com/guides/performance-tuning/serving-stale-content']),
      '#default_value' => $config->get('stale_if_error_value') ?: 604800,
      '#states' => [
        'visible' => [
          ':input[name="stale_if_error"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="stale_if_error"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['integrations'] = [
      '#type' => 'details',
      '#title' => $this->t('Integrations'),
      '#open' => TRUE,
    ];

    $form['integrations']['webhook'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook'),
      '#open' => TRUE,
    ];

    $form['integrations']['webhook']['webhook_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Webhook'),
      '#description' => $this->t("Enables or disabled webhook"),
      '#default_value' => $config->get('webhook_enabled'),
    ];

    $webhook_url = count($form_state->getValues()) ? $form_state->getValue('webhook_url') : $config->get('webhook_url');

    $form['integrations']['webhook']['webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#default_value' => $webhook_url,
      '#required' => FALSE,
      '#description' => $this->t("Incoming WebHook URL"),
      '#states' => [
        'visible' => [
          ':input[name="webhook_enabled"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="webhook_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['integrations']['webhook']['webhook_notifications'] = [
      '#type' => 'select',
      '#title' => $this->t('Send notifications for this events'),
      '#description' => $this->t('Chose which notification to push to your webhook'),
      '#options' => $this->getEventsNotificationOptions(),
      '#default_value' => $config->get('webhook_notifications'),
      '#multiple' => TRUE,
      '#size' => 5,
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
    // Get and use the API token value from this form for validation.
    $apiKey = $form_state->getValue('api_key');
    if (empty($apiKey) && !$this->api->validatePurgeCredentials()) {
      $form_state->setErrorByName('api_key', $this->t('Please enter an API token.'));
    }

    if(!empty($apiKey)) {
      $this->api->setApiKey($apiKey);
    }

    // Verify API token has adequate scope to use this form.
    if (!$this->api->validatePurgeToken()) {
      $form_state->setErrorByName('api_key', $this->t('Invalid API token. Make sure the token you are trying has at least <em>global:read</em>, <em>purge_all</em>, and <em>purge_all</em> scopes.'));
    }
  }

  /**
   * Gets options to be used as webhook config options.
   */
  public function getEventsNotificationOptions() {
    return [
      'purge_keys'  => " " . $this->t('Purge by keys') . " ",
      'purge_all'   => " " . $this->t('Purge all') . " ",
      'vcl_update'  => " " . $this->t('VCL update') . " ",
      'config_save'  => " " . $this->t('Config save') . " ",
      'maintenance_page' => " " . $this->t('Maintenance page upload') . " ",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set purge credentials state to TRUE if we have made it this far.
    $this->state->setPurgeCredentialsState(TRUE);

    $this->config('fastly.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('webhook_url', $form_state->getValue('webhook_url'))
      ->set('service_id', $form_state->getValue('service_id'))
      ->set('purge_method', $form_state->getValue('purge_method'))
      ->set('stale_while_revalidate', $form_state->getValue('stale_while_revalidate'))
      ->set('stale_while_revalidate_value', $form_state->getValue('stale_while_revalidate_value'))
      ->set('stale_if_error', $form_state->getValue('stale_if_error'))
      ->set('stale_if_error_value', $form_state->getValue('stale_if_error_value'))
      ->set('webhook_enabled', $form_state->getValue('webhook_enabled'))
      ->set('error_maintenance', $form_state->getValue('error_maintenance'))
      ->set('webhook_notifications', $form_state->getValue('webhook_notifications'))
      ->set('site_id', $form_state->getValue('site_id'))
      ->set('cache_tag_hash_length', $form_state->getValue('cache_tag_hash_length'))
      ->save();

    $this->webhook->sendWebHook($this->t("Fastly module configuration changed on %base_url", ['%base_url' => $this->baseUrl]), "config_save");

    parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves options for the Fastly service.
   *
   * @return array
   *   Array of service ids mapped to service names.
   */
  protected function getServiceOptions() {
    if (empty($this->api->getApiKey())) {
      return [];
    }

    $services = $this->api->getServices();
    if (empty($services)) {
      return [];
    }

    $service_options = [];
    foreach ($services as $service) {
      $service_options[$service->id] = $service->name;
    }

    ksort($service_options);
    return $service_options;
  }

  /**
   * Upload Vcls.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AjaxResponse.
   */
  public function uploadVcls(array $form, FormStateInterface $form_state) {
    $activate = $form_state->getValue("activate");
    $response = new AjaxResponse();
    $message = $this->vclHandler->execute($activate);
    $response->addCommand(new HtmlCommand('.email-valid-message', $message));
    return $response;
  }

  /**
   * Purge all.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AjaxResponse.
   */
  public function purgeAll(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $purge = $this->api->purgeAll(FALSE);
    if (!$purge) {
      $message = $this->t("Something went wrong while purging / invalidating content. Please, check logs for more info.");
    }
    else {
      $message = $this->t("Whole service purged successfully.");
    }
    $response->addCommand(new HtmlCommand('.purge-all-message', $message));
    return $response;
  }

  /**
   * Uploads maintenance page and saves configuration.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AjaxResponse.
   */
  public function uploadMaintenance(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    if ($this->config("error_maintenance") != $form_state->getValue('error_maintenance')) {
      $upload = $this->vclHandler->uploadMaintenancePage($form_state->getValue('error_maintenance'));
    }
    if (!$upload) {
      $message = $this->t("Maintenance page upload failed.");
    }
    else {
      $message = $this->t("Maintenance page uploaded successfuly.");

      $this->webhook->sendWebHook($this->t("Fastly Error / Maintenance page updated on %base_url", ['%base_url' => $this->baseUrl]), "config_save");

      $this->submitForm($form, $form_state);
    }
    $response->addCommand(new HtmlCommand('.error-maintenance-message', $message));

    return $response;
  }

  /**
   * Purge all.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AjaxResponse.
   */
  public function purgeAllByKeys(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $purge = $this->api->purgeAll();
    if (!$purge) {
      $message = $this->t("Something went wrong while purging / invalidating content. Please, check logs for more info.");
    }
    else {
      $message = $this->t("All site content is purged / invalidated successfully.");
    }
    $response->addCommand(new HtmlCommand('.purge-all-message-keys', $message));
    return $response;
  }

}
