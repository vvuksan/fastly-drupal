<?php

namespace Drupal\fastly\Commands;

use Drupal\fastly\Api;
use Drupal\fastly\CacheTagsHash;
use Drush\Commands\DrushCommands;

/**
 * Provides drush commands for Fastly.
 */
class FastlyCommands extends DrushCommands {

  /**
   * Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * @var \Drupal\fastly\CacheTagsHash
   */
  protected $cacheTagsHash;

  /**
   * Construct the FastlyCommands object.
   *
   * @param \Drupal\fastly\Api $api
   *   The Fastly API service.
   * @param \Drupal\fastly\CacheTagsHash $cache_tags_hash
   *   CacheTagsHash service.
   */
  public function __construct(Api $api, CacheTagsHash $cache_tags_hash) {
    $this->api = $api;
    $this->cacheTagsHash = $cache_tags_hash;
  }

  /**
   * Purge whole service.
   *
   * @command fastly:purge:all
   * @aliases fpall
   */
  public function purgeAll() {
    if ($this->api->purgeAll()) {
      $this->output()->writeln("<info>Successfully purged all on Fastly.</info>");
    }
    else {
      $this->output()->writeln("<error>Unable to purge all on Fastly.</error>");
    }
  }

  /**
   * Purge cache by Url.
   *
   * @param string $url
   *   A full URL to purge.
   *
   * @command fastly:purge:url
   * @aliases fpurl
   */
  public function purgeUrl($url = '') {
    if (empty($url)) {
      return;
    }
    if ($this->api->purgeUrl($url)) {
      $this->output()->writeln("<info>Successfully purged url on Fastly.</info>");
    }
    else {
      $this->output()->writeln("<error>Unable to purge url on Fastly.</error>");
    }
  }

  /**
   * Purge cache by key.
   *
   * @param string $keys
   *   A comma-separated list of keys to purge.
   *
   * @command fastly:purge:key
   * @aliases fpkey
   */
  public function purgeKeys($keys = '') {
    if (empty($keys)) {
      return;
    }
    $keys = explode(',', $keys);
    $hashes = $this->cacheTagsHash->cacheTagsToHashes($keys);
    if ($this->api->purgeKeys($hashes)) {
      $this->output()->writeln("<info>Successfully purged key(s) on Fastly.</info>");
    }
    else {
      $this->output()->writeln("<error>Unable to purged key(s) on Fastly.</error>");
    }
  }

}
