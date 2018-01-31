<?php

/**
 * @file
 * Administrative forms for Fastly module.
 */

/**
 * Settings form.
 */
function fastly_setup_form($form_state) {
  $api = fastly_get_api();
  $service_id = variable_get('fastly_service_id', '');
  $api_key = variable_get('fastly_api_key', '');

  $form['fastly_api_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Fastly API Key'),
    '#default_value' => $api_key,
    '#required' => TRUE,
    '#description' => t('You can find it on your account settings page. If you dont have an account, please go to <a href="/?q=admin/config/services/fastly/register">registration page</a>'),
  );

  if ($api_key) {
    $services = array();

    foreach ($api->getServices() as $service) {
      $services[$service->id] = $service->name;
    }

    ksort($services);

    $form['fastly_service_id'] = array(
      '#type' => 'radios',
      '#title' => t('Service'),
      '#options' => $services,
      '#default_value' => $service_id,
      '#required' => TRUE,
      '#description' => t('A Service represents the configuration for your website to be served through Fastly.'),
    );

    $form['actions']['new_service'] = array(
      '#markup' => l(t('New service'), 'admin/config/services/fastly/new',
        array('attributes' => array('class' => 'button'))),
      '#weight' => 10,
    );
  }

  $default_ttl = $api->getSetting('general.default_ttl');

  $form['fastly_ttl'] = array(
    '#type' => 'textfield',
    '#title' => t('Default TTL'),
    '#default_value' => $default_ttl ? $default_ttl : '',
    '#description' => t('The default time to live for cached content in seconds.'),
  );

  $description = t("Specify pages by using their paths. Enter one path per line. The '*' character is a wildcard. Example paths are %blog for the blog page and %blog-wildcard for every personal blog. %front is the front page.",
    array(
      '%blog' => 'blog',
      '%blog-wildcard' => 'blog/*',
      '%front' => '<front>',
    ));

  $form['fastly_non_cached'] = array(
    '#type' => 'textarea',
    '#title' => t('Non-cached pages'),
    '#default_value' => variable_get('fastly_non_cached', ''),
    '#description' => $description,
  );

  if (module_exists('httprl')) {
    $form['fastly_purge_endpoint'] = array(
      '#type' => 'textfield',
      '#title' => t('Purge endpoint'),
      '#default_value' => variable_get('fastly_purge_endpoint', NULL),
      '#description' => t('Alternative PURGE request endpoint, use your <a href="@cname-url">Fastly CNAME hostname</a> if an HTTP request to %base_url from your server would not go through Fastly.', array(
        '%base_url' => $GLOBALS['base_url'],
        '@cname-url' => 'https://docs.fastly.com/guides/basic-setup/adding-cname-records',
      )),
    );
  }

  $form['fastly_purge_type'] = array(
    '#type' => 'radios',
    '#title' => t('Purging'),
    '#options' => array(
      'soft' => 'Soft purge',
      'immediate' => 'Immediate purge',
    ),
    '#default_value' => variable_get('fastly_purge_type', 'immediate'),
    '#required' => TRUE,
    '#description' => t('Purge of cached items may be immediate or delayed (soft). Soft purge means Fastly will deliver the existing cache until new data is fetched from the origin.'),
  );

  $form['#submit'][] = 'fastly_setup_form_submit';

  return system_settings_form($form);
}

/**
 * Webhook form
 *
 * @param $form_state
 * @return mixed
 */
