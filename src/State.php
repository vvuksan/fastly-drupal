<?php

namespace Drupal\fastly;

use Drupal\Core\State\StateInterface;

/**
 * Tracks validity of credentials associated with Fastly Api.
 */
class State {

  const VALID_PURGE_CREDENTIALS = 'fastly.state.valid_purge_credentials';

  /**
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
   * Get the Drupal state representing whether or not the configured Fastly Api
   * credentials are sufficient to perform all supported types of purge requests.
   *
   * @return mixed
   */
  public function getPurgeCredentialsState() {
    $state = $this->state->get(self::VALID_PURGE_CREDENTIALS);
    return $state;
  }

  /**
   * Get the Drupal state representing whether or not the configured Fastly Api
   * credentials are sufficient to perform all supported types of purge requests.
   */
  public function setPurgeCredentialsState($state = FALSE) {
    $this->state->set(self::VALID_PURGE_CREDENTIALS, $state);
  }

}
