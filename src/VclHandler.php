<?php

namespace Drupal\fastly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fastly\Services\Webhook;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class to control the VCL handling.
 */
class VclHandler {

  use StringTranslationTrait;

  /**
   * Drupal Error Page Response Object Name.
   */
  const ERROR_PAGE_RESPONSE_OBJECT = 'drupalmodule_error_page_response_object';

  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * VCL data to be processed.
   *
   * @var array
   */
  protected $vclData;

  /**
   * Condition data to be processed.
   *
   * @var array
   */
  protected $conditionData;

  /**
   * Setting data to be processed.
   *
   * @var array
   */
  protected $settingData;

  /**
   * Fastly API endpoint.
   *
   * @var string
   */
  protected $hostname;

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
   * Fastly API URL version base.
   *
   * @var string
   */
  protected $versionBaseUrl;

  /**
   * Headers used for GET requests.
   *
   * @var array
   */
  protected $headersGet;

  /**
   * Headers used for POST, PUT requests.
   *
   * @var array
   */
  protected $headersPost;

  /**
   * Last active version data.
   *
   * @var array
   */
  protected $lastVersionData;

  /**
   * Next cloned version number.
   *
   * @var string
   */
  public $nextClonedVersionNum = NULL;

  /**
   * Last active version number.
   *
   * @var string
   */
  public $lastActiveVersionNum = NULL;

  /**
   * Last cloned version number.
   *
   * @var string
   */
  protected $lastClonedVersion;

  /**
   * Errors.
   *
   * @var array
   */
  protected $errors = [];

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Fastly webhook service.
   *
   * @var \Drupal\fastly\Services\Webhook
   */
  protected $webhook;

