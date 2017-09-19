<?php

namespace Drupal\fastly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Services\Webhook;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class to control the VCL handling.
 */
class VclHandler
{
  /**
   * Drupal Error Page Response Object Name
   */
  const ERROR_PAGE_RESPONSE_OBJECT = 'drupalmodule_error_page_response_object';

  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * VCL data to be processed
   */
  protected $vclData;

  /**
   * Condition data to be processed
   */
  protected $conditionData;

  /**
   * Setting data to be processed
   */
  protected $settingData;

  /**
   * Fastly API endpoint
   */
  protected $hostname;

  /**
   * Fastly API Key
   */
  protected $apiKey;

  /**
   * Fastly Service ID
   */
  protected $serviceId;

  /**
   * Fastly API URL version base
   */
  protected $versionBaseUrl;

  /**
   * Headers used for GET requests
   */
  protected $headersGet;

  /**
   * Headers used for POST, PUT requests
   */
  protected $headersPost;

  /**
   * Last active version data
   */
  protected $lastVersionData;

  /**
   * Next cloned version number
   */
  public $nextClonedVersionNum = null;

  /**
   * Last active version number
   */
  public $lastActiveVersionNum = null;

  /**
   * Last cloned version number
   */
  protected $lastClonedVersion;

  /**
   * Errors
   */
  protected $errors = [];

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var Webhook
   */
  protected $webhook;

  /**
   * @var string
   */
  protected $base_url;

  /**
   * Sets data to be processed, sets Credentials
   * Vcl_Handler constructor.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param $host
   * @param Api $api
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, Api $api, LoggerInterface $logger, Webhook $webhook, RequestStack $requestStack) {
    $vcl_dir = drupal_get_path('module', 'fastly'). '/vcl_snippets';
    $data = [
      'vcl' => [
        [
          'vcl_dir' => $vcl_dir,
          'type' => 'recv'
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
        ]
      ],
      'condition' => [
        [
          'name' => 'drupalmodule_request',
          'statement' => 'req.http.x-pass == "1"',
          'type' => 'REQUEST',
          'priority' => 90
        ]
      ],
      'setting' => [
        [
          'name' => 'drupalmodule_setting',
          'action' => 'pass',
          'request_condition' => 'drupalmodule_request'
        ]
      ]
    ];

    $this->api = $api;
    $this->webhook = $webhook;
    $config = $config_factory->get('fastly.settings');
    $this->vclData = !empty($data['vcl']) ? $data['vcl'] : false;
    $this->conditionData = !empty($data['condition']) ? $data['condition'] : false;
    $this->settingData = !empty($data['setting']) ? $data['setting'] : false;
    $this->hostname = $host;
    $this->serviceId = $config->get('service_id');
    $this->apiKey = $config->get('api_key');
    $this->logger = $logger;
    $this->base_url = $requestStack->getCurrentRequest()->getHost();

    $connection = $this->api->testFastlyApiConnection();

    if (!$connection['status']) {
      $this->addError($connection['message']);
      return;
    }

    // Set credentials based data (API url, headers, last version)
    $this->versionBaseUrl = '/service/' . $this->serviceId . '/version';
    $this->headersGet = [
      'Fastly-Key' => $this->apiKey,
      'Accept' => 'application/json'
    ];
    $this->headersPost = [
      'Fastly-Key' => $this->apiKey,
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded'
    ];

    $this->lastVersionData = $this->getLastVersion();

    if ($this->lastVersionData) {
      $this->lastActiveVersionNum = $this->lastVersionData->number;
    }

    return;
  }

  /**
   * Creates a new Response Object.
   *
   * @param $version
   * @param array $response
   * @return mixed
   */
  public function createResponse($version, array $response)
  {
    $_response = $this->getResponse($version, $response['name']);

    $url = $this->versionBaseUrl . '/' . $version . '/response_object/';

    if($_response->getStatusCode() != "404")
    {
      $verb = $this->headersPost;
      $type = "PUT";
      $url = $url . $response['name'];
    } else {
      $verb = $this->headersPost;
      $type = "POST";
    }

    $result = $this->vclRequestWrapper($url, $verb, $response, $type);

    return $result;
  }

  /**
   * Gets the specified Response Object.
   *
   * @param string $version
   * @param string $name
   * @return bool|mixed $result
   */
  public function getResponse($version, $name)
  {
    if (empty($this->lastVersionData)) {
      return false;
    }
    $url = $this->versionBaseUrl . '/' . $version . '/response_object/' . $name;

    return $this->vclRequestWrapper($url);
  }

