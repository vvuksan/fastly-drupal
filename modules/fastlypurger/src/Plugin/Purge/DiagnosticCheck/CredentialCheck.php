<?php

namespace Drupal\fastlypurger\Plugin\Purge\DiagnosticCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Api;
use Drupal\fastly\State;
use Drupal\purge\Plugin\Purge\DiagnosticCheck\DiagnosticCheckBase;
use Drupal\purge\Plugin\Purge\DiagnosticCheck\DiagnosticCheckInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks if valid Api credentials have been entered for Fastly.
 *
 * @PurgeDiagnosticCheck(
 *   id = "fastly_creds",
 *   title = @Translation("Fastly - Credentials"),
 *   description = @Translation("Checks to see if the supplied account credentials for Fastly are valid."),
 *   dependent_queue_plugins = {},
 *   dependent_purger_plugins = {"fastly"}
 * )
 */
class CredentialCheck extends DiagnosticCheckBase implements DiagnosticCheckInterface {
  /**
   * The settings configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The Fastly state store.
   *
   * @var \Drupal\fastly\State
   */
  protected $state;

  /**
   * Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * Constructs a CredentialCheck object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The factory for configuration objects.
   * @param \Drupal\fastly\State $state
   *   Fastly state service for Drupal.
   * @param \Drupal\fastly\Api $api
   *   Fastly api service for Drupal.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config, State $state, Api $api) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config->get('fastly.settings');
    $this->state = $state;
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('fastly.state'),
      $container->get('fastly.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run() {

    // This runs on every page, so we probably want to avoid a web service call
    // when possible. Cache the state of the check to avoid rerunning every
    // time the purger is run.
    $purge_credentials_state = $this->state->getPurgeCredentialsState();
    // Try to check the purge credentials if they haven't been validated yet.
    if (!$purge_credentials_state) {
      $apiKey = getenv('FASTLY_API_TOKEN') ?: $this->config->get('api_key');
      $serviceId = getenv('FASTLY_API_SERVICE') ?: $this->config->get('service_id');
      // If both API key and Service are available then try to revalidate.
      if ($apiKey && $serviceId) {
        $purge_credentials_state = $this->api->validatePurgeCredentials();
        $this->state->setPurgeCredentialsState($purge_credentials_state);
      }
    }

    if (!$purge_credentials_state) {
      $this->recommendation = $this->t("Invalid Api credentials. Make sure the token you are trying has at least global:read, purge_select, and purge_all scopes.");
      return SELF::SEVERITY_ERROR;
    }

    $this->recommendation = $this->t('Valid Api credentials detected.');
    return SELF::SEVERITY_OK;
  }

}
