<?php

namespace Survos\LinguaBundle;

use Survos\LinguaBundle\Command\LinguaDemoCommand;
use Survos\LinguaBundle\Controller\LinguaWebhookController;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Controller\LinguaController;
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
            ->scalarNode('server')->defaultValue("%env(default::LINGUA_BASE_URI)%")->end()
            ->scalarNode('api_key')->defaultValue('%env(default::LINGUA_API_KEY)%')->end()
		    ->end();
	}


	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
        $builder->autowire(LinguaClient::class)
            ->setAutoconfigured(true)
            ->addArgument('$config', $config)
            ->setPublic(true);

		$services = $container->services();

        $services->set(LinguaClient::class)
            ->autowire()
        ;

        dd(LinguaWebhookController::class, class_exists(LinguaWebhookController::class));
        foreach ([LinguaController::class, LinguaWebhookController::class] as $class) {
            $builder->autowire($class)
                ->setAutoconfigured(true)
                ->setPublic(true)
                ->addTag('controller.service_arguments');
        }

            $builder->autowire(LinguaDemoCommand::class)
                ->setAutoconfigured(true)
                ->addArgument('$config', $config)
                ->setPublic(true)
                ->addTag('console.command');
	}
}
