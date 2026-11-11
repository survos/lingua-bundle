<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Symfony\Component\Routing\RouterInterface;

final readonly class LinguaMenuListener
{
    public function __construct(private RouterInterface $router)
    {
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function lingua(MenuEvent $event): void
    {
        $routes = $this->router->getRouteCollection();
        $links = array_filter([
            'lingua_index' => 'Overview',
            'lingua_sandbox' => 'Translation sandbox',
        ], fn (string $route): bool => null !== $routes->get($route), ARRAY_FILTER_USE_KEY);
        if ([] === $links) {
            return;
        }
        $menu = $event->getMenu()->addChild('Lingua')
            ->setExtra('icon', 'tabler:language')
            ->setExtra('translation_domain', false);
        foreach ($links as $route => $label) {
            $menu->addChild($label, ['uri' => $this->router->generate($route)])
                ->setExtra('translation_domain', false);
        }
    }
}
