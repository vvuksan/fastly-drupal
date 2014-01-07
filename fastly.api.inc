<?php

/**
 * Fastly API for Drupal.
 */
class Fastly {
  public function __construct($api_key, $service_id) {
    $this->api_key = $api_key;
    $this->service_id = $service_id;
    $this->host = 'https://api.fastly.com/';
    //$this->host = 'http://stg.fastly.com/';
    $this->log_enabled = variable_get('fastly_log_enabled', 0);
  }

  /**
   * Registers a new customer.
   *
   * @param array $data
   */
  public function signup($data) {
    $headers['Content-Type'] = 'application/x-www-form-urlencoded';

    $result = $this->query('plugin/drupal/signup', $data, 'POST', $headers);

    return json_decode($result->data);
  }

  /**
   * Used to validate API key and service ID. Returns FALSE if any corrupt data is passed.
   */
  public function validate() {
    return $this->query('current_customer')->status_message == 'OK';
  }

  /**
   * Gets a list of services for the current customer.
   */
  public function getServices() {
    $result = $this->query('service');

    return json_decode($result->data);
  }

  /**
   * Creates a default service for our website once we signed up.
   *
   * @param array $data
   * @return mixed
   */
  public function createService($data) {
    $service = json_decode($this->query('service', $data, 'POST')->data);

    if (isset($service->id)) {
      $data['service'] = $service->id;

      $this->query('service/' . $service->id . '/version/1/domain', array('name' => $data['domain']), 'POST');

      unset($data['domain']);
      unset($data['address']);

      $this->query('service/' . $service->id . '/version/1/backend', $data, 'POST');
      $this->query('service/' . $service->id . '/version/1/syslog', $data, 'POST');
      $this->query('service/' . $service->id . '/version/1/activate', array(), 'PUT');
    }

    return $service;
  }

  /**
   * Gets the settings for a version.
   * @param int $version
   */
  public function getSettings() {
    $result = $this->query('service/' . $this->service_id . '/version/' . $this->getActiveVersion() . '/settings');

    return json_decode($result->data);
  }

  /**
   * Updates the settings for a version.
   * @param array $data
   * @param int $version
   */
  public function updateSettings($data) {
    if ($this->service_id) {
      $active_version = $this->getActiveVersion();

      $new_version = json_decode($this->query('service/' . $this->service_id . '/version/' . $active_version . '/clone', array(), 'PUT')->data);

      $headers['Content-Type'] = 'application/x-www-form-urlencoded';

      $this->query('service/' . $this->service_id . '/version/' . $new_version->number . '/settings', $data, 'PUT', $headers);
      $this->query('service/' . $this->service_id . '/version/' . $new_version->number . '/activate', array(), 'PUT');
    }
  }

  /**
   * Purge whole service.
   */
  public function purgeAll() {
    $this->query('service/' . $this->service_id . '/purge_all', array(), 'POST');
  }

  /**
   * Purge cache by path.
   */
  public function purgePath($path) {
    global $base_url;

    $path = str_replace($base_url, '', $path);

    $this->query($path, array(), 'PURGE', array('Host' => $_SERVER['SERVER_NAME']));
  }

  /**
   * Purge cache by key.
   */
  public function purgeKey($key) {
    $this->query('service/' . $this->service_id . '/purge/' . $key, array(), 'POST');
  }

  /**
   * Gets active version number for the current service.
   */
  private function getActiveVersion() {
    $service = json_decode($this->query('service/' . $this->service_id)->data);

    foreach ($service->versions as $version) {
      if ($version->active) {
        return $version->number;
      }
    }
  }

  /**
   * Performs http queries to Fastly API server.
   *
   * @param string $uri
   * @param array $data
   * @param string $method
   * @param array $headers
   *
   * @return object
   */
  private function query($uri, $data = array(), $method = 'GET', $headers = array()) {
    $url = $this->host . $uri;

    $options['headers'] = $headers;
    $options['method'] = $method;
    $options['data'] = http_build_query($data);

    if ($this->api_key) {
      $options['headers']['Fastly-Key'] = $this->api_key;
    }

    $result = drupal_http_request($url, $options);

    if ($this->log_enabled) {
      watchdog('fastly', $result->request, array(), WATCHDOG_INFO);
    }

    return $result;
  }
}
