<?php

namespace Drupal\fastly\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Webhook.
 *
 * @package Drupal\fastly\Services
 */
class Webhook {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Connect timeout.
   *
   * @var string
   */
  protected $webhookConnectTimeout;

  /**
   * Webhook constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param string $webhookConnectTimeout
   *   The timeout for webhook connections.
   */
  public function __construct(ConfigFactoryInterface $configFactory, ClientInterface $httpClient, LoggerInterface $logger, $webhookConnectTimeout) {
    $this->config = $configFactory->get('fastly.settings');
    $this->httpClient = $httpClient;
    $this->logger = $logger;
    $this->webhookConnectTimeout = $webhookConnectTimeout;
  }

  /**
   * Sends request to WebHookURL.
   *
   * @param string $message
   *   Webhook message, pass through t() or SafeMarkup::format first.
   * @param string $type
   *   Webhook type.
   *
   * @return mixed
   *   FALSE if webhook notification are disabled or an unsupported type.
   */
  public function sendWebHook($message, $type) {
    if (!$this->config->get('webhook_enabled') || !in_array($type, $this->config->get('webhook_notifications'))) {
      return FALSE;
    }

    $text = $message;

    $headers = [
      'Content-type: application/json',
    ];

    $body = [
      "text"  => $text,
      "username" => "fastly-drupal-bot",
      "icon_emoji" => ":airplane:",
    ];

    $option = [
      "headers" => $headers,
      "connect_timeout" => $this->webhookConnectTimeout,
      "json" => $body,
    ];

    // @TODO: handle exceptions.
    $this->httpClient->request("POST", $this->config->get('webhook_url'), $option);

  }

}
