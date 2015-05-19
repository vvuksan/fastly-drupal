<?php

/**
 * @file
 * Handles API calls to the Fastly service.
 */

namespace Drupal\Fastly;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Fastly API for Drupal.
 */
class Api {

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a \Drupal\fastly\Api object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.
   * @param string $host
   *   The host to use to talk to the Fastly API.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, ClientInterface $http_client, LoggerInterface $logger) {
    $config = $config_factory->get('fastly.settings');

    $this->apiKey = $config->get('api_key');
    $this->serviceId = $config->get('service_id');

    $this->host = $host;
    $this->httpClient = $http_client;
    $this->logger = $logger;
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
    $response = $this->query('service');
    return $response->json();
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
    $this->purgeQuery(drupal_get_path_alias($path));
  }

  /**
   * Performs an actual purge request for the given path.
   */
  protected function purgeQuery($path) {
    drupal_http_request(url($path, array('absolute' => TRUE)), array(
      'headers' => array(
        'Host' => $_SERVER['HTTP_HOST'],
      ),
      'method' => 'PURGE',
    ));
  }

  /**
   * Purge cache by key.
   *
   * @param string $key
   *   A Surrogate Key value; in the case of Drupal: a cache tag.
   */
  public function purgeKey($key) {
    try {
      $response = $this->query('service/' . $this->serviceId . '/purge/' . $key, [], 'POST');

      $result = $response->json();
      if ($result['status'] === 'ok') {
        $this->logger->info('Successfully purged the key %key. Purge ID: %id.', ['%key' => $key, '%id' => $result['id']]);
      }
      else {
        $this->logger->critical('Unable to purge the key %key was purged from Fastly. Response status: %status. Purge ID: %id.', ['%key' => $key, '%status' => $result['status'], '%id' => $result['id']]);
      }
    }
    catch (RequestException $e) {
//      $this->logger->critical($e->getMessage());
    }
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
   * @return \GuzzleHttp\Message\ResponseInterface
   *
   * @throws \GuzzleHttp\Exception\RequestException
   */
  protected function query($uri, $data = array(), $method = 'GET', $headers = array()) {
    $request = $this->httpClient->createRequest($method, $this->host . $uri, $data);
    $request->addHeaders($headers);
    if ($this->apiKey) {
      $request->addHeader('Fastly-Key', $this->apiKey);
    }

    return $this->httpClient->send($request);
  }
}