  /**
   * Host of current request.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * Sets data to be processed, sets Credentials Vcl_Handler constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.
   * @param string $host
   *   The host to use to talk to the Fastly API.
   * @param \Drupal\fastly\Api $api
   *   Fastly API for Drupal.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param \Drupal\fastly\Services\Webhook $webhook
   *   The Fastly webhook service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, Api $api, LoggerInterface $logger, Webhook $webhook, RequestStack $requestStack) {
    $vcl_dir = drupal_get_path('module', 'fastly') . '/vcl_snippets';
    $data = [
      'vcl' => [
        [
          'vcl_dir' => $vcl_dir,
          'type' => 'recv',
        ],
        [
          'vcl_dir' => $vcl_dir,
          'type' => 'deliver',
        ],
        [
          'vcl_dir' => $vcl_dir,
          'type' => 'error',
        ],
        [
          'vcl_dir' => $vcl_dir,
          'type' => 'fetch',
        ],
      ],
      'condition' => [
        [
          'name' => 'drupalmodule_request',
          'statement' => 'req.http.x-pass == "1"',
          'type' => 'REQUEST',
          'priority' => 90,
        ],
      ],
      'setting' => [
        [
          'name' => 'drupalmodule_setting',
          'action' => 'pass',
          'request_condition' => 'drupalmodule_request',
        ],
      ],
    ];

    $this->api = $api;
    $this->webhook = $webhook;
    $config = $config_factory->get('fastly.settings');
    $this->vclData = !empty($data['vcl']) ? $data['vcl'] : FALSE;
    $this->conditionData = !empty($data['condition']) ? $data['condition'] : FALSE;
    $this->settingData = !empty($data['setting']) ? $data['setting'] : FALSE;
    $this->hostname = $host;
    $this->serviceId = $config->get('service_id');
    $this->apiKey = $config->get('api_key');
    $this->logger = $logger;
    $this->baseUrl = $requestStack->getCurrentRequest()->getHost();

    $connection = $this->api->testFastlyApiConnection();

    if (!$connection['status']) {
      $this->addError($connection['message']);
      return;
    }

    // Set credentials based data (API url, headers, last version)
    $this->versionBaseUrl = '/service/' . $this->serviceId . '/version';
    $this->headersGet = [
      'Fastly-Key' => $this->apiKey,
      'Accept' => 'application/json',
    ];
    $this->headersPost = [
      'Fastly-Key' => $this->apiKey,
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded',
    ];

    $this->lastVersionData = $this->getLastVersion();

    if ($this->lastVersionData) {
      $this->lastActiveVersionNum = $this->lastVersionData->number;
    }

  }

  /**
   * Creates a new Response Object.
   *
   * @param string $version
   *   Version number.
   * @param array $responseToCreate
   *   Request data for response to create.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function createResponse($version, array $responseToCreate) {
    $responseObject = $this->getResponse($version, $responseToCreate['name']);

    $url = $this->versionBaseUrl . '/' . $version . '/response_object/';

    if ($responseObject->getStatusCode() != "404") {
      $headers = $this->headersPost;
      $type = "PUT";
      $url = $url . $responseToCreate['name'];
    }
    else {
      $headers = $this->headersPost;
      $type = "POST";
    }

    $result = $this->vclRequestWrapper($url, $headers, $responseToCreate, $type);
    return $result;
  }

  /**
   * Gets the specified Response Object.
   *
   * @param string $version
   *   Version number.
   * @param string $name
   *   Response name.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function getResponse($version, $name) {
    if (empty($this->lastVersionData)) {
      return FALSE;
    }
    $url = $this->versionBaseUrl . '/' . $version . '/response_object/' . $name;

    return $this->vclRequestWrapper($url);
  }

  /**
   * Prepares request for Single VCL.
   *
   * @param array $single_vcl_data
   *   Single VCL data.
   * @param string $prefix
   *   Prefix.
   *
   * @return array|bool
   *   Request data for single VCL, FALSE otherwise.
   */
  public function prepareSingleVcl(array $single_vcl_data, $prefix = "drupalmodule") {
    if (!empty($single_vcl_data['type'])) {
      $single_vcl_data['name'] = $prefix . '_' . $single_vcl_data['type'];
      $single_vcl_data['dynamic'] = 0;
      $single_vcl_data['priority'] = 50;
      if (file_exists($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl')) {
        $single_vcl_data['content'] = file_get_contents($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl');
        unset($single_vcl_data['vcl_dir']);
      }
      else {
        $message = $this->t('VCL file does not exist.');
        $this->addError($message);
        $this->logger->info($message);
        return FALSE;
      }
      if ($this->checkIfVclExists($single_vcl_data['name'])) {
        $requests[] = $this->prepareUpdateVcl($single_vcl_data);
      }
      else {
        $requests[] = $this->prepareInsertVcl($single_vcl_data);
      }
    }
    else {
      $message = $this->t('VCL type not set.');
      $this->addError($message);
      $this->logger->info($message);
      return FALSE;
    }
    return $requests;
  }

  /**
   * Upload maintenance page.
   *
   * @param string $html
   *   Content for maintenance page.
   *
   * @return bool
   *   TRUE if New Error/Maintenance page is updated and activated,
   *   FALSE if unsuccessful.
   */
  public function uploadMaintenancePage($html) {
    try {
      $clone = $this->cloneLastActiveVersion();
      if (FALSE === $clone) {
        $this->addError($this->t('Unable to clone last version'));
        return FALSE;
      }

      $condition = [
        'name' => 'drupalmodule_error_page_condition',
        'statement' => 'req.http.ResponseObject == "970"',
        'type' => 'REQUEST',
      ];

      $_condition = $this->getCondition($condition["name"]);

      if ($_condition->getStatusCode() == "404") {
        $this->insertCondition($condition);
      }

      $response = [
        'name' => self::ERROR_PAGE_RESPONSE_OBJECT,
        'request_condition' => $condition["name"],
        'content'   => $html,
        'status' => "503",
        'response' => "Service Temporarily Unavailable",
      ];

      $createResponse = $this->createResponse($this->lastClonedVersion, $response);

      if (!$createResponse) {
        $this->addError($this->t('Failed to create a RESPONSE object.'));
        return FALSE;
      }

      $validate = $this->validateVersion($this->lastClonedVersion);
      if (!$validate) {
        $this->addError($this->t('Failed to validate service version: @last_cloned_version', ['@last_cloned_version' => $this->lastClonedVersion]));
        return FALSE;
      }

      $vcl_dir = drupal_get_path('module', 'fastly') . '/vcl_snippets/errors';
      $singleVclData['vcl_dir'] = $vcl_dir;
      $singleVclData['type'] = 'deliver';
      $requests = [];
      if (!empty($singleVclData)) {
        $requests = array_merge($requests, $this->prepareSingleVcl($singleVclData, "drupalmodule_error_page"));
      }

      $responses = [];
      foreach ($requests as $value) {
        if (!isset($value['type'])) {
          continue;
        }
        $url = $value['url'];
        $data = $value['data'];
        $type = $value['type'];
        $headers = [];

        $response = $this->vclRequestWrapper($url, $headers, $data, $type);

        $responses[] = $response;
      }
      unset($responses);

      $request = $this->prepareActivateVersion();

      $response = $this->vclRequestWrapper($request['url'], $request['headers'], [], $request['type']);
      if ($response->getStatusCode() != "200") {
        return FALSE;
      }

      $this->webhook->sendWebHook($this->t('*New Error/Maintenance page has updated and activated under config version @last_cloned_version', ['@last_cloned_version' => $this->lastClonedVersion]), "maintenance_page");

      return TRUE;
    }
    catch (\Exception $e) {
      $this->addError($this->t('@message', ['@message' => $e->getMessage()]));
      return FALSE;
    }
  }

  /**
   * Main execute function.
   *
   * Takes values inserted into constructor,
   * builds requests and sends them via Fastly API.
   *
   * @param bool $activate
   *   (optional) TRUE to update and active, FALSE to update only.
   *
   * @return mixed
   *   TRUE if executes successfully, FALSE if unsuccessful.
   */
  public function execute($activate = FALSE) {
    // Check if there are connection errors from construct.
    $errors = $this->getErrors();
    if (!empty($errors)) {
      foreach ($errors as $error) {
        drupal_set_message($error, 'error');
      }
      return FALSE;
    }

    // Check if last version is fetched.
    if ($this->lastVersionData === FALSE) {
      $this->addError($this->t('Last version does not exist'));
      return FALSE;
    }

    // Check if any of the data is set.
    if (empty($this->vclData) && empty($this->conditionData) && empty($this->settingData)) {
      $this->addError($this->t('No update data set, please specify, vcl, condition or setting data'));
      return FALSE;
    }

    try {
      if (FALSE === $this->cloneLastActiveVersion()) {
        $this->addError($this->t('Unable to clone last version'));
        return FALSE;
      }

      $requests = [];

      if (!empty($this->vclData)) {
        $requests = array_merge($requests, $this->prepareVcl());
      }

      if (!empty($this->conditionData)) {
        $conditions = $this->prepareCondition();
        if (FALSE === $conditions) {
          $this->addError($this->t('Unable to insert new condition'));
          return FALSE;
        }
        $requests = array_merge($requests, $conditions);
      }

      if (!empty($this->settingData)) {
        $requests = array_merge($requests, $this->prepareSetting());
      }

      if (!$this->validateVersion()) {
        $this->addError($this->t('Version not validated'));
        return FALSE;
      }

      // Set Request Headers.
      foreach ($requests as $key => $request) {
        if (in_array($request['type'], ["POST", "PUT"])) {
          $requests[$key]['headers'] = $this->headersPost;
        }
        else {
          $requests[$key]['headers'] = $this->headersGet;
        }
      }

      // Send Requests.
      $responses = [];
      foreach ($requests as $key => $value) {
        if (!isset($value['type'])) {
          continue;
        }
        $url = $value['url'];
        $data = $value['data'];
        $type = $value['type'];
        $headers = $value['headers'];

        $response = $this->vclRequestWrapper($url, $headers, $data, $type);

        $responses[] = $response;
      }

      $pass = TRUE;

      foreach ($responses as $response) {
        if ($response->getStatusCode() != "200") {
          $pass = FALSE;
          $this->addError($this->t('Some of the API requests failed, enable debugging and check logs for more information.'));
          $this->logger->critical('VCL update failed : @body', ['@body' => json_decode($response->getBody())]);
        }
      }

      // Activate version if vcl is successfully uploaded.
      if ($pass && $activate) {
        $request = $this->prepareActivateVersion();

        $response = $this->vclRequestWrapper($request['url'], $request['headers'], [], $request['type']);
        if ($response->getStatusCode() != "200") {
          $this->addError($this->t('Some of the API requests failed, enable debugging and check logs for more information.'));
          $this->logger->critical('Activation of new version failed : @body', ['@body' => $response->getBody()]);
        }
        else {
          $this->logger->info('VCL updated, version activated : ', ['@last_cloned_version' => $this->lastClonedVersion]);
        }
      }
      elseif ($pass && !$activate) {
        $message = $this->t('VCL updated, but not activated.');
        $this->logger->info($message);
      }
      $this->webhook->sendWebHook($this->t('VCL updated, but not activated on %base_url', ['%base_url' => $this->baseUrl]), "vcl_update");
    }
    catch (Exception $e) {
      $this->addError($this->t('Some of the API requests failed, enable debugging and check logs for more information.'));
      $this->logger->critical('VCL update failed : @message', ['@message' => $e->getMessage()]);
      foreach ($this->getErrors() as $error) {
        // $error should have been passed through t() before $this->setError.
        drupal_set_message($error, 'error');
      }
      return FALSE;
    }
    return $message;
  }

  /**
   * Prepares VCL request.
   *
   * @return array|bool
   *   Request date for VCL request, FALSE if not valid.
   */
  public function prepareVcl() {
    // Prepare VCL data content.
    $requests = [];
    foreach ($this->vclData as $single_vcl_data) {
      if (!empty($single_vcl_data['type'])) {
        $single_vcl_data['name'] = 'drupalmodule_' . $single_vcl_data['type'];
        $single_vcl_data['dynamic'] = 0;
        $single_vcl_data['priority'] = 50;
        if (file_exists($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl')) {
          $single_vcl_data['content'] = file_get_contents($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl');
          unset($single_vcl_data['vcl_dir']);
        }
        else {
          $message = $this->t('VCL file does not exist.');
          $this->addError($message);
          $this->logger->info($message);

          return FALSE;
        }

        if ($this->checkIfVclExists($single_vcl_data['name'])) {
          $requests[] = $this->prepareUpdateVcl($single_vcl_data);

        }
        else {
          $requests[] = $this->prepareInsertVcl($single_vcl_data);
        }
      }
      else {
        $message = $this->t('VCL type not set.');
        $this->addError($message);
        $this->logger->info($message);
        return FALSE;
      }
    }

    return $requests;
  }

  /**
   * Checks if VCL exists.
   *
   * @name string
   *
   * @return bool
   *   TRUE if VCL exists, FALSE otherwise.
   */
  public function checkIfVclExists($name) {
    if (empty($this->lastVersionData)) {
      return FALSE;
    }

    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $name;
    $response = $this->vclGetWrapper($url);
    $responseBody = (string) $response->getBody();

    if (empty($responseBody)) {
      return FALSE;
    }
    $_responseBody = json_decode($response->getBody());
    if (!empty($_responseBody->content)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Fetch Id of a snippet.
   *
   * @data array
   *
   * @return int
   *   Snippet Id.
   */
  public function getSnippetId($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $data['name'];

    $response = $this->vclGetWrapper($url);
    $responseData = json_decode($response->getBody());
    return $responseData->id;
  }

  /**
   * Prepares request for updating existing VCL.
   *
   * @data array
   *
   * @return array
   *   Request data for updating existing VCL.
   */
  public function prepareUpdateVcl($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $data["name"];

    $data['form_params'] = [
      'content' => $data['content'],
      'type' => $data['type'],
      'name' => $data['name'],
      'dynamic' => $data['dynamic'],
      'priority' => $data['priority'],
    ];

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    ];

    return $request;
  }

  /**
   * Prepare request for inserting new VCL.
   *
   * @data array
   *
   * @return array
   *   Request data for inserting new VCL.
   */
  public function prepareInsertVcl($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST',
    ];

    return $request;
  }

  /**
   * Fetch last service version.
   *
   * @return bool|int
   *   FALSE otherwise.
   */
  public function getLastVersion() {
    $url = $this->versionBaseUrl;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $response_data = json_decode($response->getBody());

    $this->nextClonedVersionNum = count($response_data) + 1;

    foreach ($response_data as $version_data) {
      if ($version_data->active) {
        return $version_data;
      }
    }

    return FALSE;
  }

  /**
   * Creates and returns cloned version number.
   *
   * @return mixed
   *   Cloned version number if successful, FALSE otherwise.
   */
  public function cloneLastActiveVersion() {
    if (empty($this->lastVersionData)) {
      return FALSE;
    }

    $version_number = $this->lastVersionData->number;
    $url = $this->versionBaseUrl . '/' . $version_number . '/clone';
    $response = $this->vclPutWrapper($url, $this->headersPost);

    $response_data = json_decode($response->getBody());

    $cloned_version_number = isset($response_data->number) ? $response_data->number : FALSE;
    $this->lastClonedVersion = $cloned_version_number;

    return $cloned_version_number;
  }

  /**
   * Prepares condition for insertion.
   *
   * @return array|bool
   *   Request data to insert condition or FALSE if condition data invalid.
   */
  public function prepareCondition() {
    // Prepare condition content.
    $requests = [];
    foreach ($this->conditionData as $single_condition_data) {
      if (empty($single_condition_data['name']) ||
        empty($single_condition_data['statement']) ||
        empty($single_condition_data['type']) ||
        empty($single_condition_data['priority'])
      ) {
        $message = $this->t('Condition data not properly set.');
        $this->addError($message);
        $this->logger->critical($message);
        return FALSE;
      }
      else {
        if ($this->checkCondition($single_condition_data['name'])) {
          $requests[] = $this->prepareUpdateCondition($single_condition_data);
        }
        else {
          // Do insert here because condition is needed before setting
          // (requests are not sent in order).
          return $this->insertCondition($single_condition_data);
        }
      }
    }

    return $requests;
  }

  /**
   * Checks if condition exists.
   *
   * @param string $name
   *   Condition name.
   *
   * @return bool
   *   FALSE if response not returned or without condition, TRUE otherwise.
   */
  public function checkCondition($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $name;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseBody = (string) $response->getBody();
    $_responseBody = json_decode($responseBody);
    if (empty($_responseBody)) {
      return FALSE;
    }
    if ($_responseBody->version) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Fetches condition by condition name.
   *
   * @param string $name
   *   Condition name.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function getCondition($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $name;
    return $this->vclGetWrapper($url, $this->headersGet);
  }

  /**
   * Prepare condition for update.
   *
   * @data array
   *
   * @return array
   *   Request data to update condition.
   */
  public function prepareUpdateCondition($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $data['name'];
    $request = [
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    ];

    return $request;
  }

  /**
   * Prepare condition for insert.
   *
   * @data
   *
   * @return array
   *   Response data or empty array.
   */
  public function insertCondition($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST',
    ];

    $response = $this->vclRequestWrapper($request['url'], $this->headersPost, $request['data'], $request['type']);
    $responseData = json_decode($response->getBody());

    if ($responseData) {
      return $responseData;
    }
    else {
      return [];
    }
  }

  /**
   * Prepares setting for insertion.
   *
   * @return array|bool
   *   Request data to insert setting or FALSE if settings data invalid.
   */
  public function prepareSetting() {
    // Prepare setting content.
    $requests = [];
    foreach ($this->settingData as $single_setting_data) {
      if (empty($single_setting_data['name']) ||
        empty($single_setting_data['action']) ||
        empty($single_setting_data['request_condition'])
      ) {
        $message = $this->t('Setting data not properly set.');
        $this->addError($message);
        $this->logger->critical($message);
        return FALSE;
      }
      else {
        if ($this->getSetting($single_setting_data['name'])) {
          $requests[] = $this->prepareUpdateSetting($single_setting_data);
        }
        else {
          $requests[] = $this->prepareInsertSetting($single_setting_data);
        }
      }
    }

    return $requests;
  }

  /**
   * Fetches setting by condition name.
   *
   * @name string
   *
   * @return bool
   *   FALSE if response not returned or without version, TRUE otherwise.
   */
  public function getSetting($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings/' . $name;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseBody = (string) $response->getBody();
    $_responseBody = json_decode($responseBody);

    if (empty($_responseBody)) {
      return FALSE;
    }

    if (!$_responseBody->version) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Prepares update setting data.
   *
   * @data array
   *
   * @return array
   *   Request data to update settings.
   */
  public function prepareUpdateSetting($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings/' . $data['name'];

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'PUT',
    ];

    return $request;
  }

  /**
   * Prepares Insert setting data.
   *
   * @data array
   *
   * @return array
   *   Request data to insert settings.
   */
  public function prepareInsertSetting($data) {

    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST',
    ];

    return $request;
  }

  /**
   * Validates last cloned version.
   *
   * @return bool
   *   TRUE if no validation errors, FALSE otherwise.
   */
  public function validateVersion() {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/validate';
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseData = json_decode($response->getBody());

    if (!empty($responseData->errors)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Activates last cloned version.
   *
   * @return array
   *   Request data to activates last cloned version.
   */
  public function prepareActivateVersion() {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/activate';

    $request = [
      'url' => $url,
      'type' => 'PUT',
      'headers' => $this->headersGet,
    ];

    return $request;
  }

  /**
   * Adds new error to error array.
   *
   * @param string $message
   *   Error message.
   */
  public function addError($message) {
    $this->errors[] = $message;
  }

  /**
   * Fetches logged errors.
   *
   * @return array
   *   Logged errors.
   */
  public function getErrors() {
    return $this->errors;
  }

  /**
   * Wraps api call to make query via Guzzle.
   *
   * @param string $url
   *   The uri to use for the request, appended to the host.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   * @param array $data
   *   (optional) Data to send with the request.
   * @param string $type
   *   (optional) The method to use for the request, defaults to GET.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function vclRequestWrapper($url, array $headers = [], array $data = [], $type = "GET") {
    return $this->api->vclQuery($url, $data, $type, $headers);
  }

  /**
   * Makes get request via vclRequestWrapper.
   *
   * @param string $url
   *   The uri to use for the request, appended to the host.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   * @param array $data
   *   (optional) Data to send with the request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function vclGetWrapper($url, array $headers = [], array $data = []) {
    return $this->vclRequestWrapper($url, $headers, $data, "GET");
  }

  /**
   * Makes put request via vclRequestWrapper.
   *
   * @param string $url
   *   The uri to use for the request, appended to the host.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   * @param array $data
   *   (optional) Data to send with the request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   vclQuery.
   */
  public function vclPutWrapper($url, array $headers = [], array $data = []) {
    return $this->vclRequestWrapper($url, $headers, $data, "PUT");
  }

}
