<?php

namespace Drupal\fastly\Services;

use Drupal\Core\Config\ConfigFactoryInterface;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Class SlackService
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

  /**
   * Slack constructor.
   *
   * @param ConfigFactoryInterface $configFactory
   * @param ClientInterface $httpClient
   * @param LoggerInterface $logger
   */
  public function __construct(ConfigFactoryInterface $configFactory, ClientInterface $httpClient, LoggerInterface $logger) {
    $this->_config = $configFactory->get('fastly.settings');

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
    if (!in_array($type, $this->_config->get('webhook_notifications'))) {
      return false;
    }

    $text =  $message;
    $headers = [
      'Content-type: application/json'
    ];

    $body = [
      "text"  =>  $text,
      "username" => "fastly-drupal-bot",
      "icon_emoji"=> ":drupal:"
    ];


    $this->_httpClient->request("POST", $this->_config->get('webhook_url'), array ("headers" =>$headers, "json" => $body));

  }

}