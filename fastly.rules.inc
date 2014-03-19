<?php

/**
 * @file
 * Integration with the Rules module.
 */

/**
 * Implements hook_rules_action_info().
 */
function fastly_rules_action_info() {
  $actions = array();

  $actions['fastly_rules_action_purge'] = array(
    'label' => t('Purge the page.'),
    'group' => t('Fastly'),
  );

  $actions['fastly_rules_action_purge_by_urls'] = array(
    'label' => t('Purge by URL(s).'),
    'group' => t('Fastly'),
    'parameter' => array(
      'urls' => array(
        'type' => 'text',
        'label' => t('Absolute URL or internal path of page to clear'),
        'description' => t('Paste one or more URLs to purge. Each in new line. Examples: http://example.com, http://example.com/node/1, user/1, &lt;front&gt;.'),
      ),
    ),
  );

  return $actions;
}

/**
 * Rules action to purge the URL after the content is updated.
 */
function fastly_rules_action_purge() {
  $node = menu_get_object();
  $api = fastly_get_api();
  $api->purgePath('node/' . $node->nid);
}

/**
 * Rules action to purge the specified URL(s).
 *
 * @param $urls
 *   Array with user-defined URLs and internal paths.
 */
function fastly_rules_action_purge_by_urls($urls) {
  $api = fastly_get_api();
  foreach (explode("\r\n", $urls) as $line) {
    $api->purgePath(trim($line));
  }
}
