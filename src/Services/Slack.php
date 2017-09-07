<?php

namespace Drupal\fastly\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Slack
 *
 * @package Drupal\fastly\Services
 */
class Slack
{

  protected $_config;

  /**
   * @var LoggerInterface
   */
  protected $_logger;

  /**
   * @var ClientInterface
   */
  protected $_httpClient;


  protected $slackConnectTimeout;

  /**
   * Slack constructor.
   *
   * @param ConfigFactoryInterface $configFactory
   * @param ClientInterface $httpClient
   * @param LoggerInterface $logger
   */
  public function __construct(ConfigFactoryInterface $configFactory, ClientInterface $httpClient, LoggerInterface $logger, $slackConnectTimeout) {
    $this->_config = $configFactory->get('fastly.settings');
    $this->slackConnectTimeout = $slackConnectTimeout;
    $this->_httpClient = $httpClient;
    $this->_logger = $logger;
  }

  /**
   * Sends request to WebHookURL
   *
   * @param $message
   * @param $type
   * @return mixed
   */
  public function sendWebHook($message, $type) {
    if (!in_array($type, $this->_config->get('webhook_notifications')) || !$this->_config->get('webhook_enabled')) {
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


    $this->_httpClient->request("POST", $this->_config->get('webhook_url'),
      array ("headers" =>$headers, "connect_timeout" => $this->slackConnectTimeout, "json" => $body));

  }

}
