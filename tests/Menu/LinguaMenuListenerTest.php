<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Tests\Menu;

use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Survos\LinguaBundle\Menu\LinguaMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class LinguaMenuListenerTest extends TestCase
{
    public function testOnlyAvailableRoutesAreLinked(): void
    {
        $routes = new RouteCollection();
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $router->expects(self::once())->method('generate')->with('lingua_sandbox')->willReturn('/_lingua/sandbox');
        $factory = new MenuFactory();
        $menu = $factory->createItem('root');
        $event = new MenuEvent($menu, $factory);
        $listener = new LinguaMenuListener($router);
        $listener->lingua($event);
        self::assertCount(0, $menu->getChildren());
        $routes->add('lingua_sandbox', new Route('/_lingua/sandbox'));
        $listener->lingua($event);
        self::assertCount(1, $menu['Lingua']->getChildren());
        self::assertSame('/_lingua/sandbox', $menu['Lingua']['Translation sandbox']->getUri());
    }
}
