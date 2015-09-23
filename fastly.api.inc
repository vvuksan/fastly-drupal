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
   */
  public function purgePath($path) {
    global $base_url;
    $path = str_replace($base_url, '', $path);
    $this->purgeQuery($path);
  }

  /**
   * Performs an actual purge request for the given path.
   */
  protected function purgeQuery($path) {
    $url = url($path, array('absolute' => TRUE, 'alias' => TRUE));
    $options = array(
      'headers' => array(
        'Host' => $_SERVER['HTTP_HOST'],
      ),
      'method' => 'PURGE',
    );
    if (module_exists('httprl')) {
      // We just want to execute the queue but not wait.
      $options['blocking'] = FALSE;
      httprl_request(array($url), $options);
    }
    else {
      drupal_http_request($url, $options);
    }
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
}
