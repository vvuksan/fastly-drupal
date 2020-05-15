<?php


namespace Drupal\fastly;


interface CacheTagsHashInterface {

  /**
   * Default Cache tag hash length.
   */
  const CACHE_TAG_HASH_LENGTH = 3;

  /**
   * Default Fastly Site ID.
   */
  const FASTLY_DEFAULT_SITE_ID = 'site1';
}
