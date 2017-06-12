<?php

namespace Drupal\fastlypurger;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the Fastly fastly.cache_tags.invalidator service.
 */
class FastlypurgerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('fastly.cache_tags.invalidator');
    $definition->clearTag('cache_tags_invalidator');
  }

}
