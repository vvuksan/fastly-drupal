<?php

/**
 * @file
 * fastly.rules_defaults.inc
 * Provides default rules to purge Fastly cache.
 */

/**
 * Implements hook_default_rules_configuration().
 * 
 * @return
 *   An array of rules configurations with the configuration names as keys.
 */
function fastly_default_rules_configuration() {
  // Define four reaction rules. These are initially purge Fastly cache for listed entity pages 
  // and front page when node, comment, user profile or taxonomy term is created, updated or
  // deleted but the user can modify the values and add more URL(s) to be cleared as well as 
  // add a new rule which implements Purge by URL(s) action.
  
  // 1. Reaction rule to purge Fastly cache when a comment is created, updated or deleted.
  // Purges comment page, relevant node page, and front page.
  $configs['rules_fastly_purge_comment'] = rules_import('{ "rules_fastly_purge_comment" : {
      "LABEL" : "Purge Fastly cache when a comment is created, updated or deleted.",
      "PLUGIN" : "reaction rule",
      "OWNER" : "rules",
      "TAGS" : [ "fastly" ],
      "REQUIRES" : [ "rules", "fastly", "comment" ],
      "ON" : { "comment_update" : [], "comment_delete" : [], "comment_insert" : [] },
      "DO" : [
        { "fastly_rules_action_purge_by_urls" : { "urls" : "comment\/[comment:cid]\r\nnode\/[comment:node:nid]\r\n[site:url]" } }
      ]
    }
  }');
  
  // 2. Reaction rule to purge Fastly cache when a node is created, updated or deleted.
  // Purges node page and front page.
  $configs['rules_fastly_purge_node'] = rules_import('{ "rules_fastly_purge_node" : {
      "LABEL" : "Purge Fastly cache when a node is created, updated or deleted.",
      "PLUGIN" : "reaction rule",
      "OWNER" : "rules",
      "TAGS" : [ "fastly" ],
      "REQUIRES" : [ "rules", "fastly" ],
      "ON" : { "node_update" : [], "node_insert" : [], "node_delete" : [] },
      "DO" : [
        { "fastly_rules_action_purge_by_urls" : { "urls" : "node\/[node:nid]\r\n\u003Cfront\u003E" } }
      ]
    }
  }');
  
  // 3. Reaction rule to purge Fastly cache when user account is created, updated or deleted.
  // Purges user profile page and front page.
  $configs['rules_fastly_purge_user_profile'] = rules_import('{ "rules_fastly_purge_user_profile" : {
      "LABEL" : "Purge Fastly cache when user profile is created, updated or deleted.",
      "PLUGIN" : "reaction rule",
      "OWNER" : "rules",
      "TAGS" : [ "fastly" ],
      "REQUIRES" : [ "rules", "fastly" ],
      "ON" : { "user_update" : [], "user_insert" : [], "user_delete" : [] },
      "DO" : [
        { "fastly_rules_action_purge_by_urls" : { "urls" : "user\/[account:uid]\r\n\u003Cfront\u003E" } }
      ]
    }
  }');
  
  // 4. Reaction rule to purge Fastly cache when taxonomy term is updated or deleted.
  // Purges taxonomy term page.
  $configs['rules_fastly_purge_taxonomy_term'] = rules_import('{ "rules_fastly_purge_taxonomy_term" : {
      "LABEL" : "Purge Fastly cache when taxonomy term is updated or deleted.",
      "PLUGIN" : "reaction rule",
      "OWNER" : "rules",
      "TAGS" : [ "fastly" ],
      "REQUIRES" : [ "rules", "fastly", "taxonomy" ],
      "ON" : { "taxonomy_term_update" : [], "taxonomy_term_delete" : [] },
      "DO" : [
        { "fastly_rules_action_purge_by_urls" : { "urls" : "taxonomy\/term\/[term:tid]\r\n[term:url]" } }
      ]
    }
  }');

  return $configs;
}