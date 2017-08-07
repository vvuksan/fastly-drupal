<?php

namespace Drupal\fastly;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class to control the VCL handling.
 */
class VclHandler
{
  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * VCL data to be processed
   */
  protected $_vclData;

  /**
   * Condition data to be processed
   */
  protected $_conditionData;

  /**
   * Setting data to be processed
   */
  protected $_settingData;

  /**
   * Fastly API endpoint
   */
  protected $_hostname;

  /**
   * Fastly API Key
   */
  protected $_apiKey;

  /**
   * Fastly Service ID
   */
  protected $serviceId;

  /**
   * Fastly API URL version base
   */
  protected $_versionBaseUrl;

  /**
   * Headers used for GET requests
   */
  protected $_headersGet;

  /**
   * Headers used for POST, PUT requests
   */
  protected $_headersPost;

  /**
   * Last active version data
   */
  protected $_lastVersionData;

  /**
   * Next cloned version number
   */
  public $_nextClonedVersionNum = null;

  /**
   * Last active version number
   */
  public $_lastActiveVersionNum = null;

  /**
   * Last cloned version number
   */
  protected $_lastClonedVersion;

  /**
   * Errors
   */
  protected $_errors = array();

  /**
   * Sets data to be processed, sets Credentials
   * Vcl_Handler constructor.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param $host
   * @param Api $api
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, Api $api) {
    $vcl_dir = drupal_get_path('module', 'fastly'). '/vcl_snippets';
    $data = array(
      'vcl' => array(
        array(
          'vcl_dir' => $vcl_dir,
          'type' => 'recv'
        ),
        array(
          'vcl_dir' => $vcl_dir,
          'type' => 'deliver',
        ),
        array(
          'vcl_dir' => $vcl_dir,
          'type' => 'error',
        ),
        array(
          'vcl_dir' => $vcl_dir,
          'type' => 'fetch',
        )
      ),
      'condition' => array(
        array(
          'name' => 'drupalmodule_request1',
          'statement' => 'req.http.x-pass == "1"',
          'type' => 'REQUEST',
          'priority' => 90
        )
      ),
      'setting' => array(
        array(
          'name' => 'drupalmodule_setting1',
          'action' => 'pass',
          'request_condition' => 'drupalmodule_request1'
        )
      )
    );

    $this->api = $api;
    $config = $config_factory->get('fastly.settings');
    $this->_vclData = !empty($data['vcl']) ? $data['vcl'] : false;
    $this->_conditionData = !empty($data['condition']) ? $data['condition'] : false;
    $this->_settingData = !empty($data['setting']) ? $data['setting'] : false;
    $this->_hostname = $host;
    $this->serviceId = $config->get('service_id');
    $this->_apiKey = $config->get('api_key');

    $connection = $this->testFastlyApiConnection($this->_hostname, $this->serviceId, $this->_apiKey);

    if (!$connection['status']) {
      $this->addError($connection['message']);
      return;
    }

    // Set credentials based data (API url, headers, last version)
    $this->_versionBaseUrl = '/service/' . $this->serviceId . '/version';
    $this->_headersGet = array(
      'Fastly-Key' => $this->_apiKey,
      'Accept' => 'application/json'
    );
    $this->_headersPost = array(
      'Fastly-Key' => $this->_apiKey,
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded'
    );

    $this->_lastVersionData = $this->getLastVersion();

    if ($this->_lastVersionData) {
      $this->_lastActiveVersionNum = $this->_lastVersionData->number;
    }

    return;
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
    if ($this->_lastVersionData === false) {
      $this->addError('Last version does not exist');
      return false;
    }

    // Check if any of the data is set
    if (empty($this->_vclData) && empty($this->_conditionData) && empty($this->_settingData)) {
      $this->addError('No update data set, please specify, vcl, condition or setting data');
      return false;
    }

    try {
      if (false === $this->cloneLastActiveVersion()) {
        $this->addError('Unable to clone last version');
        return false;
      }

      $requests = array();

      if (!empty($this->_vclData)) {
        $requests = array_merge($requests, $this->prepareVcl());
      }

      if (!empty($this->_conditionData)) {
        $conditions = $this->prepareCondition();
        if (false === $conditions) {
          $this->addError('Unable to insert new condition');
          return false;
        }
        $requests = array_merge($requests, $conditions);
      }

      if (!empty($this->_settingData)) {
        $requests = array_merge($requests, $this->prepareSetting());
      }

      if (!$this->validateVersion()) {
        $this->addError('Version not validated');
        return false;
      }

      // Set Request Headers
      foreach ($requests as $key => $request) {
        if (in_array($request['type'], array("POST", "PUT"))) {
          $requests[$key]['headers'] = $this->_headersPost;
        } else {
          $requests[$key]['headers'] = $this->_headersGet;
        }
      }

      // Send Requests
      $responses = [];
      foreach($requests as $key=>$value) {
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
          $this->log("critical", $message);
        }
      }

      // Activate version if vcl is successfully uploaded
      if ($pass && $activate) {
        $request = $this->prepareActivateVersion();

        $response = $this->vclRequestWrapper($request['url'], $request['headers'], array(), $request['type']);
        if ($response->getStatusCode() != "200") {
          $pass = false;
          $this->addError('Some of the API requests failed, enable debugging and check logs for more information.');

          $message = 'Activation of new version failed : ' . $response->body;
          $this->log("critical", $message);
        } else {
          $message = 'VCL updated, version activated : ' . $this->_lastClonedVersion;
         // send_web_hook($message);
        }
      } elseif ($pass && !$activate) {
        $message = 'VCL updated, but not activated.';
      }

    } catch (Exception $e) {
      $this->addError('Some of the API requests failed, enable debugging and check logs for more information.');
      $message = 'VCL update failed : ' . $e->getMessage();
      error_log($message);// Force log this, possibly no response object
      foreach($this->getErrors() as $error) {
        drupal_set_message(t($error), 'error');
      }
      return false;
    }

    return $pass;
  }

  /**
   * Prepares VCL request
   * @return array|bool
   */
  public function prepareVcl() {
    // Prepare VCL data content
    $requests = array();
    foreach ($this->_vclData as $key => $single_vcl_data) {
      if (!empty($single_vcl_data['type'])) {
        $single_vcl_data['name'] = 'drupalmodule_' . $single_vcl_data['type'];
        $single_vcl_data['dynamic'] = 0;
        $single_vcl_data['priority'] = 50;
        if (file_exists($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl')) {
          $single_vcl_data['content'] = file_get_contents($single_vcl_data['vcl_dir'] . '/' . $single_vcl_data['type'] . '.vcl');
          unset($single_vcl_data['vcl_dir']);
        } else {
          $this->addError('VCL file does not exist.');
          return false;
        }

        if ($this->checkIfVclExists($single_vcl_data['name'])) {
          $requests[] = $this->prepareUpdateVcl($single_vcl_data);
        } else {
          $requests[] = $this->prepareInsertVcl($single_vcl_data);
        }
      } else {
        $this->addError('VCL type not set.');
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
    if (empty($this->_lastVersionData)) {
      return false;
    }

    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/snippet/' . $name;
    $response = $this->vclGetWrapper($url, $this->_headersGet);

    if($response->getStatusCode() == "200") {
      return false;
    }
    return false;

  }

  /**
   * Prepares request for updating existing VCL
   *
   * @data array
   * @return array
   */
  public function prepareUpdateVcl($data) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/snippet/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT"
    );

    return $request;
  }

  /**
   * Prepare request for inserting new VCL
   *
   * @data array
   * @return array
   */
  public function prepareInsertVcl($data) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/snippet';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    );

    return $request;
  }