  public function uploadMaintenancePage($html) {
    try {
      $clone = $this->cloneLastActiveVersion();
      if (false === $clone) {
        $this->addError('Unable to clone last version');
        return false;
      }

      $condition = [
        'name' => 'drupalmodule_error_page_condition',
        'statement' => 'req.http.ResponseObject == "970"',
        'type' => 'REQUEST',
      ];

      $_condition = $this->getCondition1($condition["name"]);

      if($_condition->getStatusCode() == "404") {
        $this->insertCondition($condition);
      }

      $response = array(
        'name' => self::ERROR_PAGE_RESPONSE_OBJECT,
        'request_condition' => $condition["name"],
        'content'   =>  $html,
        'status' => "503",
        'response' => "Service Temporarily Unavailable"
      );

      $createResponse = $this->createResponse($this->lastClonedVersion, $response);

      if(!$createResponse) {
        $this->addError('Failed to create a RESPONSE object.');
        return false;
      }

      $validate = $this->validateVersion($this->lastClonedVersion);
      if(!$validate) {
        $this->addError('Failed to validate service version: '.$this->lastClonedVersion);
        return false;
      }

      $request = $this->prepareActivateVersion();

      $response = $this->vclRequestWrapper($request['url'], $request['headers'], [], $request['type']);

      /*if ($this->config->areWebHooksEnabled() && $this->config->canPublishConfigChanges()) {
        $this->api->sendWebHook('*New Error/Maintenance page has updated and activated under config version ' . $clone->number . '*');
      }*/
      return true;
      //set message version activated
    } catch (\Exception $e) {
      $this->addError($e->getMessage());
      return false;
    }
  }

  /**
   * Main execute function, takes values inserted into constructor, builds requests
   * and sends them via Fastly API
   *
   * @param $activate bool
   * @return bool
   */
  public function execute($activate = false) {
    // Check if there are connection errors from construct
    $errors = $this->getErrors();
    if (!empty($errors)) {
      foreach($errors as $error) {
        drupal_set_message(t($error), 'error');
      }
      return false;
    }

    // Check if last version is fetched
    if ($this->lastVersionData === false) {
      $this->addError('Last version does not exist');
      return false;
    }

    // Check if any of the data is set
    if (empty($this->vclData) && empty($this->conditionData) && empty($this->settingData)) {
      $this->addError('No update data set, please specify, vcl, condition or setting data');
      return false;
    }

    try {
      if (false === $this->cloneLastActiveVersion()) {
        $this->addError('Unable to clone last version');
        return false;
      }

      $requests = [];

      if (!empty($this->vclData)) {
        $requests = array_merge($requests, $this->prepareVcl());
      }

      if (!empty($this->conditionData)) {
        $conditions = $this->prepareCondition();
        if (false === $conditions) {
          $this->addError('Unable to insert new condition');
          return false;
        }
        $requests = array_merge($requests, $conditions);
      }

      if (!empty($this->settingData)) {
        $requests = array_merge($requests, $this->prepareSetting());
      }

      if (!$this->validateVersion()) {
        $this->addError('Version not validated');
        return false;
      }

      // Set Request Headers
      foreach ($requests as $key => $request) {
        if (in_array($request['type'], ["POST", "PUT"])) {
          $requests[$key]['headers'] = $this->headersPost;
        } else {
          $requests[$key]['headers'] = $this->headersGet;
        }
      }

      // Send Requests
      $responses = [];
      foreach($requests as $key=>$value) {
        if(!isset($value['type'])) {
            continue;
        }
        $url = $value['url'];
        $data = $value['data'];
        $type = $value['type'];
        $headers = $value['headers'];

        $response = $this->vclRequestWrapper($url, $headers, $data, $type);

        $responses [] = $response;
      }

      $pass = true;

      foreach ($responses as $response) {
        if ($response->getStatusCode() != "200") {
          $pass = false;
          $this->addError('Some of the API requests failed, enable debugging and check logs for more information.');
          $message = 'VCL update failed : ' . json_decode($response->getBody());
          $this->logger->critical($message);
        }
      }

      // Activate version if vcl is successfully uploaded
      if ($pass && $activate) {
        $request = $this->prepareActivateVersion();

        $response = $this->vclRequestWrapper($request['url'], $request['headers'], [], $request['type']);
        if ($response->getStatusCode() != "200") {
          $pass = false;
          $this->addError('Some of the API requests failed, enable debugging and check logs for more information.');
          $message = 'Activation of new version failed : ' . $response->getBody();
          $this->logger->critical($message);
        } else {
          $message = 'VCL updated, version activated : ' . $this->lastClonedVersion;
          $this->logger->info($message);

        }
      } elseif ($pass && !$activate) {
        $message = 'VCL updated, but not activated.';
        $this->logger->info($message);
      }
      $this->webhook->sendWebHook($message . " on ".$this->base_url, "vcl_update");

    } catch (Exception $e) {
      $this->addError('Some of the API requests failed, enable debugging and check logs for more information.');
      $message = 'VCL update failed : ' . $e->getMessage();
      $this->logger->critical($message);
      foreach($this->getErrors() as $error) {
        drupal_set_message(t($error), 'error');
      }
      return false;
    }
    return $message;
  }

