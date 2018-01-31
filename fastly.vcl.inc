<?php

/**
 * Class to control the VCL handling.
 */
class Vcl {
  /**
   * VCL data to be processed .*/
  protected $_vcl_data;

  /**
   * Condition data to be processed .*/
  protected $_condition_data;

  /**
   * Setting data to be processed .*/
  protected $_setting_data;


  /**
   * Fastly API endpoint .*/
  protected $_hostname;

  /**
   * Fastly API Key .*/
  protected $_api_key;

  /**
   * Fastly Service ID .*/
  protected $_service_id;

  /**
   * Fastly API URL version base .*/
  protected $_version_base_url;

  /**
   * Headers used for GET requests .*/
  protected $_headers_get;

  /**
   * Headers used for POST, PUT requests .*/
  protected $_headers_post;

  /**
   * Last active version data .*/
  protected $_last_version_data;

  /**
   * Next cloned version number .*/
  public $_next_cloned_version_num = NULL;

  /**
   * Last active version number .*/
  public $_last_active_version_num = NULL;

  /**
   * Last cloned version number .*/
  protected $_last_cloned_version;

  /**
   * Errors .*/
  protected $_errors = array();

  /**
   * @var Fastly
   */
  protected $_api;

  /**
   * Sets data to be processed, sets Credentials
   * VclHandler constructor.
   */
  public function __construct($data, $api_key, $service_id) {
    $this->_api_key = $api_key;
    $this->_service_id = $service_id;
    $this->_hostname = 'https://api.fastly.com/';
    $this->_api = fastly_get_api($this->_api_key, $this->_service_id);

    $this->_vcl_data = !empty($data['vcl']) ? $data['vcl'] : FALSE;
    $this->_condition_data = !empty($data['condition']) ? $data['condition'] : FALSE;
    $this->_setting_data = !empty($data['setting']) ? $data['setting'] : FALSE;
    $this->_response_object_data = !empty($data['response']) ? $data['response'] : FALSE;

    // Set credentials based data (API url, headers, last version)
    $this->_version_base_url = 'service/' . $this->_service_id . '/version';
    $this->_headers_get = array(
      'Fastly-Key' => $this->_api_key,
      'Accept' => 'application/json',
    );
    $this->_headers_post = array(
      'Fastly-Key' => $this->_api_key,
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded',
    );

    $this->_last_version_data = $this->get_last_version();

    if ($this->_last_version_data) {
      $this->_last_active_version_num = $this->_last_version_data->number;
    }

    return;
  }

