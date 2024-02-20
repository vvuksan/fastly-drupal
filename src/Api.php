<?php

namespace Drupal\fastly;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fastly\Form\PurgeOptionsForm;
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
   * Fastly max header key size.
   */
  const FASTLY_MAX_HEADER_KEY_SIZE = 256;

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
   * Host.
   *
   * @var string
   */
  protected $host;

  /**
   * Guzzle Http Client.
   *
   * @var |GuzzleHttp\ClientInterface
   */
  protected $httpClient;

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
   * Messenger.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * Purge logging.
   *
   * @var bool
   */
  protected $purgeLogging;

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
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   Messenger service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, ClientInterface $http_client, LoggerInterface $logger, State $state, $connectTimeout, Webhook $webhook, RequestStack $requestStack, CacheTagsHash $cache_tags_hash, Messenger $messenger) {

    $config = $config_factory->get('fastly.settings');
    $this->apiKey = getenv('FASTLY_API_TOKEN') ?: $config->get('api_key');
    $this->serviceId = getenv('FASTLY_API_SERVICE') ?: $config->get('service_id');
    $this->purgeMethod = $config->get('purge_method') ?: PurgeOptionsForm::FASTLY_INSTANT_PURGE;
    $this->purgeLogging = $config->get('purge_logging');
    $this->connectTimeout = $connectTimeout;
    $this->host = $host;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->state = $state;
    $this->webhook = $webhook;
    if ($requestStack->getCurrentRequest()) {
      $this->baseUrl = $requestStack->getCurrentRequest()->getHost();
    }
    else {
      global $base_url;
      $this->baseUrl = $base_url;
    }
    $this->cacheTagsHash = $cache_tags_hash;
    $this->messenger = $messenger;
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
   * Purge whole site/service.
   *
   * @param bool $siteOnly
   *   Set to FALSE if you want to purge entire service otherwise it will purge
   *   entire site only.
   *
   * @return bool
   *   FALSE if purge failed, TRUE is successful.
   */
  public function purgeAll($siteOnly = TRUE) {
    if ($siteOnly) {
      // This will return only hash from FASTLY SITE ID and purge only site id hash.
      $siteId = $this->cacheTagsHash->getSiteId();
      $siteIdHash = $this->cacheTagsHash->hashInput($siteId);
      return $this->purgeKeys([$siteIdHash]);
    }
    else {
      if ($this->state->getPurgeCredentialsState()) {
        try {
          $response = $this->query('service/' . $this->serviceId . '/purge_all', [], 'POST');
          $result = $this->json($response);
          if ($result->status === 'ok') {
            if ($this->purgeLogging) {
              $this->logger->info('Successfully purged all on Fastly.');
            }
            $this->webhook->sendWebHook($this->t("Successfully purged / invalidated all content on @base_url.", ['@base_url' => $this->baseUrl]), "purge_all");
            return TRUE;
          }
          else {
            $this->logger->critical('Unable to purge all on Fastly. Response status: %status.', ['%status' => $result['status']]);
          }
        } catch (RequestException $e) {
          $this->logger->critical($e->getMessage());
        }
      }
      return FALSE;
    }
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
          if ($this->purgeLogging) {
            $this->logger->info('Successfully purged URL %url. Purge Method: %purge_method.', [
              '%url' => $url,
              '%purge_method' => $this->purgeMethod,
            ]);
          }
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
        $num = count($keys);
        if ($num >= self::FASTLY_MAX_HEADER_KEY_SIZE ){
          $parts = $num / self::FASTLY_MAX_HEADER_KEY_SIZE;
          $additional = ($parts > (int)$parts) ? 1 : 0;
          $parts = (int)$parts + (int)$additional;
          $chunks = ceil($num/$parts);
          $collection = array_chunk($keys, $chunks);
        }else{
          $collection = [$keys];
        }
        foreach($collection as $keysChunk){
          $response = $this->query('service/' . $this->serviceId . '/purge', [], 'POST', ["Surrogate-Key" => implode(" ", $keysChunk)]);
          $result = $this->json($response);
          if (!empty($result)) {
            $message = $this->t('Successfully purged following key(s) *@keys* on @base_url. Purge Method: @purge_method', [
              '@keys' => implode(" ", $keysChunk),
              '@base_url' => $this->baseUrl,
              '@purge_method' => $this->purgeMethod,
            ]);
            $this->webhook->sendWebHook($message, 'purge_keys');
            if ($this->purgeLogging) {
              $this->logger->info('Successfully purged following key(s) %key. Purge Method: %purge_method.', [
                '%key' => implode(" ", $keysChunk),
                '%purge_method' => $this->purgeMethod,
              ]);
            }
            return TRUE;
          }
          else {
            $message = $this->t('Unable to purge following key(s) * @keys. Purge Method: @purge_method', [
              '@keys' => implode(" ", $keysChunk),
              '@purge_method' => $this->purgeMethod,
            ]);
            $this->webhook->sendWebHook($message, 'purge_keys');
            $this->logger->critical('Unable to purge following key(s) %key from Fastly. Purge Method: %purge_method.', [
              '%key' => implode(" ", $keysChunk),
              '%purge_method' => $this->purgeMethod,
            ]);
          }
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
        if ($this->purgeMethod == PurgeOptionsForm::FASTLY_SOFT_PURGE) {
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
      $this->messenger->addError($e->getMessage());
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
        if ($this->purgeMethod == PurgeOptionsForm::FASTLY_SOFT_PURGE) {
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
        case 'PATCH':
        case 'PUT':
        case 'DELETE':
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

  /**
   * Get Service Details.
   *
   * @param $serviceId
   * @return \stdClass
   */
  public function getDetails($serviceId){
    $response = $this->query('/service/'. $serviceId .'/details');
    return $this->json($response);
  }

  /**
   * Check if IO is enabled on service.
   *
   * @param $serviceId
   * @return bool
   */
  public function ioEnabled($serviceId = FALSE){
    if (!$serviceId) {
      $serviceId = $this->serviceId;
    }
    $response = $this->getDetails($serviceId);
    if ($response instanceof \stdClass) {
      if (property_exists($response, 'active_version') && property_exists($response->active_version, 'io_settings')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
