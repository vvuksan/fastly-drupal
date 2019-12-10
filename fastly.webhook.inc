<?php

/**
 * Class Webhook.
 */
class Webhook {

  /**
   * Sends request to WebHookURL
   *
   * @param $message
   * @param $type
   *
   * @return mixed
   */
  public function sendMessage($message, $type, $action = null) {
    global $base_url;
    $headers = array(
      'Content-type: application/json',
    );

    $data = json_encode(
      array("text"  => $base_url . " // " . $type . ': ' . $message,
            "username" => "fastly-drupal-bot",
            "icon_emoji" => ":airplane:",
      ));
      $options =array("method" => "POST",
                      "data" => $data,
                      "connect_timeout" => 15,
                      "headers" =>$headers
      );

    $webhookUrl = variable_get('fastly_webhook_url');
    $webhookActions = variable_get('fastly_webhook_events', array());


    if(in_array($action, $webhookActions)) {
      drupal_http_request($webhookUrl, $options);
    }
  }
}
