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

  public function signup($data) {
    $result = $this->query('signup/and_confirm', $data, 'POST');

    if ($result->status_message == 'OK') {
      return json_decode($result->data);
    } else {
      return FALSE;
    }
  }

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

  public function getServices() {
    $result = $this->query('service');

    return json_decode($result->data);
  }

  public function createService($data) {
    $service = json_decode($this->query('service', $data, 'POST')->data);
dsm($service);
    $this->query('service/' . $service->id . '/version/1/domain', array('name' => $data['domain']), 'POST');
    $this->query('service/' . $service->id . '/version/1/backend', $data, 'POST');

    return $service;
  }

  public function purgeAll() {
    $this->query('/service/' . $this->service_id . '/purge_all', array(), 'POST');
  }

  public function purgeUrl($url) {
    drupal_http_request($url, array('method' => 'PURGE'));
  }

  public function purgeKey($key) {
    $this->query('/service/' . $this->service_id . '/purge/' . $key, array(), 'POST');
  }

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
