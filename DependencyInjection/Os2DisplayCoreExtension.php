<?php

namespace Os2Display\CoreBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see
 * {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class Os2DisplayCoreExtension extends Os2DisplayBaseExtension {

  /**
   * {@inheritdoc}
   */
  public function load(array $configs, ContainerBuilder $container) {
    $this->dir = __DIR__;

    parent::load($configs, $container);

    $configuration = new Configuration();
    $config = $this->processConfiguration($configuration, $configs);
    $def = $container->getDefinition('os2display.middleware.service');

    if (isset($config['cache_ttl'])) {
        $def->replaceArgument(3, $config['cache_ttl']);
    }

    if (isset($config['zencoder'])) {
      if (isset($config['zencoder']['outputs'])) {
        $def = $container->getDefinition('sonata.media.provider.zencoder');
        $def->replaceArgument(0, $config['zencoder']['outputs']);
      }
      $container->setParameter('os2_display_core.zencoder.remove_original', $config['zencoder']['remove_original'] ?? false);
    }
  }
}