  /**
   * Fetch last service version
   *
   * @return bool|int
   */
  public function getLastVersion() {
    $url = $this->_versionBaseUrl;
    $response = $this->vclGetWrapper($url, $this->_headersGet);
    $response_data = json_decode($response->getBody());

    $this->_nextClonedVersionNum = count($response_data) + 1;

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
    if (empty($this->_lastVersionData)) {
      return false;
    }

    $version_number = $this->_lastVersionData->number;
    $url = $this->_versionBaseUrl . '/' . $version_number . '/clone';
    $response = $this->vclPutWrapper($url, $this->_headersPost);

    $response_data = json_decode($response->getBody());

    $cloned_version_number = isset($response_data->number) ? $response_data->number : false;
    $this->_lastClonedVersion = $cloned_version_number;

    return $cloned_version_number;
  }

  /**
   * Prepares condition for insertion
   *
   * @return array|bool
   */
  public function prepareCondition() {
    // Prepare condition content
    $requests = array();
    foreach ($this->_conditionData as $single_condition_data) {
      if (empty($single_condition_data['name']) ||
        empty($single_condition_data['statement']) ||
        empty($single_condition_data['type']) ||
        empty($single_condition_data['priority'])
      ) {
        $this->addError('Condition data not properly set.');
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

  /**
   * Fetches condition by condition name
   *
   * @name string
   * @return bool
   */
  public function getCondition($name) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/condition/' . $name;
    $response = $this->vclGetWrapper($url, $this->_headersGet);

    if($response->getStatusCode() == "200") {
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
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/condition/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT"
    );

    return $request;
  }

  /**
   * Prepare condition for insert
   *
   * @data
   * @return array
   */
  public function insertCondition($data) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/condition';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    );

    $response = $this->vclRequestWrapper($request['url'], $this->_headersPost, $request['data'], $request['type']);

    if ($response->getStatusCode() == "200") {
      return array();
    } else {
      return false;
    }
  }

  /**
   * Prepares setting for insertion
   *
   * @return array|bool
   */
  public function prepareSetting() {
    // Prepare setting content
    $requests = array();
    foreach ($this->_settingData as $single_setting_data) {
      if (empty($single_setting_data['name']) ||
        empty($single_setting_data['action']) ||
        empty($single_setting_data['request_condition'])
      ) {
        $this->addError('Setting data not properly set.');
        return false;
      } else {
        if ($this->getSetting($single_setting_data['name'])) {
          $requests[] = $this->prepare_update_setting($single_setting_data);
        } else {
          $requests[] = $this->prepare_insert_setting($single_setting_data);
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
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/request_settings/' . $name;
    $response = $this->vclGetWrapper($url, $this->_headersGet);

    if($response->getStatusCode() == "200") {
      return true;
    }
    return false;

  }

  /**
   * Prepares update setting data
   *
   * @data array
   * @return array
   */
  public function prepare_update_setting($data) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/request_settings/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => 'PUT'
    );

    return $request;
  }

  /**
   * Prepares Insert setting data
   *
   * @data array
   * @return array
   */
  public function prepare_insert_setting($data) {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/request_settings';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => 'POST'
    );

    return $request;
  }

  /**
   * Validates last cloned version
   *
   * @return bool
   */
  public function validateVersion() {
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/validate';
    $response = $this->vclGetWrapper($url, $this->_headersGet);

    if($response->getStatusCode() != "200") {
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
    $url = $this->_versionBaseUrl . '/' . $this->_lastClonedVersion . '/activate';

    $request = array(
      'url' => $url,
      'type' => 'PUT',
      'headers' => $this->_headersGet
    );

    return $request;
  }

  /**
   * Adds new error to error array
   *
   * @param string $message
   */
  public function addError($message) {
    $this->_errors[] = $message;
  }

  /**
   * Fetches logged errors
   *
   * @return array
   */
  public function getErrors() {
    return $this->_errors;
  }

  /**
   * Function for testing Fastly API connection
   *
   * @param $hostname
   * @param $service_id
   * @param $api_key
   * @return array
   */
  public function testFastlyApiConnection($hostname, $service_id, $api_key) {
    if (empty($hostname) || empty($service_id) || empty($api_key)) {
      return array('status' => false, 'message' => 'Please enter credentials first');
    }

    $url = '/service/' . $service_id;
    $headers = array(
      'Fastly-Key' => $api_key,
      'Accept' => 'application/json'
    );
    try {

      $response = $this->vclGetWrapper($url, $headers);
      if ($response->getStatusCode() == "200") {
        $status = true;
        $response_body = json_decode($response->getBody());
        $service_name = $response_body->name;
        $message = 'Connection Successful on service *' . $service_name . "*";
      } else {
        $status = false;
        $response_body = json_decode($response->getBody());
        $service_name = $response_body->name;
        $message = 'Connection not Successful on service *' . $service_name . "*" . " status : " . $response->getStatusCode();
        $this->log("critical", $message);
      }

      return array('status' => $status, 'message' => $message);

    } catch (Exception $e) {

      return array('status' => false, 'message' => $e->getMessage());
    }
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
  public function vclRequestWrapper($url, $headers = array(), $data = array(), $type = "GET") {
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
  public function vclGetWrapper($url, $headers = array(), $data = array()) {
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
  public function vclPutWrapper($url, $headers = array(), $data = array()) {
    return $this->vclRequestWrapper($url, $headers, $data, "PUT");
  }

  public function log($level, $message) {
    switch($level) {
      case "critical":
        \Drupal::logger('fastly')->notice($message);
      break;
      case "info":
        \Drupal::logger('fastly')->info($message);
      default:
    }

  }
}
