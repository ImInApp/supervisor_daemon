<?php

namespace Bozoslivehere\SupervisorDaemonBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class BozoslivehereSupervisorDaemonExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        foreach ($config as $key => $val) {
            if ($key == 'supervisor_log_path') {
                $path = rtrim($config[$key],DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                $config[$key] = $path;
            }
            $container->setParameter($this->getAlias() . '_' . $key, $config[$key]);
        }
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }

    public function getAlias()
    {
        return 'bozoslivehere_supervisor_daemon';
    }
}
