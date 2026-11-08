<?php

namespace LazySession\Session;

/**
 * Removes session containers whose expiry time has passed.
 *
 * Laminas records a container's expiry in the "__Laminas" metadata and only
 * discards the container when something opens it again. CSRF salts are kept
 * that way: a visitor who views twenty item pages carries twenty salts, most
 * of them long expired, which would push the cookie past its size limit.
 * Dropping them before the session is written changes nothing Laminas would
 * not do itself.
 */
class ExpiredContainers
{
    /**
     * @param array|\ArrayAccess $session Usually $_SESSION
     * @return string[] Names of the removed containers
     */
    public static function prune(&$session, ?int $now = null): array
    {
        $now = $now ?? time();
        if (!isset($session['__Laminas']) || !is_array($session['__Laminas'])) {
            return [];
        }
        $metadata = $session['__Laminas'];
        $removed = [];
        foreach ($metadata as $name => $entry) {
            if (!is_array($entry) || !isset($entry['EXPIRE']) || !is_int($entry['EXPIRE'])) {
                continue;
            }
            if ($entry['EXPIRE'] >= $now) {
                continue;
            }
            unset($session[$name], $metadata[$name]);
            $removed[] = $name;
        }
        if ($removed) {
            $session['__Laminas'] = $metadata;
        }
        return $removed;
    }
}
