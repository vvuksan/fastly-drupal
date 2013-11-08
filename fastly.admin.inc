<?php

/**
 * @file
 * Administrative forms for Fastly module.
 */

/**
 * Settings form.
 */
function fastly_setup_form($form_state) {
  $form['fastly_api_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Fastly API Key'),
    '#default_value' => variable_get('fastly_api_key', ''),
    '#required' => TRUE,
    '#description' => t('You can find it on your account settings page. If you dont have an account, please go to <a href="/?q=admin/config/services/fastly/register">registration page</a>'),
  );

  $form['fastly_service_id'] = array(
    '#type' => 'textfield',
    '#title' => t('Service ID'),
    '#default_value' => variable_get('fastly_service_id', ''),
    '#required' => TRUE,
    '#description' => t('A Service represents the configuration for your website to be served through Fastly.'),
  );

  return system_settings_form($form);
}

/**
 * Register form.
 */
function fastly_register_form($form_state) {
  if (variable_get('fastly_used_registration')) {
    drupal_set_message(t('You already used your registration opportunity. You can <a href="https://app.fastly.com/#password_reset">reset your password</a> if you don\'t remember it.'), 'warning');
  }
  else {
    global $user;

    $form['account_name'] = array(
      '#type' => 'textfield',
      '#title' => t('Account name'),
      '#default_value' => variable_get('site_name', ''),
      '#description' => t('The customer account name. Ex: Some Business, LLC.'),
      '#required' => TRUE,
    );

    $form['owner_name'] = array(
      '#type' => 'textfield',
      '#title' => t("Owner's name"),
      '#default_value' => $user->name,
      '#description' => t("The customer account owner's name. Ex: John Doe."),
      '#required' => TRUE,
    );

    $form['owner_login'] = array(
      '#type' => 'textfield',
      '#title' => t("Owner's email"),
      '#default_value' => $user->mail,
      '#description' => t("The owner's email to be used as login. Ex: john@somebusiness.com."),
      '#required' => TRUE,
    );

    $form['owner_password'] = array(
      '#type' => 'password',
      '#title' => t("Owner's password"),
      '#default_value' => '',
      '#description' => t("The customer account owner's password. Please enter 4 or more characters. Ex: P@ssW0rd!1"),
      '#required' => TRUE,
    );

    $form['policy'] = array(
      '#markup' => '<p>' . t('By clicking "Sign Up" you are agreeing to the <a target="_blank" href="https://www.fastly.com/terms">Terms of Use</a> and <a target="_blank" href="https://www.fastly.com/privacy">Privacy Policy</a>.') . '</p>',
    );

    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Sign Up'),
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
 * Implements hook_form_validate().
 */
function fastly_register_form_validate($form, &$form_state) {
  if ($form_state['values']['owner_password'] && strlen($form_state['values']['owner_password']) < 4) {
    form_set_error('owner_password', t('The password must contain 4 or more characters.'));
  }
  if (!valid_email_address($form_state['values']['owner_login'])) {
    form_set_error('owner_login', t('The email address is invalid.'));
  }
}

/**
 * Implements hook_form_validate().
 */
function fastly_setup_form_validate($form, &$form_state) {
  $api = fastly_get_api($form_state['values']['fastly_api_key'], $form_state['values']['fastly_service_id']);

  if (!$api->validate()) {
    form_set_error('', t('Invalid API key or service ID.'));
  }
}

/**
 * Implements hook_form_submit().
 */
function fastly_register_form_submit($form, &$form_state) {
  $data = array(
  	'name' => $form_state['values']['account_name'],
    'owner' => array(
  	  'name' => $form_state['values']['owner_name'],
  	  'login' => $form_state['values']['owner_login'],
  	  'password' => $form_state['values']['owner_password'],
    ),
  );

  $api = fastly_get_api();

  $account = $api->signup($data);

  if ($account) {
    $data = array(
      'name' => variable_get('site_name', t('Default')),
      'domain' => 'eexample.com', //$_SERVER['HTTP_HOST'],
      'hostname' => 'eexample.com', //$_SERVER['HTTP_HOST'],
      'address' => 'eexample.com', //$_SERVER['HTTP_HOST'],
      'ipv4' => 'eexample.com', //$_SERVER['HTTP_HOST'],
      'version' => 1,
    );

    $api->api_key = $account->raw_api_key;

    $service = $api->createService($data);

    variable_set('fastly_used_registration', 1);
    variable_set('fastly_api_key', $account->raw_api_key);
    variable_set('fastly_service_id', $service->id);

    drupal_set_message(t('Registration successful! You will receive a confirmation email very soon. Please check your inbox and verify your account by clicking the received link.'));

    drupal_goto('admin/config/services/fastly/purge');
  }
  else {
    drupal_set_message(t('An error occured while processing registration'), 'error');
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
  	    $error = (bool)drupal_set_message(t('Please input the URLs to purge.'), 'error');
  	  }
  	  else {
        foreach (explode("\n", $values) as $line) {
          $api->purgeUrl(trim($line));
    	}
  	  }
  	  break;

  	case 'keys':
  	  $values = trim($form_state['values']['keys_list']);

  	  if (empty($values)) {
  	    $error = (bool)drupal_set_message(t('Please input the keys to purge.'), 'error');
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