function fastly_webhook_form($form_state) {
  $form['fastly_webhook_enabled'] = array(
    '#type' => 'radios',
    '#title' => t('Webhook enabled?'),
    '#options' => array(
        'yes' => 'Yes',
        'no' => 'No',
    ),
    '#default_value' => variable_get('fastly_webhook_enabled', 'no'),
  );

  $defaultWebhookUrlValue = variable_get('fastly_webhook_url', '');

  $form['fastly_webhook_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Incoming WebHook URL'),
    '#description' => t('Incoming WebHook URL'),
    '#states' => array(
        'visible' => array(
            ':input[name="fastly_webhook_enabled"]' => array('value' => 'yes'),
        ),
    ),
    '#required' => TRUE,
    '#default_value' => $defaultWebhookUrlValue,
  );

  $defaultFastlyWebhookEvents = variable_get('fastly_webhook_events', array());

  $form['fastly_webhook_events'] = array(
    '#type' => 'select',
    '#multiple' => TRUE,
    '#title' => t('Send webhook notifications for'),
    '#required' => FALSE,
    '#options' => array("purge_actions" =>"Purge actions", "vcl_upload_actions" =>"VLC upload actions"),
    '#size' => 5,
    '#weight' => 0,
    '#states' => array(
        'visible' => array(
            ':input[name="fastly_webhook_enabled"]' => array('value' => 'yes'),
        ),
    ),
    '#default_value' => $defaultFastlyWebhookEvents,
  );

  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save webhook configuration'),
  );

  return $form;
}

/**
 * Save form variables
 *
 * @param $form
 * @param $form_state
 */
function fastly_webhook_form_submit(&$form, &$form_state) {
  variable_set('fastly_webhook_url', $form_state['values']['fastly_webhook_url']);
  variable_set('fastly_webhook_events', $form_state['values']['fastly_webhook_events']);
}

/**
 * Register form.
 */
function fastly_register_form($form_state) {
  if (variable_get('fastly_used_registration')) {
    drupal_set_message(t('You are already registered. You can <a href="https://app.fastly.com/#password_reset">reset your password</a> if you don\'t remember it.'), 'warning');
  }
  else {
    global $user;

    $form['owner_first_name'] = array(
      '#type' => 'textfield',
      '#title' => t("First Name"),
      '#description' => t("The customer account owner's first name. Ex: John."),
      '#required' => TRUE,
    );

    $form['owner_last_name'] = array(
      '#type' => 'textfield',
      '#title' => t("Last Name"),
      '#description' => t("The customer account owner's last name. Ex: Smith."),
      '#required' => TRUE,
    );

    $form['owner_login'] = array(
      '#type' => 'textfield',
      '#title' => t("Email"),
      '#default_value' => $user->mail,
      '#description' => t("The owner's email to be used as login. Ex: john@somebusiness.com."),
      '#required' => TRUE,
    );

    $form['owner_password'] = array(
      '#type' => 'password',
      '#title' => t("Password"),
      '#default_value' => '',
      '#required' => TRUE,
    );

    $form['confirm_password'] = array(
      '#type' => 'password',
      '#title' => t("Confirm Password"),
      '#default_value' => '',
      '#description' => t("The customer account owner's password. Please enter 4 or more characters. Ex: P@ssW0rd!1"),
      '#required' => TRUE,
    );

    $form['account_name'] = array(
      '#type' => 'textfield',
      '#title' => t('Company Name'),
      '#default_value' => variable_get('site_name', ''),
      '#description' => t('The customer account name. Ex: Some Business, LLC.'),
      '#required' => TRUE,
    );

    $form['origin_ip'] = array(
      '#type' => 'textfield',
      '#title' => t('Origin Server IP'),
      '#default_value' => $_SERVER['SERVER_ADDR'],
      '#required' => TRUE,
    );

    $form['port'] = array(
      '#type' => 'textfield',
      '#title' => t('Origin Port'),
      '#default_value' => '80',
      '#required' => TRUE,
    );

    $form['domain_name'] = array(
      '#type' => 'textfield',
      '#title' => t('Domain Name'),
      '#default_value' => $_SERVER['HTTP_HOST'],
      '#required' => TRUE,
    );

    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Sign Up'),
    );

    $form['actions']['already_registered'] = array(
      '#markup' => t('Already registered?') . ' ' . l(t('Click here to enter your account information and get started!'), 'admin/config/services/fastly/config'),
    );

    $form['policy'] = array(
      '#markup' => '<p>' . t('By clicking "Sign Up" you are agreeing to the <a target="_blank" href="https://www.fastly.com/terms">Terms of Use</a> and <a target="_blank" href="https://www.fastly.com/privacy">Privacy Policy</a>.') . '</p>',
    );

    return $form;
  }
}

