<?php

namespace Drupal\fastlypurger\Plugin\Purge\Purger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Api;
use Drupal\fastly\CacheTagsHash;
use Drupal\purge\Plugin\Purge\Purger\PurgerBase;
use Drupal\purge\Plugin\Purge\Purger\PurgerInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fastly purger.
 *
 * @PurgePurger(
 *   id = "fastly",
 *   label = @Translation("Fastly"),
 *   description = @Translation("Purger for Fastly."),
 *   types = {"tag", "url", "everything"},
 *   multi_instance = FALSE,
 * )
 */
class FastlyPurger extends PurgerBase implements PurgerInterface {

  /**
   * Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * The settings configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * CacheTagsHash service.
   *
   * @var \Drupal\fastly\CacheTagsHash
   */
  protected $cacheTagsHash;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('fastly.api'),
      $container->get('fastly.cache_tags.hash')
    );
  }

  /**
   * Constructs a \Drupal\Component\Plugin\FastlyPurger.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The factory for configuration objects.
   * @param \Drupal\fastly\Api $api
   *   Fastly API for Drupal.
   * @param \Drupal\fastly\CacheTagsHash $cache_tags_hash
   *   CacheTagsHash service.
   *
   * @throws \LogicException
   *   Thrown if $configuration['id'] is missing, see Purger\Service::createId.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config, Api $api, CacheTagsHash $cache_tags_hash) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->config = $config->get('fastly.settings');
    $this->api = $api;
    $this->cacheTagsHash = $cache_tags_hash;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRuntimeMeasurement() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function routeTypeToMethod($type) {
    $methods = [
      'tag'  => 'invalidateTags',
      'url'  => 'invalidateUrls',
      'everything' => 'invalidateAll',
    ];
    return isset($methods[$type]) ? $methods[$type] : 'invalidate';
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(array $invalidations) {
    throw new \LogicException('This should not execute.');
  }

  /**
   * Invalidate a set of urls.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidator instance.
   *
   * @throws \Exception
   */
  public function invalidateUrls(array $invalidations) {
    $urls = [];
    // Set all invalidation states to PROCESSING before kick off purging.
    /* @var \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface $invalidation */
    foreach ($invalidations as $invalidation) {
      $invalidation->setState(InvalidationInterface::PROCESSING);
      $urls[] = $invalidation->getExpression();
    }

    if (empty($urls)) {
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::FAILED);
        throw new \Exception('No url found to purge');
      }
    }

    // Fastly only allows purging of a single URL per request.
    $urls_each = array_chunk($urls, 1);
    foreach ($urls_each as $url) {
      // Invalidate and update the item state.
      $invalidation_state = $this->invalidateItems('urls', $url);
    }
    $this->updateState($invalidations, $invalidation_state);
  }

  /**
   * Invalidate a set of tags.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidator instance.
   *
   * @throws \Exception
   */
  public function invalidateTags(array $invalidations) {
    $tags = [];
    // Set all invalidation states to PROCESSING before kick off purging.
    /* @var \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface $invalidation */
    foreach ($invalidations as $invalidation) {
      $invalidation->setState(InvalidationInterface::PROCESSING);
      $tags[] = $invalidation->getExpression();
    }

    if (empty($tags)) {
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::FAILED);
        throw new \Exception('No tag found to purge');
      }
    }

    // Invalidate and update the item state.
    // @TODO: Does Fastly have a limit per purge we need to consider (32k)?
    // Also invalidate the cache tags as hashes, to automatically also work for
    // responses that exceed the 16 KB header limit.
    $hashes = $this->cacheTagsHash->cacheTagsToHashes($tags);
    $invalidation_state = $this->invalidateItems('tags', $hashes);
    $this->updateState($invalidations, $invalidation_state);
  }

  /**
   * Invalidate everything.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidator instance.
   */
  public function invalidateAll(array $invalidations) {
    $this->updateState($invalidations, InvalidationInterface::PROCESSING);
    // Invalidate and update the item state.
    $invalidation_state = $this->invalidateItems();
    $this->updateState($invalidations, $invalidation_state);
  }

  /**
   * Invalidate Fastly cache.
   *
   * @param mixed $type
   *   Type to purge like tags/url. If null, will purge everything.
   * @param string[] $invalidates
   *   A list of items to invalidate.
   *
   * @return int
   *   Returns invalidate items.
   */
  protected function invalidateItems($type = NULL, array $invalidates = []) {
    try {
      if ($type === 'tags') {
        $purged = $this->api->purgeKeys($invalidates);
      }
      elseif ($type === 'urls') {
        // $invalidates should be an array with one URL.
        foreach ($invalidates as $invalidate) {
          $purged = $this->api->purgeUrl($invalidate);
        }
      }
      else {
        $purged = $this->api->purgeAll();
      }
      if ($purged) {
        return InvalidationInterface::SUCCEEDED;
      }
      return InvalidationInterface::FAILED;
    }
    catch (\Exception $e) {
      return InvalidationInterface::FAILED;
    }
    finally {
      // @TODO: Check/increment API limits - https://docs.fastly.com/api/#rate-limiting.
    }
  }

  /**
   * Update the invalidation state of items.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The invalidator instance.
   * @param int $invalidation_state
   *   The invalidation state.
   */
  protected function updateState(array $invalidations, $invalidation_state) {
    // Update the state.
    foreach ($invalidations as $invalidation) {
      $invalidation->setState($invalidation_state);
    }
  }

}
