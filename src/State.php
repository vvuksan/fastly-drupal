<?php

namespace Drupal\fastly;

use Drupal\Core\State\StateInterface;

/**
 * Tracks validity of credentials associated with Fastly Api.
 */
class State {

  const VALID_PURGE_CREDENTIALS = 'fastly.state.valid_purge_credentials';

  /**
   * The drupal state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * ValidateCredentials constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The drupal state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Get the state of the Fastly credentials related to Purge functionality.
   *
   * Get the Drupal state representing whether the configured Fastly Api
   * credentials are sufficient to perform all supported purge operations.
   *
   * @return mixed
   *   The state of the configured Fastly Api credentials
   */
  public function getPurgeCredentialsState() {
    $state = $this->state->get(self::VALID_PURGE_CREDENTIALS);
    return $state;
  }

  /**
   * Set the state of the Fastly credentials related to Purge functionality.
   *
   * Set the Drupal state representing whether or not the configured Fastly Api
   * credentials are sufficient to perform all supported purge operations.
   */
  public function setPurgeCredentialsState($state = FALSE) {
    $this->state->set(self::VALID_PURGE_CREDENTIALS, $state);
  }

}