/**
 * Purge form.
 */
function fastly_purge_form($form_state) {
  if (variable_get('fastly_service_id', '')  && variable_get('fastly_api_key', '')) {
    $form['purge_url'] = array(
      '#type' => 'fieldset',
      '#title' => t('Purge by URL'),
      '#description' => t('Paste one or more URLs to purge. Each in new line.'),
    );

    $form['purge_url']['urls_list'] = array(
      '#type' => 'textarea',
    );

    $form['purge_url']['submit'] = array(
      '#type' => 'button',
      '#value' => t('Purge'),
      '#id' => 'urls',
      '#name' => 'urls',
      '#executes_submit_callback' => 1,
    );

    $form['purge_key'] = array(
      '#type' => 'fieldset',
      '#title' => t('Purge by key'),
      '#description' => t('Paste one or more keys to purge. Each in new line.'),
    );

    $form['purge_key']['keys_list'] = array(
      '#type' => 'textarea',
    );

    $form['purge_key']['submit'] = array(
      '#type' => 'button',
      '#value' => t('Purge'),
      '#id' => 'keys',
      '#name' => 'keys',
      '#executes_submit_callback' => 1,
    );

    $form['purge_all'] = array(
      '#type' => 'fieldset',
      '#title' => t('Purge all'),
      '#description' => t('Purge whole service. You might not use this function too often.'),
    );

    $form['purge_all']['submit'] = array(
      '#type' => 'button',
      '#value' => t('Purge'),
      '#id' => 'all',
      '#name' => 'all',
      '#executes_submit_callback' => 1,
    );

    return $form;
  }
  else {
    drupal_set_message(t('You need to set up your API key and service ID to use this form.'), 'warning');
  }
}

/**
 * New service form.
 */
function fastly_new_service_form() {
  $form['name'] = array(
    '#type' => 'textfield',
    '#title' => t('Service Name'),
    '#default_value' => variable_get('site_name', ''),
    '#required' => TRUE,
  );

  $form['origin_ip'] = array(
    '#type' => 'textfield',
    '#title' => t('Origin Server IP'),
    '#default_value' => $_SERVER['SERVER_ADDR'],
    '#required' => TRUE,
  );

  $form['port'] = array(
    '#type' => 'textfield',
    '#title' => t('Origin Port'),
    '#default_value' => '80',
    '#required' => TRUE,
  );

  $form['domain_name'] = array(
    '#type' => 'textfield',
    '#title' => t('Domain Name'),
    '#default_value' => $_SERVER['HTTP_HOST'],
    '#required' => TRUE,
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Create'),
  );

  return $form;
}

/**
 * Implements hook_form_validate().
 */
function fastly_register_form_validate($form, &$form_state) {
  if ($form_state['values']['owner_password'] && strlen($form_state['values']['owner_password']) < 4) {
    form_set_error('owner_password', t('The password must contain 4 or more characters.'));
  }

  if (!valid_email_address($form_state['values']['owner_login'])) {
    form_set_error('owner_login', t('The email address is invalid.'));
  }

  if ($form_state['values']['owner_password'] !== $form_state['values']['confirm_password']) {
    form_set_error('confirm_password', t('The passwords do not match.'));
  }

  fastly_help_message();
}

/**
 * Implements hook_form_validate().
 */
function fastly_setup_form_validate($form, &$form_state) {
  $api = fastly_get_api($form_state['values']['fastly_api_key']);

  if (!$api->validate()) {
    form_set_error('', t('Invalid API key.'));
  }

  fastly_help_message();
}

/**
 * Implements hook_form_submit().
 */
function fastly_setup_form_submit($form, &$form_state) {
  $api = fastly_get_api();
  $api->updateSettings(array('general.default_ttl' => $form_state['values']['fastly_ttl']));
}

/**
 * Implements hook_form_submit().
 */
