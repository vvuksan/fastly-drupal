<?php

/**
 * @file
 * Provides drush commands for Fastly related operations.
 */

/**
 * Implements hook_drush_command().
 */
function fastly_drush_command() {
  $items = [];
  $items['fastly-purge-all'] = [
    'description' => 'Purge all fastly caches.',
    'arguments' => [
      'tags' => 'An comma-separated list of cache tags to purge, or leave empty to purge all.',
    ],
    'drupal dependencies' => ['fastly'],
    'aliases' => ['fastly:purge'],
  ];
  return $items;
}

/**
 * Call back function to purge Fastly caches from drush.
 */
function drush_fastly_purge_all($tags = '') {
  $api = Drupal::service('fastly.api');
  if (empty($tags)) {
    $api->purgeAll();
  }
  else {
    $cache_tags = explode(',', $tags);
    if (!empty($cache_tags)) {
      foreach ($cache_tags as $tag) {
        $api->purgeKey(trim($tag));
      }
    }
  }
}