  /**
   * Main execute function, takes values inserted into constructor, builds requests and sends them via Fastly API.
   *
   * @activate bool
   *
   * @return bool
   */
  public function execute($activate = FALSE) {
    // Check if last version is fetched.
    if ($this->_last_version_data === FALSE) {
      $this->add_error(t('Last version does not exist'));
      return FALSE;
    }

    // Check if any of the data is set.
    if (empty($this->_vcl_data) && empty($this->_condition_data) && empty($this->_setting_data) && empty($this->_response_object_data)) {
      $this->add_error(t('No update data set, please specify, vcl, condition or setting data'));
      return FALSE;
    }

    try {
      if (FALSE === $this->clone_last_active_version()) {
        $this->add_error(t('Unable to clone last version'));
        return FALSE;
      }

      $requests = array();

      if (!empty($this->_vcl_data)) {
        $requests = array_merge($requests, $this->prepare_vcl());
      }

      if (!empty($this->_condition_data)) {
        $conditions = $this->prepare_condition();
        if (FALSE === $conditions) {
          $this->add_error(t('Unable to insert new condition'));
          return FALSE;
        }
        $requests = array_merge($requests, $conditions);
      }

      if (!empty($this->_setting_data)) {
        $requests = array_merge($requests, $this->prepare_setting());
      }

      if (!empty($this->_response_object_data)) {
        $requests = array_merge($requests, $this->prepare_response_object());
      }

      if (!$this->validate_version()) {
        $this->add_error(t('Version not validated'));
        return FALSE;
      }

      // Set Request Headers.
      foreach ($requests as $key => $request) {
        if (in_array($request['type'], array("POST", "PUT"))) {
          $requests[$key]['headers'] = $this->_headers_post;
        }
        else {
          $requests[$key]['headers'] = $this->_headers_get;
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
        $response = $this->_api->makeRequest($url, $data, $type, $headers);

        $responses[] = $response;
      }

      $pass = TRUE;
      foreach ($responses as $response) {
        if ($response->code != 404) {
          $success = TRUE;
        }
        else {
          $success = FALSE;
        }

        if (!$success) {
          $pass = FALSE;
          $this->add_error(t('Some of the API requests failed, enable debugging and check logs for more information.'));

          $this->add_error(t('VCL update failed : ' . $response->data));

        }
      }

      // Activate version if vcl is successfully uploaded.
      if ($pass && $activate) {
        $request = $this->prepare_activate_version();
        $response = $this->_api->makeRequest($request['url'], array(), $request['type'], $request['headers']);

        if (!$response->code == 200) {
          $pass = FALSE;
          $this->add_error(t('Some of the API requests failed, enable debugging and check logs for more information.'));

          $this->add_error(t('Activation of new version failed : ' . $response->data));
        }
        else {
          $this->add_message(t('VCL updated, version activated : ' . $this->_last_cloned_version));

        }
      }
      elseif ($pass && !$activate) {
        $this->add_message(t('VCL updated, but not activated.'));
      }

    }
    catch (Exception $e) {
      $this->add_error(t('Some of the API requests failed, enable debugging and check logs for more information.'));
      $this->add_error(t('VCL update failed : ' . $e->getMessage()));
      return FALSE;
    }

    return $pass;
  }

  /**
   * Prepares VCL request.
   *
   * @return array|bool
   */
  public function prepare_vcl() {
    // Prepare VCL data content.
    $requests = array();
    foreach ($this->_vcl_data as $key => $single_vcl_data) {
      if (!empty($single_vcl_data['type'])) {

        // Append subdirectory to name if it exists.
        if (!empty($single_vcl_data['subdirectory'])) {
          $single_vcl_data['name'] = 'drupalmodule_' . $single_vcl_data['subdirectory'] . '_' . $single_vcl_data['type'];
          $single_vcl_data['vcl_dir'] = $single_vcl_data['vcl_dir'] . DIRECTORY_SEPARATOR . $single_vcl_data['subdirectory'];
          unset($single_vcl_data['subdirectory']);
        }
        else {
          $single_vcl_data['name'] = 'drupalmodule_' . $single_vcl_data['type'];
        }

        $single_vcl_data['dynamic'] = 0;
        $single_vcl_data['priority'] = 60;

        if (file_exists($single_vcl_data['vcl_dir'] . DIRECTORY_SEPARATOR . $single_vcl_data['type'] . '.vcl')) {
          $single_vcl_data['content'] = file_get_contents($single_vcl_data['vcl_dir'] . DIRECTORY_SEPARATOR . $single_vcl_data['type'] . '.vcl');
          unset($single_vcl_data['vcl_dir']);
        }
        else {
          $this->add_error(t('VCL file does not exist.'));
          return FALSE;
        }

        if ($this->check_if_vcl_exists($single_vcl_data['name'])) {
          $requests[] = $this->prepare_update_vcl($single_vcl_data);
        }
        else {
          $requests[] = $this->prepare_insert_vcl($single_vcl_data);
        }
      }
      else {
        $this->add_error(t('VCL type not set.'));
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
   */
  public function check_if_vcl_exists($name) {
    if (empty($this->_last_version_data)) {
      return FALSE;
    }

    $uri = $this->_version_base_url . "/" . $this->_last_cloned_version . '/snippet/' . $name;

    $response = $this->_api->makeRequest($uri, array(), "GET", $this->_headers_get);
    if ($response->code != 404) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Prepares request for updating existing VCL.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_update_vcl($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/snippet/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    );

    return $request;
  }

  /**
   * Prepare request for inserting new VCL.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_insert_vcl($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/snippet';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "POST",
    );

    return $request;
  }

  /**
   * Fetch last service version.
   *
   * @return bool|int
   */
  public function get_last_version() {
    $uri = $this->_version_base_url;
    $response = $this->_api->makeRequest($uri, array(), "GET", $this->_headers_get);

    $response_data = json_decode($response->data);

    $this->_next_cloned_version_num = count($response_data) + 1;

    foreach ($response_data as $key => $version_data) {
      if ($version_data->active) {
        return $version_data;
      }
    }
    return FALSE;
  }

  /**
   * Creates and returns cloned version number.
   *
   * @return bool
   */
  public function clone_last_active_version() {
    if (empty($this->_last_version_data)) {
      return FALSE;
    }

    $version_number = $this->_last_version_data->number;
    $uri = $this->_version_base_url . '/' . $version_number . '/clone';
    $response = $this->_api->makeRequest($uri, array(), "PUT", array());

    $response_data = json_decode($response->data);
    $cloned_version_number = isset($response_data->number) ? $response_data->number : FALSE;
    $this->_last_cloned_version = $cloned_version_number;

    return $cloned_version_number;
  }

  /**
   * Prepares condition for insertion.
   *
   * @return array|bool
   */
  public function prepare_condition() {
    // Prepare condition content.
    $requests = array();
    foreach ($this->_condition_data as $single_condition_data) {
      if (empty($single_condition_data['name']) ||
        empty($single_condition_data['statement']) ||
        empty($single_condition_data['type']) ||
        empty($single_condition_data['priority'])
      ) {
        $this->add_error(t('Condition data not properly set.'));
        return FALSE;
      }
      else {
        if ($this->get_condition($single_condition_data['name'])) {
          $requests[] = $this->prepare_update_condition($single_condition_data);
        }
        else {
          // Do insert here because condition is needed before setting (requests are not sent in order)
          return $this->insert_condition($single_condition_data);
        }
      }
    }
    return $requests;
  }

  /**
   * Fetches condition by condition name.
   *
   * @name string
   *
   * @return bool
   */
  public function get_condition($name) {
    $uri = $this->_version_base_url . "/" . $this->_last_cloned_version . '/condition/' . $name;
    $response = $this->_api->makeRequest($uri, array(), "GET", $this->_headers_get);
    if ($response->code != 404) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Prepare condition for update.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_update_condition($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/condition/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    );

    return $request;
  }

  /**
   * Prepare condition for insert.
   *
   * @data
   *
   * @return array
   */
  public function insert_condition($data) {
    $uri = $this->_version_base_url . '/' . $this->_last_cloned_version . '/condition';

    $request = array(
      'uri' => $uri,
      'data' => $data,
      'type' => "POST",
    );

    $response = $this->_api->makeRequest($request['uri'], $request['data'], $request['type'], $this->_headers_post);
    if ($response->code == 200) {
      return array();
    }
    else {
      return FALSE;
    }
  }

  /**
   * Prepares setting for insertion.
   *
   * @return array|bool
   */
  public function prepare_setting() {
    // Prepare setting content.
    $requests = array();
    foreach ($this->_setting_data as $single_setting_data) {
      if (empty($single_setting_data['name']) ||
        empty($single_setting_data['action']) ||
        empty($single_setting_data['request_condition'])
      ) {
        $this->add_error(t('Setting data not properly set.'));
        return FALSE;
      }
      else {
        if ($this->get_setting($single_setting_data['name'])) {
          $requests[] = $this->prepare_update_setting($single_setting_data);
        }
        else {
          $requests[] = $this->prepare_insert_setting($single_setting_data);
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
   */
  public function get_setting($name) {
    $uri = $this->_version_base_url . '/' . $this->_last_cloned_version . '/request_settings/' . $name;

    $response = $this->_api->makeRequest($uri);
    if ($response->code != 404) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Prepares update setting data.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_update_setting($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/request_settings/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    );

    return $request;
  }

  /**
   * Prepares Insert setting data.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_insert_setting($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/request_settings';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "POST",
    );

    return $request;
  }

  /**
   * Prepares request object for insertion.
   *
   * @return array|bool
   */
  public function prepare_response_object() {
    // Prepare setting content.
    $requests = array();
    foreach ($this->_response_object_data as $single_response_object_data) {
      if (empty($single_response_object_data['name']) ||
        empty($single_response_object_data['request_condition']) ||
        empty($single_response_object_data['content'])
      ) {
        $this->add_error(t('Setting data not properly set.'));
        return FALSE;
      }
      else {
        if ($this->get_response_object($single_response_object_data['name'])) {
          $requests[] = $this->prepare_update_response_object($single_response_object_data);
        }
        else {
          $requests[] = $this->prepare_insert_response_object($single_response_object_data);
        }
      }
    }
    return $requests;
  }

  /**
   * Fetches response object by name.
   *
   * @name string
   *
   * @return bool
   */
  public function get_response_object($name) {
    $uri = $this->_version_base_url . '/' . $this->_last_cloned_version . '/response_object/' . $name;

    $response = $this->_api->makeRequest($uri, array(), "GET", $this->_headers_get);
    if ($response->code != 404) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Prepares update response object data.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_update_response_object($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/response_object/' . $data['name'];

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "PUT",
    );

    return $request;
  }

  /**
   * Prepares insert response object data.
   *
   * @data array
   *
   * @return array
   */
  public function prepare_insert_response_object($data) {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/response_object';

    $request = array(
      'url' => $url,
      'data' => $data,
      'type' => "POST",
    );

    return $request;
  }

  /**
   * Validates last cloned version.
   *
   * @return bool
   */
  public function validate_version() {
    $uri = $this->_version_base_url . '/' . $this->_last_cloned_version . '/validate';

    $response = $this->_api->makeRequest($uri, array(), "GET", $this->_headers_get);

    if ($response->code != 404) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Activates last cloned version.
   *
   * @return array
   */
  public function prepare_activate_version() {
    $url = $this->_version_base_url . '/' . $this->_last_cloned_version . '/activate';

    $request = array(
      'url' => $url,
      'type' => "PUT",
      'headers' => $this->_headers_get,
    );

    return $request;
  }

  /**
   * Adds new error.
   *
   * @param $message
   */
  public function add_error($message) {
    drupal_set_message($message, "error");
  }

  /**
   * Adds new message.
   *
   * @param $message
   */
  public function add_message($message) {
    drupal_set_message(t($message), "status");
  }

}
