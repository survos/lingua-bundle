<?php

namespace Survos\SurvosLinguaBundle;

use Survos\LinguaBundle\Command\LinguaDemoCommand;
use Survos\LinguaBundle\Controller\LinguaWebhookController;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\SurvosLinguaBundle\Command\LinguaCommand;
use Survos\SurvosLinguaBundle\Controller\LinguaController;
use Survos\SurvosLinguaBundle\Service\LinguaService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosLinguaBundle extends AbstractBundle
{
	public function configure(DefinitionConfigurator $definition): void
	{
		$definition->rootNode()
		    ->children()
            ->scalarNode('server')->defaultValue('%env(default:https://translation-server.survos.com:LINGUA_BASE_URI)%')->end()
            ->scalarNode('api_key')->defaultValue('%env(default::LINGUA_API_KEY)%')->end()
		    ->end();
	}


	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$services = $container->services();

        $services->set(LinguaClient::class)
            ->autowire()
            ->autoconfigure()
            ->arg('$config', $config)
            ->public();

        foreach ([LinguaController::class, LinguaWebhookController::class] as $class) {
            $services->set(LinguaController::class)
                ->autoconfigure(true)
                ->autowire(true)
                ->public()
                ->tag('controller.service_arguments');
        }
		        $services->set(LinguaDemoCommand::class)
                    ->autoconfigure(true)
                    ->autowire(true)
                    ->public()
		            ->tag('console.command');
	}
}
