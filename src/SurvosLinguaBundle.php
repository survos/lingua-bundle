<?php
declare(strict_types=1);

namespace Survos\LinguaBundle;

use Survos\LinguaBundle\Command\LinguaDemoCommand;
use Survos\LinguaBundle\Command\LinguaPullBabelCommand;
use Survos\LinguaBundle\Command\LinguaPushBabelCommand;
use Survos\LinguaBundle\Command\LinguaStatusCommand;
use Survos\LinguaBundle\Command\LinguaSyncBabelCommand;
use Survos\LinguaBundle\Controller\LinguaController;
use Survos\LinguaBundle\Controller\LinguaSandboxController;
use Survos\LinguaBundle\Controller\LinguaWebhookController;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Twig\Extension\LinguaExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SurvosLinguaBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Client service
        $builder->autowire(LinguaClient::class)
            ->setAutoconfigured(true)
            ->setArgument('$config', $config)
            ->setPublic(true);

        // Controllers
        foreach ([LinguaController::class, LinguaSandboxController::class, LinguaWebhookController::class] as $controllerClass) {
            $builder->autowire($controllerClass)
                ->addTag('controller.service_arguments')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Commands
        foreach ([LinguaDemoCommand::class,
                     LinguaPushBabelCommand::class, LinguaPullBabelCommand::class, LinguaSyncBabelCommand::class] as $commandClass) {
            $builder->register($commandClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true)
                ->addTag('console.command');
        }

        // Twig extension
        $builder->autowire(LinguaExtension::class)
            ->setAutowired(true)
            ->setAutoconfigured(true) // adds twig.extension tag automatically
            ->setPublic(false);
    }

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        // Import attribute routes from this bundle’s Controller directory
        $routes->import(__DIR__.'/Controller/', 'attribute');
        // e.g., to prefix: ->prefix('/_lingua')
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('server')->defaultValue('%env(default::LINGUA_BASE_URI)%')->end()
                ->scalarNode('api_key')->defaultValue('%env(default::LINGUA_API_KEY)%')->end()
                ->integerNode('timeout')->defaultValue(10)->end()
            ->end();
    }
}
