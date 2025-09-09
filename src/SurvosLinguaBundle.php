<?php

namespace Survos\LinguaBundle;

use Survos\LinguaBundle\Command\LinguaDemoCommand;
use Survos\LinguaBundle\Controller\LinguaSandboxController;
use Survos\LinguaBundle\Controller\LinguaWebhookController;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Controller\LinguaController;
use Survos\PixieBundle\Controller\PixieController;
use Survos\PixieBundle\Controller\PixieDashboardController;
use Survos\PixieBundle\Controller\PixiePrinterController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class SurvosLinguaBundle extends AbstractBundle
{

	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
        $builder->autowire(LinguaClient::class)
            ->setAutoconfigured(true)
            ->setArgument('$config', $config)
            ->setPublic(true);

//        assert(class_exists(LinguaWebhookController::class));
        foreach ([LinguaController::class,
                     LinguaSandboxController::class,
                     LinguaWebhookController::class] as $controllerClass) {
                // Controllers
                $builder->autowire($controllerClass)
                    ->addTag('controller.service_arguments')
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->setPublic(true);

            }

            $builder->register(LinguaDemoCommand::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true)
                ->addTag('console.command');
	}

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        dd($routes);
        // Import attribute routes from this bundle’s Controller directory
        $routes->import(__DIR__.'/Controller/', 'attribute');
        // If you want a common prefix: ->prefix('/_lingua')
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('server')->defaultValue("%env(default::LINGUA_BASE_URI)%")->end()
            ->scalarNode('api_key')->defaultValue('%env(default::LINGUA_API_KEY)%')->end()
            ->integerNode('timeout')->defaultValue(10)->end()
            ->end();
    }


}
