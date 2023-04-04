<?php

namespace Drupal\fastly\EventSubscriber;

use Drupal\fastly\CacheTagsHash;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Generates a 'Surrogate-Key' header in the format expected by Fastly.
 *
 * @see https://docs.fastly.com/guides/purging/getting-started-with-surrogate-keys
 */
class SurrogateKeyGenerator implements EventSubscriberInterface {

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * CacheTagsHash service.
   *
   * @var \Drupal\fastly\CacheTagsHash
   */
  protected $cacheTagsHash;

  /**
   * Constructs a new CacheTagsHeaderLimitDetector object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param \Drupal\fastly\CacheTagsHash $cache_tags_hash
   *   The Fastly logger channel.
   *
   */
  public function __construct(LoggerInterface $logger, CacheTagsHash $cache_tags_hash) {
    $this->logger = $logger;
    $this->cacheTagsHash = $cache_tags_hash;
  }

  /**
   * Logs an emergency event when the X-Drupal-Cache-Tags header exceeds 16 KB.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function onRespond(ResponseEvent $event) {
    if (!$event->isMasterRequest()) {
      return;
    }

    $response = $event->getResponse();

    if (method_exists($response, 'getCacheableMetadata')) {
      $surrogate_key_header_value = implode(' ', $response->getCacheableMetadata()->getCacheTags());

      $cache_tags = explode(' ', $surrogate_key_header_value);
      $hashes = $this->cacheTagsHash->cacheTagsToHashes($cache_tags);
      $siteId = $this->cacheTagsHash->getSiteId();
      $siteIdHash = $this->cacheTagsHash->hashInput($siteId);
      $hashes[] = $siteIdHash;
      $surrogate_key_header_value = implode(' ', $hashes);

      $response->headers->set('Surrogate-Key', $surrogate_key_header_value);
    }
  }

  /**
   * Maps cache tags to hashes.
   *
   * Used when the Surrogate-Key/X-Drupal-Cache-Tags header size otherwise
   * exceeds 16 KB.
   *
   * @deprecated Deprecated and will be removed in future versions. Use
   *   \Drupal::service('fastly.cache_tags.hash')->cacheTagsToHashes($cache_tags); instead.
   *
   * @param string[] $cache_tags
   *   The cache tags in the header.
   *
   * @return string[]
   *   The hashes to use instead in the header.
   */
  public static function cacheTagsToHashes(array $cache_tags) {
    return \Drupal::service('fastly.cache_tags.hash')->cacheTagsToHashes($cache_tags);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onRespond'];
    return $events;
  }

}
