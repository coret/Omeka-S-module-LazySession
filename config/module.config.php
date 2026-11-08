<?php

namespace LazySession;

return [
    // Omeka core reads this key in Omeka\Mvc\MvcListeners::bootstrapSession()
    // and fetches the named service instead of building its own database save
    // handler. Because module configuration is only merged for active modules,
    // deactivating LazySession restores the core behaviour on the next request.
    'session' => [
        'save_handler' => Session\SaveHandler::class,
    ],
    'service_manager' => [
        'factories' => [
            Session\SaveHandler::class => Service\SaveHandlerFactory::class,
        ],
    ],
    'lazysession' => [
        // Name of the cookie that carries an anonymous visitor's session data.
        'cookie_name' => 'lazysession',

        // Upper bound for the encoded cookie value, in bytes. Browsers accept
        // about 4096 bytes per cookie, name and attributes included. A session
        // that does not fit is stored in the database instead.
        'max_cookie_bytes' => 3500,

        // Session containers that must never leave the server. When any of
        // them holds a value, the session is stored in the database: the
        // logged-in identity, and the pending-login and challenge state of the
        // TwoFactorTotp module. Add the container names of other modules that
        // keep security-sensitive state in the session.
        'force_db_containers' => [
            'Laminas_Auth',
            'TwoFactorTotp',
            'TwoFactorTotpChallenge',
            'TwoFactorTotpPasswordConfirm',
        ],
    ],
];
