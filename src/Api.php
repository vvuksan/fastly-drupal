<?php

namespace Drupal\Fastly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Form\FastlySettingsForm;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
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
   * The purge method (instant / soft).
   *
   * @var string
   */
  private $purgeMethod;

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
    $this->purgeMethod = $config->get('purge_method');

    $this->host = $host;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Set API key.
   *
   * @paran string $api_key
   *  API key.
   */
  public function setApiKey($api_key) {
    $this->apiKey = $api_key;
  }

  /**
   * Used to validate API key.
   *
   * @return bool
   *   FALSE if any corrupt data is passed or token scope is inadequate.
   */
  public function validateApiKey() {
    try {
      $response = $this->query('/tokens/self');
      if ($response->getStatusCode() != 200) {
        return FALSE;
      }
      $json = $this->json($response);
      if (!empty($json->scopes)) {
        // GET /tokens/self will return scopes for the passed token, but that
        // alone is not enough to know if a token can perform purge actions.
        // Global scope tokens require the engineer or superuser role.
        $potentially_valid_purge_scopes = 'global';
        // Purge tokens require both purge_all and purge_select.
        $valid_purge_scopes = ['purge_all', 'purge_select'];

        if (array_intersect($valid_purge_scopes, $json->scopes) === $valid_purge_scopes) {
          return TRUE;
        }
        elseif (in_array($potentially_valid_purge_scopes, $json->scopes, TRUE)) {
          try {
            $response = $this->query('/current_user');
            if ($response->getStatusCode() != 200) {
              return FALSE;
            }
            $json = $this->json($response);
            if (!empty($json->role)) {
              if ($json->role === 'engineer' || $json->role === 'superuser') {
                return TRUE;
              }
              elseif ($json->role === 'billing' || $json->role === 'user') {
                return FALSE;
              }
              else {
                return FALSE;
              }
            }
          }
          catch (\Exception $e) {
            return FALSE;
          }
        }
        else {
          return FALSE;
        }
      }
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets a list of services for the current customer.
   */
  public function getServices() {
    $response = $this->query('service');
    return $this->json($response);
  }

  /**
   * Purge whole service.
   */
  public function purgeAll() {
    if (!empty($this->serviceId)) {
      try {
        $response = $this->query('service/' . $this->serviceId . '/purge_all', [], 'POST');

        $result = $this->json($response);
        if ($result->status === 'ok') {
          $this->logger->info('Successfully purged all on Fastly.');
        }
        else {
          $this->logger->critical('Unable to purge all on Fastly. Response status: %status.', [
            '%status' => $result['status'],
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
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
    drupal_http_request(url($path, ['absolute' => TRUE]), [
      'headers' => [
        'Host' => $_SERVER['HTTP_HOST'],
      ],
      'method' => 'PURGE',
    ]);
  }

  /**
   * Purge cache by key.
   *
   * @param array $keys
   *   A list of Surrogate Key values; in the case of Drupal: cache tags.
   */
  public function purgeKeys($keys) {
    if (!empty($this->serviceId)) {
      try {
        $response = $this->query('service/' . $this->serviceId . '/purge', [], 'POST', [ "Surrogate-Key" => join(" ", $keys) ] );

        $result = $this->json($response);
        if ( count($result) > 0 ) {

          $this->logger->info('Successfully purged key(s) %key. Purge Method: %purge_method.', [
            '%key' => join(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
        }
        else {
          $this->logger->critical('Unable to purge key(s) %key was purged from Fastly. Purge Method: %purge_method.', [
            '%key' => join(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
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
   * @return \Psr\Http\Message\ResponseInterface
   *   Response.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   RequestException.
   */
  protected function query($uri, array $data = [], $method = 'GET', array $headers = []) {
    try {
      if (empty($data['headers'])) {
        $data['headers'] = $headers;
        $data['headers']['Accept'] = 'application/json';
        $data['headers']['Fastly-Key'] = $this->apiKey;

        // If the module is configured to use soft purging, we need to add
        // the appropriate header.
        if ($this->purgeMethod == FastlySettingsForm::FASTLY_SOFT_PURGE) {
          $data['headers']['Fastly-Soft-Purge'] = 1;
        }
      }
      switch (strtoupper($method)) {
        case 'GET':
          return $this->httpClient->request($method, $this->host . $uri, $data);

        case 'POST':
          return $this->httpClient->post($this->host . $uri, $data);

        default:
          throw new \Exception('Method :method is not valid for Fastly service.', [
            ':method' => $method,
          ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->critical($e->getMessage());
    }
    return new Response();
  }

  /**
   * Get JSON from response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   Response.
   *
   * @return \stdClass
   *   JSON object.
   */
  public function json(ResponseInterface $response) {
    return json_decode($response->getBody());
  }

}