  /**
   * Prepares VCL request
   * @return array|bool
   */
  public function prepareVcl() {
    // Prepare VCL data content
    $requests = [];
    foreach ($this->vclData as $key => $single_vcl_data) {
      if (!empty($single_vcl_data['type'])) {
        $single_vcl_data['name'] = 'drupalmodule_' . $single_vcl_data['type'];
        $single_vcl_data['dynamic'] = 0;
        $single_vcl_data['priority'] = 50;
        if (file_exists($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl')) {
          $single_vcl_data['content'] = file_get_contents($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl');
          unset($single_vcl_data['vcl_dir']);
        } else {
          $message = 'VCL file does not exist.';
          $this->addError($message);
          $this->logger->info($message);

          return false;
        }

        if ($this->checkIfVclExists($single_vcl_data['name'])) {
          $requests[] = $this->prepareUpdateVcl($single_vcl_data);

        } else {
          $requests[] = $this->prepareInsertVcl($single_vcl_data);
        }
      } else {
        $message = 'VCL type not set.';
        $this->addError($message);
        $this->logger->info($message);
        return false;
      }
    }

    return $requests;
  }

  /**
   * Checks if VCL exists
   *
   * @name string
   * @return bool
   */
  public function checkIfVclExists($name) {
    if (empty($this->lastVersionData)) {
      return false;
    }

    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $name;
    $response = $this->vclGetWrapper($url);
    $responseBody = (string) $response->getBody();

    $i = 0;
    if(empty($responseBody)) {
      return false;
    }
    $_responseBody = json_decode($response->getBody());
    if(!empty($_responseBody->content)) {
        return true;
    }

    return false;
  }

  public function getSnippetId($data) {
      $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $data['name'];

      $response = $this->vclGetWrapper($url);
      $responseData = json_decode($response->getBody());
      return $responseData->id;
  }

  /**
   * Prepares request for updating existing VCL
   *
   * @data array
   * @return array
   */
  public function prepareUpdateVcl($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet/' . $data["name"];

    $data['form_params'] = [
      'content' => $data['content'],
      'type' => $data['type'],
      'name' => $data['name'],
      'dynamic' => $data['dynamic'],
      'priority' => $data['priority']
    ];

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => "PUT"
    ];

    return $request;
  }

  /**
   * Prepare request for inserting new VCL
   *
   * @data array
   * @return array
   */
  public function prepareInsertVcl($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/snippet';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    ];

    return $request;
  }

  /**
   * Fetch last service version
   *
   * @return bool|int
   */
  public function getLastVersion() {
    $url = $this->versionBaseUrl;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $response_data = json_decode($response->getBody());

    $this->nextClonedVersionNum = count($response_data) + 1;

    foreach ($response_data as $key => $version_data) {
      if ($version_data->active) {
        return $version_data;
      }
    }

    return false;
  }

  /**
   * Creates and returns cloned version number
   *
   * @return bool
   */
  public function cloneLastActiveVersion() {
    if (empty($this->lastVersionData)) {
      return false;
    }

    $version_number = $this->lastVersionData->number;
    $url = $this->versionBaseUrl . '/' . $version_number . '/clone';
    $response = $this->vclPutWrapper($url, $this->headersPost);

    $response_data = json_decode($response->getBody());

    $cloned_version_number = isset($response_data->number) ? $response_data->number : false;
    $this->lastClonedVersion = $cloned_version_number;

    return $cloned_version_number;
  }

  /**
   * Prepares condition for insertion
   *
   * @return array|bool
   */
  public function prepareCondition() {
    // Prepare condition content
    $requests = [];
    foreach ($this->conditionData as $single_condition_data) {
      if (empty($single_condition_data['name']) ||
        empty($single_condition_data['statement']) ||
        empty($single_condition_data['type']) ||
        empty($single_condition_data['priority'])
      ) {
        $message = 'Condition data not properly set.';
        $this->addError($message);
        $this->logger->critical($message);
        return false;
      } else {
        if ($this->getCondition($single_condition_data['name'])) {
          $requests[] = $this->prepareUpdateCondition($single_condition_data);
        } else {
          // Do insert here because condition is needed before setting (requests are not sent in order)
          return $this->insertCondition($single_condition_data);
        }
      }
    }

    return $requests;
  }


  public function getCondition1($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $name;
    $response = $this->vclGetWrapper($url, $this->headersGet);

    return $response;
  }


  public function getCondition($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $name;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseBody = (string) $response->getBody();
    $_responseBody = json_decode($responseBody);
    if(empty($_responseBody)) {
      return false;
    }
    if($_responseBody->version) {
      return true;
    }
    return false;
  }

  /**
   * Prepare condition for update
   *
   * @data array
   * @return array
   */
  public function prepareUpdateCondition($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition/' . $data['name'];
    $request = [
      'url' => $url,
      'data' => $data,
      'type' => "PUT"
    ];

    return $request;
  }

  /**
   * Prepare condition for insert
   *
   * @data
   * @return array
   */
  public function insertCondition($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/condition';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    ];

    $response = $this->vclRequestWrapper($request['url'], $this->headersPost, $request['data'], $request['type']);
    $responseData = json_decode($response->getBody());

    if ($responseData) {
      //return [];
      return $responseData;
    } else {
      return [];
    }
  }

  /**
   * Prepares setting for insertion
   *
   * @return array|bool
   */
  public function prepareSetting() {
    // Prepare setting content
    $requests = [];
    foreach ($this->settingData as $single_setting_data) {
      if (empty($single_setting_data['name']) ||
        empty($single_setting_data['action']) ||
        empty($single_setting_data['request_condition'])
      ) {
        $message = 'Setting data not properly set.';
        $this->addError($message);
        $this->logger->critical($message);
        return false;
      } else {
        if ($this->getSetting($single_setting_data['name'])) {
          $requests[] = $this->prepareUpdateSetting($single_setting_data);
        } else {
          $requests[] = $this->prepareInsertSetting($single_setting_data);
        }
      }
    }

    return $requests;
  }

  /**
   * Fetches setting by condition name
   *
   * @name string
   * @return bool
   */
  public function getSetting($name) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings/' . $name;
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseBody = (string) $response->getBody();
    $_responseBody = json_decode($responseBody);

    if(empty($_responseBody)) {
        return false;
    }

    if(!$_responseBody->version) {
      return false;
    }

    return true;
  }

