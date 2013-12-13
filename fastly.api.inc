<?php

/**
 * Fastly API for Drupal.
 */
class Fastly {
  public function __construct($api_key, $service_id) {
    $this->api_key = $api_key;
    $this->service_id = $service_id;
    //$this->host = 'https://api.fastly.com/';
    $this->host = 'http://stg.fastly.com/';
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

      unset($data['hostname']);
      unset($data['domain']);
      unset($data['address']);

      $this->query('service/' . $service->id . '/version/1/backend', $data, 'POST');
      $this->query('service/' . $service->id . '/version/1/syslog', $data, 'POST');
    }

    return $service;
  }

  /**
   * Gets the settings for a version.
   * @param int $version
   */
  public function getSettings($version = 1) {
    $result = $this->query('service/' . $this->service_id . '/version/' . $version . '/settings');

    return json_decode($result->data);
  }

  /**
   * Updates the settings for a version.
   * @param array $data
   * @param int $version
   */
  public function updateSettings($data, $version = 1) {
    $headers['Content-Type'] = 'application/x-www-form-urlencoded';

    $this->query('service/' . $this->service_id . '/version/' . $version . '/settings', $data, 'PUT', $headers);
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