function fastly_register_form_submit($form, &$form_state) {
  $data = array(
    'name' => $form_state['values']['account_name'],
    'owner' => array(
      'name' => $form_state['values']['owner_first_name'] . ' ' . $form_state['values']['owner_last_name'],
      'login' => $form_state['values']['owner_login'],
      'password' => $form_state['values']['owner_password'],
    ),
    'domain' => $form_state['values']['domain_name'],
    'port' => $form_state['values']['port'],
    'address' => $form_state['values']['origin_ip'],
    'ipv4' => $form_state['values']['origin_ip'],
    'version' => 1,
    'id' => 'syslog',
  );

  $api = fastly_get_api();

  $account = $api->signup($data);

  if (isset($account->msg)) {
    drupal_set_message($account->msg, 'error');
  }
  else {
    variable_set('fastly_used_registration', 1);
    variable_set('fastly_api_key', $account->api_key);
    variable_set('fastly_service_id', $account->service_id);

    drupal_set_message(t('Registration successful! You will receive a confirmation email very soon. Please check your inbox and verify your account by clicking the received link.'));

    drupal_goto('admin/config/services/fastly/purge');
  }
}

/**
 * Implements hook_form_submit().
 */
function fastly_new_service_form_submit($form, &$form_state) {
  $data = array(
    'name' => $form_state['values']['name'],
    'domain' => $form_state['values']['domain_name'],
    'port' => $form_state['values']['port'],
    'address' => $form_state['values']['domain_name'],
    'ipv4' => $form_state['values']['origin_ip'],
    'version' => 1,
    'id' => 'syslog',
  );

  $api = fastly_get_api();

  $service = $api->createService($data);

  if (isset($service->msg)) {
    drupal_set_message($service->msg, 'error');
  }
  else {
    variable_set('fastly_service_id', $service->id);

    drupal_set_message(t('A new service successfuly created and set to default.'));

    drupal_goto('admin/config/services/fastly');
  }
}

/**
 * Implements hook_form_submit().
 */
function fastly_purge_form_submit($form, &$form_state) {
  $method = $form_state['triggering_element']['#name'];
  $api = fastly_get_api();

  switch ($method) {
    case 'all':
      $api->purgeAll();
      break;

    case 'urls':
      $values = trim($form_state['values']['urls_list']);

      if (empty($values)) {
        $error = (bool) drupal_set_message(t('Please input the URLs to purge.'), 'error');
      }
      else {
        foreach (explode("\n", $values) as $line) {
          $api->purgeQuery(trim($line));
        }
      }
      break;

    case 'keys':
      $values = trim($form_state['values']['keys_list']);

      if (empty($values)) {
        $error = (bool) drupal_set_message(t('Please input the keys to purge.'), 'error');
      }
      else {
        foreach (explode("\n", $values) as $line) {
          $api->purgeKey(trim($line));
        }
      }
      break;
  }

  if (empty($error)) {
    drupal_set_message(t('Cache successfuly purged.'));
  }
}

/**
 * New VCL upload form
 */
function fastly_vcl_upload_form($form, &$form_state) {
  $form['vcl'] = array(
    '#type' => 'fieldset',
    '#title' => t('VCL Upload'),
    '#description' => t('Upload Fastly VCL snippets that improve cacheability of the site. Not required but highly recommended.'),
  );
  $form['vcl']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Upload VCL'),
    '#attributes' => array('onclick' => 'if(!confirm("Are you sure you want to upload VCL snippets?")){return false;}'),
  );

  $form['vcl']['actions']['activate'] = array(
    '#type' => 'checkbox',
    '#default_value' => 1,
    '#title' => t('Activate version'),
  );

  return $form;
}

/**
 * Implements hook_form_submit().
 */
function fastly_vcl_upload_form_submit($form, &$form_state) {
  $vcl = fastly_get_vcl();
  $activate = $form_state['values']['activate'];
  $vcl->execute($activate);
}

/**
 * A reusable snippet.
 *
 * Checks for error messages
 * and sets up an extra message with a link to Zendesk.
 */
function fastly_help_message() {
  if (drupal_get_messages('error', FALSE)) {
    drupal_set_message(t('<a href="https://fastly.zendesk.com">Click here</a> if you need help.'), 'warning');
  }
}
