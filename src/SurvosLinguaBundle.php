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
use Survos\LinguaBundle\RemoteEvent\TranslationRemoteEventConsumer;
use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Survos\LinguaBundle\Service\ApiPlatformDataFetcher;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Service\TranslationUpdateApplier;
use Survos\LinguaBundle\Webhook\LinguaWebhookRequestParser;
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
            ->setArgument('$protocolName', $config['protocol'] ?: LinguaClient::PROTOCOL_REST)
            ->setArgument('$callbackUrl', $config['callback_url'] ?: null)
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
        foreach ([LinguaController::class, LinguaSandboxController::class] as $controllerClass) {
            $builder->autowire($controllerClass)
                ->addTag('controller.service_arguments')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Inbound translation.completed webhook. Registered only when the components are
        // installed: an app that merely pushes strings and pulls them back does not need
        // symfony/webhook, and these classes would have no parent/interface to resolve.
        //
        // A LinguaWebhookController used to sit here instead, checking an X-Api-Key by hand.
        // It was never reachable — its route was not registered, and the
        // `lingua.webhook_key` parameter it originally read had never been defined anywhere
        // either. What replaces it is FrameworkBundle's own /webhook/{name} endpoint plus the
        // parser below, which verifies an HMAC over the body rather than comparing a bearer
        // token. See survos-sites/mediary#8.
        if (class_exists(\Symfony\Component\Webhook\Client\AbstractRequestParser::class)
            && interface_exists(\Symfony\Component\RemoteEvent\Consumer\ConsumerInterface::class)
        ) {
            $builder->autowire(LinguaWebhookRequestParser::class)
                ->setAutoconfigured(true)
                // Public: framework.webhook.routing.lingua.service names this by FQCN, and
                // the WebhookController resolves it through a service locator.
                ->setPublic(true);

            $builder->autowire(TranslationUpdateApplier::class)
                ->setAutoconfigured(true);

            $builder->autowire(TranslationRemoteEventConsumer::class)
                ->setAutoconfigured(true);
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
                // scalarNode, not enumNode: an env placeholder is still an unresolved string
                // at config-build time, which an enum node rejects. LinguaClient normalises
                // the value -- anything that is not exactly "rpc" means REST -- so an empty
                // or mistyped var degrades to today's behaviour rather than failing the build.
                ->scalarNode('protocol')
                    ->info('"rest" (default) uses POST /batch-translate and /babel/pull. "rpc" uses '
                        . 'JSON-RPC at POST /api/v1, which reports rejected payloads as real errors '
                        . 'instead of {"status":"ok","response":{"error":...}} at HTTP 200. Requires a '
                        . 'lingua deployed with /api/v1; not auto-detected, because probing costs a '
                        . 'round trip and a silent fallback would hide a misconfigured server.')
                    ->defaultValue('%env(default::LINGUA_PROTOCOL)%')
                ->end()
                ->integerNode('timeout')->defaultValue(10)->end()
                ->scalarNode('proxy')
                    ->info('HTTP proxy override. Empty auto-selects the symfony proxy for a .wip host.')
                    ->defaultValue('%env(default::LINGUA_PROXY)%')
                ->end()
                ->scalarNode('callback_url')
                    ->info('Absolute URL of THIS app\'s /webhook/lingua endpoint, e.g. '
                        . 'https://zm.wip/webhook/lingua. Sent with every push so the server '
                        . 'announces translations instead of making us poll with lingua:pull. '
                        . 'Empty keeps the polling behaviour.')
                    ->defaultValue('%env(default::LINGUA_CALLBACK_URL)%')
                ->end()
                // NOTE: `webhook_key` was removed. It configured LinguaWebhookController's
                // hand-rolled X-Api-Key check, and both are gone — the inbound secret is now
                // framework.webhook.routing.lingua.secret (LINGUA_WEBHOOK_SECRET), which is
                // where symfony/webhook expects it and where it is a `#[\SensitiveParameter]`.
            ->end();
    }
}
