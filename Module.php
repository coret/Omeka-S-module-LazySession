<?php

namespace LazySession;

use LazySession\Session\ExpiredContainers;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Settings\SettingsInterface;

/**
 * Keeps anonymous visitors out of the session table.
 *
 * The work is done by Session\SaveHandler, which Omeka core picks up through
 * the "session.save_handler" configuration key. This class creates the
 * encryption key and makes sure the session is written while a cookie can
 * still be sent.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Module extends AbstractModule
{
    /**
     * Setting that holds the base64-encoded secretbox key.
     */
    const KEY_SETTING = 'lazysession_key';

    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function install(ServiceLocatorInterface $services)
    {
        self::loadKey($services->get('Omeka\Settings'));
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Settings')->delete(self::KEY_SETTING);
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        // SendResponseListener sends the response at priority -10000; writing
        // the session before that is what lets the save handler set its cookie.
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_FINISH,
            [$this, 'closeSession'],
            100
        );
    }

    /**
     * Write and close the session before the response goes out.
     *
     * Anything that changes the session after this point is not saved. Within
     * the MVC cycle that is only the response being sent.
     */
    public function closeSession(MvcEvent $event)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        ExpiredContainers::prune($_SESSION);
        session_write_close();
    }

    /**
     * Return the raw encryption key, creating and storing one if there is none.
     *
     * Replacing the key (deleting the setting) invalidates every anonymous
     * session cookie at once; logged-in sessions are unaffected.
     */
    public static function loadKey(SettingsInterface $settings): string
    {
        $encoded = $settings->get(self::KEY_SETTING);
        if (is_string($encoded)) {
            $key = base64_decode($encoded, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }
        $key = sodium_crypto_secretbox_keygen();
        $settings->set(self::KEY_SETTING, base64_encode($key));
        return $key;
    }
}
