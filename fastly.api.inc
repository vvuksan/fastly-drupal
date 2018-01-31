<?php

/**
 * @file
 * Contains Faslt class that handles API calls to the Fastly service.
 */

/**
 * Fastly API for Drupal.
 */
class Fastly {

  /**
   * Construct function for the Fastly class.
   */
  public function __construct($api_key, $service_id) {
    $this->api_key = $api_key;
    $this->service_id = $service_id;
    $this->host = 'https://api.fastly.com/';
    // $this->host = 'http://stg.fastly.com/';
  }

  /**
   * Registers a new customer.
   *
   * @param array $data
   *   Data to post to Fastly for the signup request.
   *
   * @return array
   *   Data returned from Fastly.s
   */
  public function signup($data) {
    $headers['Content-Type'] = 'application/x-www-form-urlencoded';

    $result = $this->query('plugin/drupal/signup', $data, 'POST', $headers);

    return json_decode($result->data);
  }

  /**
   * Used to validate API key and service ID.
   *
   * @return bool
   *   FALSE if any corrupt data is passed.
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
   *   An array of data to create the service with.
   *
   * @return object
   *   Data returned from the Fastly request.
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
   */
  public function getSettings() {
    if ($active_version = $this->getActiveVersion()) {
      $result = $this->query('service/' . $this->service_id . '/version/' . $active_version . '/settings');
      return json_decode($result->data);
    }
    return NULL;
  }

  /**
   * Gets the settings for a version.
   */
  public function getSetting($setting_id) {
    $settings = $this->getSettings();

    if ($settings && isset($settings->{$setting_id})) {
      return $settings->{$setting_id};
    }
  }

  /**
   * Updates the settings for a version.
   *
   * @param array $data
   *   An array of settings to update.
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
   *
   * @deprecated Use purgeQuery() instead.
   */
  public function purgePath($path) {
    $this->purgeQuery($path);
  }

  /**
   * Performs an actual purge request for the given url.
   */
  public function purgeQuery($original_url) {
    $url = url($original_url, array('absolute' => TRUE, 'alias' => TRUE));
    $options = array('method' => 'PURGE');
    if (variable_get('fastly_purge_type', 'immediate') == 'soft') {
      $options['headers']['Fastly-Soft-Purge'] = 1;
    }
    if (module_exists('httprl')) {
      // We just want to execute the queue but not wait.
      $options['blocking'] = FALSE;

      // Support for alternative PURGE endpoint.
      $purge_endpoint = variable_get('fastly_purge_endpoint', NULL);
      if ($purge_endpoint) {
        // Extract Host header from URL.
        $parsed_url = @parse_url($url);
        $default_port = $parsed_url['scheme'] == 'https' ? 443 : 80;
        $port = isset($parsed_url['port']) ? $parsed_url['port'] : $default_port;
        $options['headers']['Host'] = $parsed_url['host'] . ($port != $default_port ? ':' . $port : '');
        // Use the purge endpoint as base URL for the PURGE request.
        $url = url($original_url, array(
          'absolute' => TRUE,
          'alias' => TRUE,
          'base_url' => $purge_endpoint,
        ));
      }
      httprl_request(array($url), $options);
      httprl_send_request();
    }
    else {
      drupal_http_request($url, $options);
    }
  }

  /**
   * Purge cache by key.
   */
  public function purgeKey($key) {
    $this->purgeKeys(array($key));
  }

  /**
   * Purge cache by multiple keys.
   */
  public function purgeKeys(array $keys) {
    $uris = array();
    foreach ($keys as $key) {
      $uris[] = 'service/' . $this->service_id . '/purge/' . $key;
    }
    $this->queryMultiple($uris, array(), 'POST');
  }

  /**
   * Gets active version number for the current service.
   */
  protected function getActiveVersion() {
    $service = json_decode($this->query('service/' . $this->service_id)->data);

    if (is_object($service) && isset($service->versions)) {
      foreach ($service->versions as $version) {
        if ($version->active) {
          return $version->number;
        }
      }
    }
    return NULL;
  }

  /**
   * Performs http queries to Fastly API server.
   *
   * @param string $uri
   *   The uri to use for the request, appended to the host.
   * @param array $data
   *   (optional) Data to send with the request.
   * @param string $method
   *   (optional) The method to use for the request, defaults to GET.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   *
   * @return object
   *   From drupal_http_request().
   */
  protected function query($uri, $data = array(), $method = 'GET', $headers = array()) {
    $url = $this->host . $uri;

    $options['headers'] = $headers;
    $options['method'] = $method;
    $options['data'] = http_build_query($data);

    if ($this->api_key) {
      $options['headers']['Fastly-Key'] = $this->api_key;
    }

    $result = drupal_http_request($url, $options);

    return $result;
  }

  /**
   * Performs multiple HTTP queries to Fastly API server without returning a result.
   *
   * @param array $uris
   *   The URIs to use for the request, appended to the host.
   * @param array $data
   *   (optional) Data to send with the request.
   * @param string $method
   *   (optional) The method to use for the request, defaults to GET.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   */
  protected function queryMultiple(array $uris, $data = array(), $method = 'GET', $headers = array()) {
    foreach ($uris as $uri) {
      $urls[] = $this->host . $uri;
    }

    $options['headers'] = $headers;
    $options['method'] = $method;
    $options['data'] = http_build_query($data);

    if ($this->api_key) {
      $options['headers']['Fastly-Key'] = $this->api_key;
    }

    if (module_exists('httprl')) {
      // We just want to execute the queue but not wait.
      $options['blocking'] = FALSE;
      httprl_request($urls, $options);
      httprl_send_request();
    }
    else {
      foreach ($urls as $url) {
        drupal_http_request($url, $options);
      }
    }
  }

  /**
   *
   */
  public function makeRequest($uri, $data = array(), $method = 'GET', $headers = array()) {
    return $this->query($uri, $data, $method, $headers);
  }

}
