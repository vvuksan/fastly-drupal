<?php
namespace Drupal\fastly\Utility;

/**
 * Class FastlyEdgeModulesHelper.
 *
 * @package Drupal\fastly\Utility
 */
class FastlyEdgeModulesHelper {

  const FASTLY_EDGE_MODULE_PREFIX = 'edgemodule_';

  /**
   * Get all edge modules.
   *
   * @return array
   */
  public static function getModules()
  {

    return [
      'blackfire_integration' => [
        'description' => t('Necessary Fastly configuration to enable Blackfire profiling.'),
        'id' => 'blackfire_integration',
        'name' => t('Blackfire integration'),
        'vcl' => [
          [
            'template' => "blackfire_integration",
            'priority' => 70,
            'type' => 'recv'
          ],
        ],
      ],
      'cors_headers' => [
        'description' => t('Set CORS headers'),
        'id' => 'cors_headers',
        'name' => t('CORS headers'),
        'vcl' => [
          [
            'template' => "cors_headers",
            'type' => 'deliver'
          ]
        ],
      ],
      'countryblock' => [
        'description' => t('Block requests from a set of countries.'),
        'id' => 'countryblock',
        'name' => t('Country block'),
        'vcl' => [
          [
            'template' => "countryblock",
            'type' => 'recv'
          ],
          [
            'template' => "countryblock_error",
            'type' => 'error'
          ]
        ],
      ],
      'datadome_integration' => [
        'description' => t('Set of VCLs required to integrate Datadome services. Please note for full functionality Fastly support needs to enable proper handling of POST requests. Do not enable unless this has been done.'),
        'id' => 'datadome_integration',
        'name' => t('DataDome Bot Detection integration'),
        'vcl' => [
          [
            'template' => "datadome_integration_deliver",
            'type' => 'deliver'
          ],
          [
            'template' => "datadome_integration_error",
            'type' => 'error'
          ],
          [
            'template' => "datadome_integration_fetch",
            'type' => 'fetch'
          ],
          [
            'template' => "datadome_integration_init",
            'type' => 'init'
          ],
          [
            'template' => "datadome_integration_miss",
            'type' => 'miss'
          ],
          [
            'template' => "datadome_integration_pass",
            'type' => 'pass'
          ],
          [
            'template' => "datadome_integration_recv",
            'type' => 'recv'
          ]
        ],
      ],
      'force_cache_miss_on_hard_reload_for_admins' => [
        'description' => t('Force cache miss for users on allowlist. Invoke it on your browser by pressing CMD/CTRL + SHIFT + R or SHIFT + F5 depending on your browser. It only affects your own session. It will not affected already cached content.'),
        'id' => 'force_cache_miss_on_hard_reload_for_admins',
        'name' => t('Hard Reload cache bypass for set of admin IPs'),
        'vcl'=> [
          [
            'template' => 'force_cache_miss_on_hard_reload_for_admins_recv',
            'type' => 'recv',
          ],
          [
            'template' => 'force_cache_miss_on_hard_reload_for_admins_hash',
            'type' => 'hash',
          ]
        ]
      ],
      'increase_timeouts_long_jobs' => [
        'description' => t('For selected requests, override default backend timeout. Often used for long running jobs that take over 1 minute. Please note these paths will no longer be cached. Fastly imposes hard limit 10 minute timeout.'),
        'id' => 'increase_timeouts_long_jobs',
        'name' => t('Increase timeouts for long running jobs'),
        'vcl'=> [
          [
            'template' => 'increase_timeouts_long_jobs_recv',
            'type' => 'recv',
            'priority' => 80
          ],
          [
            'template' => 'increase_timeouts_long_jobs_pass',
            'type' => 'pass',
          ]
        ]
      ],
      'mobile_device_detection' => [
        'description' => t('By default Fastly caches a single version of a page ignoring device type e.g. mobile/desktop. This module adds Vary-ing by a device type. It supports iPhone, Android and Tizen mobile device detection. It will cache separate page versions for mobile and desktop'),
        'id' => 'mobile_device_detection',
        'name' => t('Mobile Theme support'),
        'vcl'=> [
          [
            'priority'=> 45,
            'type'=> 'recv',
            'template'=> 'mobile_device_detection_recv'
          ],
          [
            'priority'=> 70,
            'type'=> 'fetch',
            'template'=> 'mobile_device_detection_fetch'
          ],
          [
            'priority'=> 70,
            'type'=> 'deliver',
            'template'=> 'mobile_device_detection_deliver'
          ]
        ]
      ],
      'netacea_integration' => [
        'description' => t('Set of VCLs required to integrate Netacea services. Please note for full functionality Fastly support needs to enable proper handling of POST requests. Do not enable unless this has been done.'),
        'id' => 'netacea_integration',
        'name' => t('Netacea Bot Detection integration'),
        'vcl'=> [
          [
            "priority"=> 45,
            "template"=> "netacea_integration_recv",
            "type"=> "recv"
          ],
          [
            "priority"=> 45,
            "template"=> "netacea_integration_deliver",
            "type"=> "deliver"
          ],
          [
            "priority"=> 45,
            "template"=> "netacea_integration_init",
            "type"=> "init"
          ]
        ],
      ],
      'disable_cache' => [
        'description' => t('For selected requests, disable caching, either on Fastly, or in the browser, or both.'),
        'id' => 'disable_cache',
        'name' => t('Disable caching'),
        'vcl'=> [
          [
            'template' => 'disable_cache_recv',
            'type' => 'recv'
          ],
          [
            'template' => 'disable_cache_deliver',
            'type' => 'deliver'
          ]
        ]
      ],
      'other_cms_integration' => [
        'description' => t('This edge module is intended to integrate other CMSes/backend into your site e.g. 3rd party blog/shop etc. Sometimes referred to as domain masking.'),
        'id' => 'other_cms_integration',
        'name' => t('Other CMS/backend integration'),
        'vcl'=> [
          [
            'template' => 'other_cms_integration_recv',
            'priority' => 70,
            'type' => 'recv'
          ],
          [
            'template' => 'other_cms_integration_miss',
            'type' => 'miss'
          ],
          [
            'template' => 'other_cms_integration_pass',
            'type' => 'pass'
          ]
        ]

      ],
      'redirect_hosts' => [
        'description' => t('Set up domain/host redirects (301) e.g. domain.com => www.domain.com'),
        'id' => 'redirect_hosts',
        'name' => t('Redirect one domain to another'),
        'vcl'=> [
          [
            'template' => 'redirect_hosts',
            'priority' => 4,
            'type' => 'recv'
          ]
        ]

      ],
      'url_rewrites' => [
        'description' => t('Rewrite URL path to point to the correct URL on the backend. NOT a redirect.'),
        'id' => 'url_rewrites',
        'name' => t('URL rewrites'),
        'vcl'=> [
          [
            'template' => 'url_rewrites_miss',
            'type' => 'miss'
          ],
          [
            'template' => 'url_rewrites_pass',
            'type' => 'pass'
          ]
        ]
      ]
    ];
  }
}
