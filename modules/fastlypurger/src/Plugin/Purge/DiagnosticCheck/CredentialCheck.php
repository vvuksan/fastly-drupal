<?php

namespace Drupal\fastlypurger\Plugin\Purge\DiagnosticCheck;

use Drupal\Core\Config\ConfigFactoryInterface;
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config, State $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config->get('fastly.settings');
    $this->state = $state;
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
      $container->get('fastly.state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run() {

    // This runs on every page - probably want to avoid a web service call here.
    //
    // $valid_purge_credentials = (!empty($this->config->get('api_key')))
    // ? $this->state->validatePurgeCredentials($this->config->get('api_key'))
    // : FALSE;
    //
    // $this->state->setPurgeCredentialsState($valid_purge_credentials);
    $purge_credentials_state = $this->state->getPurgeCredentialsState();

    if (!$purge_credentials_state) {
      $this->recommendation = $this->t("Invalid Api credentials. Make sure the token you are trying has at least global:read, purge_select, and purge_all scopes.");
      return SELF::SEVERITY_ERROR;
    }

    $this->recommendation = $this->t('Valid Api credentials detected.');
    return SELF::SEVERITY_OK;
  }

}
