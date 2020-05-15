<?php

namespace Drupal\fastly;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class CacheTagsHash.
 *
 * @package Drupal\fastly
 */
class CacheTagsHash implements CacheTagsHashInterface {

  /**
   * Fastly settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * CacheTagsHash constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('fastly.settings');
  }

  /**
   * Maps cache tags to hashes.
   *
   * Used when the Surrogate-Key/X-Drupal-Cache-Tags header size otherwise
   * exceeds 16 KB.
   *
   * @param string[] $cache_tags
   *   The cache tags in the header.
   *
   * @return string[]
   *   The hashes to use instead in the header.
   */
  public function cacheTagsToHashes(array $cache_tags) {
    $hashes = [];
    $siteId = getenv('FASTLY_SITE_ID') ?? $this->config->get('site_id');
    $siteId = $siteId ?? self::FASTLY_DEFAULT_SITE_ID;
    $cache_tags_length = getenv('FASTLY_CACHE_TAG_HASH_LENGTH') ?? $this->config->get('cache_tag_hash_length');
    $cache_tags_length = $cache_tags_length ?? self::CACHE_TAG_HASH_LENGTH;

    // Adding site id hash as standalone hash to every header
    $hashes[] = self::hashInput($siteId, $cache_tags_length);

    foreach ($cache_tags as $cache_tag) {
      $cache_tag = $siteId ? $siteId . ':' . $cache_tag : $cache_tag;
      $hashes[] = self::hashInput($cache_tag, $cache_tags_length);
    }
    return $hashes;
  }

  /**
   * Create a hash with the given input and length.
   *
   * @param string $input
   *   The input string to be hashed.
   * @param int $length
   *   The length of the hash.
   *
   * @return string
   *   Cryptographic hash with the given length.
   */
  protected static function hashInput($input, $length) {
    //@todo Improve logic for smarter hashing algorithm.
    return substr(md5($input), 0, $length);
  }

}
