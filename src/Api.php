<?php

namespace Drupal\fastly;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fastly\Form\FastlySettingsForm;
use Drupal\fastly\Services\Webhook;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Fastly API for Drupal.
 */
class Api {

  use StringTranslationTrait;

  /**
   * Fastly API Key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * Fastly Service ID.
   *
   * @var string
   */
  protected $serviceId;

  /**
   * Host of current request.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Connect timeout.
   *
   * @var string
   */
  protected $connectTimeout;

  /**
   * The purge method (instant / soft).
   *
   * @var string
   */
  private $purgeMethod;

  /**
   * Fastly state service.
   *
   * @var \Drupal\fastly\State
   */
  protected $state;

  /**
   * Fastly webhook service.
   *
   * @var \Drupal\fastly\Services\Webhook
   */
  protected $webhook;

  /**
   * CacheTagsHash service.
   *
   * @var \Drupal\fastly\CacheTagsHash
   */
  protected $cacheTagsHash;

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
   * @param \Drupal\fastly\State $state
   *   The Fastly state service.
   * @param string $connectTimeout
   *   The timeout for connections to the Fastly API.
   * @param \Drupal\fastly\Services\Webhook $webhook
   *   The Fastly webhook service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack object.
   * @param \Drupal\fastly\CacheTagsHash $cache_tags_hash
   *   CacheTagsHash service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, ClientInterface $http_client, LoggerInterface $logger, State $state, $connectTimeout, Webhook $webhook, RequestStack $requestStack, CacheTagsHash $cache_tags_hash) {

    $config = $config_factory->get('fastly.settings');
    $this->apiKey = getenv('FASTLY_API_TOKEN') ?? $config->get('api_key');
    $this->serviceId = getenv('FASTLY_API_SERVICE') ?? $config->get('service_id');
    $this->purgeMethod = $config->get('purge_method');
    $this->connectTimeout = $connectTimeout;
    $this->host = $host;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->state = $state;
    $this->webhook = $webhook;
    $this->baseUrl = $requestStack->getCurrentRequest()->getHost();
    $this->cacheTagsHash = $cache_tags_hash;
  }

  /**
   * Get API key.
   */
  public function getApiKey() {
    return $this->apiKey;
  }

  /**
   * Set API key.
   *
   * @param string $api_key
   *   Fastly API key.
   */
  public function setApiKey($api_key) {
    $this->apiKey = $api_key;
  }

  /**
   * Set Service Id.
   *
   * @param string $service_id
   *   Fastly Service Id.
   */
  public function setServiceId($service_id) {
    $this->serviceId = $service_id;
  }

  /**
   * Get a single token based on the access_token used in the request..
   */
  public function getToken() {
    $response = $this->query('/tokens/self');
    return $this->json($response);
  }

  /**
   * Gets a list of services for the current customer.
   */
  public function getServices() {
    $response = $this->query('/service');
    return $this->json($response);
  }

  /**
   * Gets Fastly user associated with an API key.
   */
  public function getCurrentUser() {
    $response = $this->query('/current_user');
    return $this->json($response);
  }

  /**
   * Used to validate an API Token's scope for purging capabilities.
   *
   * @return bool
   *   FALSE if any corrupt data is passed or token is inadequate for purging.
   */
  public function validatePurgeToken() {
    try {

      $token = $this->getToken();

      if (!empty($token->scopes)) {
        // GET /tokens/self will return scopes for the passed token, but that
        // alone is not enough to know if a token can perform purge actions.
        // Global scope tokens require the engineer or superuser role.
        $potentially_valid_purge_scopes = 'global';
        // Purge tokens require both purge_all and purge_select.
        $valid_purge_scopes = ['purge_all', 'purge_select'];

        if (array_intersect($valid_purge_scopes, $token->scopes) === $valid_purge_scopes) {
          return TRUE;
        }
        elseif (in_array($potentially_valid_purge_scopes, $token->scopes, TRUE)) {
          try {

            $current_user = $this->getCurrentUser();

            if (!empty($current_user->role)) {
              if ($current_user->role === 'engineer' || $current_user->role === 'superuser') {
                return TRUE;
              }
              elseif ($current_user->role === 'billing' || $current_user->role === 'user') {
                return FALSE;
              }
              else {
                return FALSE;
              }
            }
            else {
              return FALSE;
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
      else {
        return FALSE;
      }
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Used to validate if an Api token has access to a service.
   *
   * @return bool
   *   FALSE if API token does not have access to a provided Service Id.
   */
  public function validateTokenServiceAccess() {
    if (empty($this->serviceId)) {
      return FALSE;
    }

    $token = $this->getToken();

    if (isset($token->services) && empty($token->services)) {
      return TRUE;
    }
    elseif (in_array($this->serviceId, $token->services, TRUE)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Used to validate API token for purge related scope.
   *
   * @return bool
   *   TRUE if API token is capable of necessary purge actions, FALSE otherwise.
   */
  public function validatePurgeCredentials() {
    if (empty($this->apiKey) || empty($this->serviceId)) {
      return FALSE;
    }
    if ($this->validatePurgeToken() && $this->validateTokenServiceAccess()) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Purge whole service.
   *
   * @return bool
   *   FALSE if purge failed, TRUE is successful.
   *   */
  public function purgeAll() {
    // This will return only hash from FASTLY SITE ID and purge only site id hash.
    $hashes = $this->cacheTagsHash->cacheTagsToHashes([]);
    return $this->purgeKeys($hashes);
  }

  /**
   * Performs an actual purge request for the given URL.
   *
   * @param string $url
   *   The full, valid URL to purge.
   *
   * @return bool
   *   FALSE if purge failed or URL is invalid, TRUE is successful.
   */
  public function purgeUrl($url = '') {
    // Validate URL -- this could be improved.
    // $url needs to be URL encoded.
    // Need to make sure we can avoid double encoding.
    if ((strpos($url, 'http') === FALSE) && (strpos($url, 'https') === FALSE)) {
      return FALSE;
    }
    if (!UrlHelper::isValid($url, TRUE)) {
      return FALSE;
    }
    if (strpos($url, ' ') !== FALSE) {
      return FALSE;
    }

    if ($this->state->getPurgeCredentialsState()) {
      try {
        // Use POST to purge/* to handle requests with http scheme securely.
        // See: https://docs.fastly.com/guides/purging/authenticating-api-purge-requests#purging-urls-with-an-api-token
        $response = $this->query('purge/' . $url, [], 'POST');
        $result = $this->json($response);
        if ($result->status === 'ok') {
          $this->logger->info('Successfully purged URL %url. Purge Method: %purge_method.', [
            '%url' => $url,
            '%purge_method' => $this->purgeMethod,
          ]);
          return TRUE;
        }
        else {
          $this->logger->critical('Unable to purge URL %url from Fastly. Purge Method: %purge_method.', [
            '%url' => $url,
            '%purge_method' => $this->purgeMethod,
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
    return FALSE;
  }

  /**
   * Purge cache by key.
   *
   * @param array $keys
   *   A list of Surrogate Key values; in the case of Drupal: cache tags.
   *
   * @return bool
   *   FALSE if purge failed, TRUE is successful.
   */
  public function purgeKeys(array $keys = []) {
    if ($this->state->getPurgeCredentialsState()) {
      try {
        $response = $this->query('service/' . $this->serviceId . '/purge', [], 'POST', ["Surrogate-Key" => implode(" ", $keys)]);
        $result = $this->json($response);

        if (!empty($result)) {

          $message = $this->t('Successfully purged following key(s) *@keys* on @base_url. Purge Method: @purge_method', [
            '@keys' => implode(" ", $keys),
            '@base_url' => $this->baseUrl,
            '@purge_method' => $this->purgeMethod,
          ]);
          $this->webhook->sendWebHook($message, 'purge_keys');

          $this->logger->info('Successfully purged following key(s) %key. Purge Method: %purge_method.', [
            '%key' => implode(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
          return TRUE;
        }
        else {

          $message = $this->t('Unable to purge following key(s) * @keys. Purge Method: @purge_method', [
            '@keys' => implode(" ", $keys),
            '@purge_method' => $this->purgeMethod,
          ]);
          $this->webhook->sendWebHook($message, 'purge_keys');

          $this->logger->critical('Unable to purge following key(s) %key from Fastly. Purge Method: %purge_method.', [
            '%key' => implode(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
    return FALSE;
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
    $data['connect_timeout'] = $this->connectTimeout;
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
          $uri = ltrim($uri, "/");
          return $this->httpClient->request($method, $this->host . $uri, $data);

        case 'POST':
          return $this->httpClient->post($this->host . $uri, $data);

        case 'PURGE':
          return $this->httpClient->request($method, $uri, $data);

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
   * Performs http queries to Fastly API server (VCL related).
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
  protected function vQuery($uri, array $data = [], $method = 'GET', array $headers = []) {
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
        $data['headers']['http_errors'] = TRUE;
      }
      $uri = ltrim($uri, '/');
      $uri = $this->host . $uri;
      $uri = rtrim($uri, '/');

      switch (strtoupper($method)) {
        case 'GET':
        case 'POST':
        case 'PURGE':
        case 'PUT':
          $data["http_errors"] = FALSE;
          return $this->httpClient->request($method, $uri, $data);

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

  /**
   * Wraps query method from this class.
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
  public function vclQuery($uri, array $data = [], $method = 'GET', array $headers = []) {
    if (empty($data['headers'])) {
      $data['headers'] = $headers;
      $data['headers']['Accept'] = 'application/json';
      $data['headers']['Fastly-Key'] = $this->apiKey;
    }

    if (($method == "POST") || ($method == "PUT")) {
      $data['form_params'] = $data;
    }

    return $this->vQuery($uri, $data, $method, $headers);
  }

  /**
   * Function for testing Fastly API connection.
   *
   * @return array
   *   Returns keyed array with 'status' and 'message' of test connection.
   */
  public function testFastlyApiConnection() {
    if (empty($this->host) || empty($this->serviceId) || empty($this->apiKey)) {
      return [
        'status' => FALSE,
        'message' => $this->t('Please enter credentials first'),
      ];
    }

    $url = '/service/' . $this->serviceId;
    $headers = [
      'Fastly-Key' => $this->apiKey,
      'Accept' => 'application/json',
    ];

    try {
      $message = '';
      $response = $this->vclQuery($url, [], "GET", $headers);

      if ($response->getStatusCode() == "200") {
        $status = TRUE;
        $response_body = json_decode($response->getBody());

        if (!empty($response_body->name)) {
          $args = ['%service_name' => $response_body->name];
          $message = $this->t('Connection Successful on service %service_name', $args);
        }
      }
      else {
        $status = FALSE;
        $response_body = json_decode($response->getBody());

        if (!empty($response_body->name)) {
          $args = [
            '%name]' => $response_body->name,
            '@status' => $response->getStatusCode(),
          ];
          $message = $this->t('Connection not Successful on service %name - @status', $args);
          $this->logger->critical($message);
        }
        else {
          $args = [
            '@status' => $response->getStatusCode(),
          ];
          $message = $this->t('Connection not Successful on service - status : @status', $args);
          $this->logger->critical($message);
        }
      }

      return [
        'status' => $status,
        'message' => $message,
      ];

    }
    catch (Exception $e) {
      return ['status' => FALSE, 'message' => $e->getMessage()];
    }
  }

}
