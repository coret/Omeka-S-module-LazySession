<?php

namespace LazySession\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use LazySession\Module;
use LazySession\Session\CookieCodec;
use LazySession\Session\SaveHandler;
use Omeka\Session\SaveHandler\Db;

class SaveHandlerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config')['lazysession'] ?? [];
        return new SaveHandler(
            new Db($services->get('Omeka\Connection')),
            new CookieCodec(Module::loadKey($services->get('Omeka\Settings'))),
            (string) ($config['cookie_name'] ?? 'lazysession'),
            (int) ($config['max_cookie_bytes'] ?? 3500),
            (array) ($config['force_db_containers'] ?? ['Laminas_Auth'])
        );
    }
}
