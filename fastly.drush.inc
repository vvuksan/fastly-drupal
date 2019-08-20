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
      'tags' => 'An comma-separated list of cache tags or hashes to purge, or leave empty to purge all.',
    ],
    'drupal dependencies' => ['fastly'],
    'aliases' => ['fastly:purge'],
  ];
  $items['fastly-purge-url'] = [
    'description' => 'Purge URL from fastly caches.',
    'arguments' => [
      'url' => 'A full URL to purge from Fastly',
    ],
    'drupal dependencies' => ['fastly'],
    'aliases' => ['fastly:purge-url'],
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
    $api->purgeKeys($cache_tags);
  }
}

/**
 * Call back function to purge a single URL at Fastly from drush.
 */
function drush_fastly_purge_url($url = '') {
  $api = Drupal::service('fastly.api');
  if (!empty($url)) {
    $api->purgeUrl($url);
  }
}
