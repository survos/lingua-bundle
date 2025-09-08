<?php

namespace Survos\SurvosLinguaBundle;

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
            ->scalarNode('server')->defaultValue('%env(default:https://translation-server.survos.com:SURVOS_LINGUA_SERVER)%')->end()
            ->scalarNode('api_key')->defaultValue('%env(default::SURVOS_LINGUA_API_KEY)%')->end()
		    ->end();
	}


	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$services = $container->services();

		        $services->set(LinguaService::class)
		            ->arg('$config', $config)
		            ->public();

		        $services->set(LinguaController::class)
		            ->tag('controller.service_arguments');

		        $services->set(LinguaCommand::class)
		            ->tag('console.command');
	}
}
