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
   * CacheTagsHash service.
   *
   * @var \Drupal\fastly\CacheTagsHash
   */
  protected $cacheTagsHash;

  /**
   * Constructs a CacheTagsInvalidator object.
   *
   * @param \Drupal\fastly\Api $api
   *   The Fastly API.
   * @param \Drupal\fastly\CacheTagsHash $cache_tags_hash
   *   CacheTagsHash service.
   */
  public function __construct(Api $api, CacheTagsHash $cache_tags_hash) {
    $this->api = $api;
    $this->cacheTagsHash = $cache_tags_hash;
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
    // Ignore config:fastly.settings.
    if (in_array('config:fastly.settings', $tags)) {
      return;
    }

    // Also invalidate the cache tags as hashes, to automatically also work for
    // responses that exceed the 16 KB header limit.
    $all_tags_and_hashes = $this->cacheTagsHash->cacheTagsToHashes($tags);
    $this->api->purgeKeys($all_tags_and_hashes);
  }

}
