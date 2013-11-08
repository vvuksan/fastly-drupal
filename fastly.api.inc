<?php

/**
 * Fastly API for Drupal.
 */
class Fastly {
  public function __construct($api_key, $service_id) {
    $this->api_key = $api_key;
    $this->service_id = $service_id;
    $this->host = 'https://api.fastly.com/';
  }

  /**
   * Registers a new customer.
   *
   * @param array $data
   */
  public function signup($data) {
    $result = $this->query('signup/and_confirm', $data, 'POST');

    if ($result->status_message == 'OK') {
      return json_decode($result->data);
    } else {
      return FALSE;
    }
  }

  /**
   * Used to validate API key and service ID. Returns FALSE if any corrupt data is passed.
   */
  public function validate() {
    $customer = $this->query('current_customer');

    if ($customer->status_message == 'OK') {
      $customer = json_decode($customer->data);

      $service = $this->query('/service/' . $this->service_id . '/details');

      return ($service->status_message == 'OK');
    }
    else {
      return FALSE;
    }
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

    $this->query('service/' . $service->id . '/version/1/domain', array('name' => $data['domain']), 'POST');
    $this->query('service/' . $service->id . '/version/1/backend', $data, 'POST');

    return $service;
  }

  /**
   * Purge whole service.
   */
  public function purgeAll() {
    $this->query('/service/' . $this->service_id . '/purge_all', array(), 'POST');
  }

  /**
   * Purge cache by URL.
   */
  public function purgeUrl($url) {
    drupal_http_request($url, array('method' => 'PURGE'));
  }

  /**
   * Purge cache by key.
   */
  public function purgeKey($key) {
    $this->query('/service/' . $this->service_id . '/purge/' . $key, array(), 'POST');
  }

  /**
   * Performs http queries to Fastly API server.
   *
   * @param string $uri
   * @param array $data
   * @param string $method
   * @param arrat $headers
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

    return drupal_http_request($url, $options);
  }
}
