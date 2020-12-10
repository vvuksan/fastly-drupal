<?php

namespace Drupal\fastly;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class CacheTagsHash.
 *
 * @package Drupal\fastly
 */
class CacheTagsHash implements CacheTagsHashInterface {

  /**
   * ConfigFactory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
    $this->configFactory = $config_factory;
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
    $siteId = $this->getSiteId();

    foreach ($cache_tags as $cache_tag) {
      $cache_tag = $siteId ? $siteId . ':' . $cache_tag : $cache_tag;
      $hashes[] = $this->hashInput($cache_tag);
    }
    return $hashes;
  }

  /**
   * Create a hash with the given input and length.
   *
   * @param string $input
   *   The input string to be hashed.
   *
   * @return string
   *   Cryptographic hash with the given length.
   */
  public function hashInput($input) {
    $cache_tags_length = getenv('FASTLY_CACHE_TAG_HASH_LENGTH') ?: $this->config->get('cache_tag_hash_length');
    $cache_tags_length = $cache_tags_length ?: self::CACHE_TAG_HASH_LENGTH;
    return substr(base64_encode(md5($input, true)), 0, $cache_tags_length);
  }

  /**
   * Get site id.
   *
   * @return array|false|mixed|string
   */
  public function getSiteId() {
    $siteId = getenv('FASTLY_SITE_ID') ?: $this->config->get('site_id');
    if (!$siteId) {
      // Create random 8 character string and save it to config.
      $random = new Random();
      $siteId = $random->name();
      $siteId = mb_strtolower($siteId);
      if ($this->configFactory->get('fastly.settings')) {
        $config = $this->configFactory->getEditable('fastly.settings');
        $config->set('site_id', $siteId)
          ->save(TRUE);
      }
    }
    return $siteId;
  }

}
