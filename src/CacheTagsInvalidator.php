<?php

namespace Drupal\fastly;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\fastly\EventSubscriber\SurrogateKeyGenerator;

/**
 * Cache tags invalidator implementation that invalidates Fastly.
 */
class CacheTagsInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * Constructs a CacheTagsInvalidator object.
   *
   * @param \Drupal\fastly\Api $api
   *   The Fastly API.
   */
  public function __construct(Api $api) {
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    // When either an extension (module/theme) is (un)installed, purge
    // everything.
    if (in_array('config:core.extension', $tags)) {
      $this->api->purgeAll();
      return;
    }

    // Also invalidate the cache tags as hashes, to automatically also work for
    // responses that exceed the 16 KB header limit.
    $all_tags_and_hashes = SurrogateKeyGenerator::cacheTagsToHashes($tags);
    $this->api->purgeKeys($all_tags_and_hashes);
  }

}
