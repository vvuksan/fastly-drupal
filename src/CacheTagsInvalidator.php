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
   * @var \Drupal\Fastly\Api
   */
  protected $fastlyApi;

  /**
   * Constructs a CacheTagsInvalidator object.
   *
   * @param \Drupal\Fastly\Api $fastly_api
   *   The Fastly API.
   */
  public function __construct(Api $fastly_api) {
    $this->fastlyApi = $fastly_api;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    // When either an extension (module/theme) is (un)installed, purge
    // everything.
    if (in_array('config:core.extension', $tags)) {
      $this->fastlyApi->purgeAll();
      return;
    }

    $this->fastlyApi->purgeKeys($tags);

    // Also invalidate the cache tags as hashes, to automatically also work for
    // responses that exceed the 16 KB header limit.
    $hashes = SurrogateKeyGenerator::cacheTagsToHashes($tags);
    $this->fastlyApi->purgeKeys($hashes);
  }

}
