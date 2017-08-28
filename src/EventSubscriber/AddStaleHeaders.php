<?php

namespace Drupal\fastly\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Form\FastlySettingsForm;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds stale headers
 *
 * @see https://docs.fastly.com/guides/purging/soft-purges
 */
class AddStaleHeaders implements EventSubscriberInterface {

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a new CacheTagsHeaderLimitDetector object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->logger = $logger;
    $this->config = $config_factory;
  }

  /**
   * Adds Surrogate-Control header if soft purging is enabled.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function onRespond(FilterResponseEvent $event) {
    // Get the fastly settings from configuration.
    $config = $this->config->get('fastly.settings');

    // Only modify the master request.
    if ((!$event->isMasterRequest())) {
      return;
    }

    // Get response.
    $response = $event->getResponse();

    // Build the Surrogate-Control header.
    $cache_control_header = $response->headers->get('Cache-Control');
    $surrogate_control_header = $cache_control_header;
    if ((bool) $config->get('stale_while_revalidate')) {
      $surrogate_control_header = $surrogate_control_header . ', stale-while-revalidate=' . $config->get('stale_while_revalidate_value');
    }

    if ((bool) $config->get('stale_if_error')) {
      $surrogate_control_header .= ', stale-if-error=' . $config->get('stale_if_error_value');
    }

    // Set the modified Cache-Control header.
    $response->headers->set('Surrogate-Control', $surrogate_control_header);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onRespond'];
    return $events;
  }

}