  /**
   * Prepares update setting data
   *
   * @data array
   * @return array
   */
  public function prepareUpdateSetting($data) {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings/' . $data['name'];

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'PUT'
    ];

    return $request;
  }

  /**
   * Prepares Insert setting data
   *
   * @data array
   * @return array
   */
  public function prepareInsertSetting($data) {

    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/request_settings';

    $request = [
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    ];

    return $request;
  }

  /**
   * Validates last cloned version
   *
   * @return bool
   */
  public function validateVersion() {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/validate';
    $response = $this->vclGetWrapper($url, $this->headersGet);
    $responseData = json_decode($response->getBody());

    if(!empty($responseData->errors)) {
      return false;
    }

    return true;
  }

  /**
   * Activates last cloned version
   *
   * @return array
   */
  public function prepareActivateVersion() {
    $url = $this->versionBaseUrl . '/' . $this->lastClonedVersion . '/activate';

    $request = [
      'url' => $url,
      'type' => 'PUT',
      'headers' => $this->headersGet
    ];

    return $request;
  }

  /**
   * Adds new error to error array
   *
   * @param string $message
   */
  public function addError($message) {
    $this->errors[] = $message;
  }

  /**
   * Fetches logged errors
   *
   * @return array
   */
  public function getErrors() {
    return $this->errors;
  }

  /**
   * Wraps api call to make query via Guzzle
   *
   * @param $url
   * @param array $headers
   * @param array $data
   * @param string $type
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function vclRequestWrapper($url, $headers = [], $data = [], $type = "GET") {
    return $this->api->vclQuery($url, $data, $type, $headers);
  }

  /**
   * Makes get request via vclRequestWrapper
   *
   * @param $url
   * @param array $headers
   * @param array $data
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function vclGetWrapper($url, $headers = [], $data = []) {
    return $this->vclRequestWrapper($url, $headers, $data, "GET");
  }

  /**
   * Makes put request via vclRequestWrapper
   *
   * @param $url
   * @param array $headers
   * @param array $data
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function vclPutWrapper($url, $headers = [], $data = []) {
    return $this->vclRequestWrapper($url, $headers, $data, "PUT");
  }
}
