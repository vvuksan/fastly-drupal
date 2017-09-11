<?php

namespace Drupal\fastly\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Webhook
 *
 * @package Drupal\fastly\Services
 */
class Webhook
{

  protected $config;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * @var ClientInterface
   */
  protected $httpClient;


  protected $webhookConnectTimeout;

  /**
   * Webhook constructor.
   *
   * @param ConfigFactoryInterface $configFactory
   * @param ClientInterface $httpClient
   * @param LoggerInterface $logger
   */
  public function __construct(ConfigFactoryInterface $configFactory, ClientInterface $httpClient, LoggerInterface $logger, $webhookConnectTimeout) {
    $this->config = $configFactory->get('fastly.settings');
    $this->webhookConnectTimeout = $webhookConnectTimeout;
    $this->httpClient = $httpClient;
    $this->logger = $logger;
  }

  /**
   * Sends request to WebHookURL
   *
   * @param $message
   * @param $type
   * @return mixed
   */
  public function sendWebHook($message, $type) {
    if (!in_array($type, $this->config->get('webhook_notifications')) || !$this->config->get('webhook_enabled')) {
      return false;
    }

    $text =  $message;
    $headers = [
      'Content-type: application/json'
    ];

    $body = [
      "text"  =>  $text,
      "username" => "fastly-drupal-bot",
      "icon_emoji"=> ":airplane:"
    ];


    $this->httpClient->request("POST", $this->config->get('webhook_url'),
      array ("headers" =>$headers, "connect_timeout" => $this->webhookConnectTimeout, "json" => $body));

  }

}
