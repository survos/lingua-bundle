<?php
declare(strict_types=1);

namespace Survos\LinguaBundle;

use Survos\LinguaBundle\Command\LinguaDemoCommand;
use Survos\LinguaBundle\Command\LinguaHarvestCommand;
use Survos\LinguaBundle\Command\LinguaPullBabelCommand;
use Survos\LinguaBundle\Command\LinguaPushBabelCommand;
use Survos\LinguaBundle\Command\LinguaStatusCommand;
use Survos\LinguaBundle\Command\LinguaSyncBabelCommand;
use Survos\LinguaBundle\Controller\LinguaController;
use Survos\LinguaBundle\Controller\LinguaSandboxController;
use Survos\LinguaBundle\Controller\LinguaWebhookController;
use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Survos\LinguaBundle\Service\ApiPlatformDataFetcher;
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
        // Configuration is resolved HERE, once, and handed to services as explicit typed
        // arguments. Services do not read env vars: no #[Autowire('%env(...)%')] in a
        // constructor, and no opaque $config array for a service to fish keys out of at call
        // time. Reading configuration is the extension's job -- a service should be handed
        // values it can rely on.
        $apiKey = $config['api_key'] ?: null;

        // Client service
        $builder->autowire(LinguaClient::class)
            ->setAutoconfigured(true)
            ->setArgument('$server', $config['server'])
            ->setArgument('$apiKey', $apiKey)
            ->setArgument('$timeoutSeconds', $config['timeout'])
            ->setArgument('$proxyUrl', $config['proxy'] ?: null)
            ->setPublic(true);

        // Shared-secret check for the server side. The same bundle is installed on lingua and
        // on every app that calls it, so one setting -- survos_lingua.api_key, from
        // LINGUA_API_KEY -- is both the key clients send and the key lingua validates.
        $builder->autowire(LinguaKeyGuard::class)
            ->setAutoconfigured(true)
            ->setArgument('$expectedKey', $apiKey)
            ->setPublic(true);

        foreach ([ApiPlatformDataFetcher::class] as $class) {
            $builder->autowire($class)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Controllers
        foreach ([LinguaController::class, LinguaSandboxController::class, LinguaWebhookController::class] as $controllerClass) {
            $builder->autowire($controllerClass)
                ->addTag('controller.service_arguments')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        $builder->getDefinition(LinguaWebhookController::class)
            ->setArgument('$webhookKey', $config['webhook_key'] ?: null);

        // Commands
        foreach ([LinguaDemoCommand::class,
                     LinguaPushBabelCommand::class, LinguaPullBabelCommand::class, LinguaSyncBabelCommand::class] as $commandClass) {
            $builder->register($commandClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true)
                ->addTag('console.command');
        }

        // Two commands (harvestRoutes, harvestMessages), one class -- they share the
        // same upsert-into-babel logic and differ only in where the strings come from.
        $builder->register(LinguaHarvestCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('console.command', ['method' => 'harvestRoutes'])
            ->addTag('console.command', ['method' => 'harvestMessages']);

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
                ->scalarNode('server')
                    ->info('Base URI of the lingua server. Empty falls back to ' . LinguaClient::DEFAULT_SERVER . '.')
                    ->defaultValue('%env(default::LINGUA_BASE_URI)%')
                ->end()
                ->scalarNode('api_key')
                    ->info('Shared secret for the lingua API -- NOT babel/LibreTranslate, which needs no key. '
                        . 'The same value belongs on lingua itself and on every app that calls it: clients send '
                        . 'it, lingua validates it. Empty disables the check (current behaviour).')
                    ->defaultValue('%env(default::LINGUA_API_KEY)%')
                ->end()
                ->integerNode('timeout')->defaultValue(10)->end()
                ->scalarNode('proxy')
                    ->info('HTTP proxy override. Empty auto-selects the symfony proxy for a .wip host.')
                    ->defaultValue('%env(default::LINGUA_PROXY)%')
                ->end()
                ->scalarNode('webhook_key')
                    ->info('Optional separate secret for the inbound /_lingua/webhook endpoint. '
                        . 'Empty disables the check.')
                    ->defaultValue('%env(default::LINGUA_WEBHOOK_KEY)%')
                ->end()
            ->end();
    }
}
