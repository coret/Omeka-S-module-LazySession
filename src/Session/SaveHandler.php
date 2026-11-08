<?php

namespace LazySession\Session;

use Laminas\Session\SaveHandler\SaveHandlerInterface;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Stores anonymous sessions in an encrypted cookie, all others in the database.
 *
 * Omeka starts a session on every page request, because looking up the
 * logged-in user opens the Laminas_Auth session container. Each visitor that
 * does not return the session cookie (most crawlers) therefore costs a new
 * row in the session table, even though nobody ever reads it again.
 *
 * Those sessions are not empty, though: forms carry CSRF salts, and modules
 * such as Comment keep anti-spam state in the session. Simply not saving
 * them would break every form an anonymous visitor submits. Instead, as long
 * as a session holds nothing that must stay on the server, its data goes into
 * an encrypted cookie. A visitor that returns the cookie gets the data back;
 * a crawler that ignores it leaves nothing behind.
 *
 * A session is stored in the database instead when:
 *  - one of the configured containers holds a value (the logged-in identity,
 *    two-factor login state, ...);
 *  - its encoded data does not fit in the cookie size limit.
 * The decision is taken on every write, so a database session that no longer
 * needs the server (after logout, or one created before the module was
 * installed) moves to the cookie and its row is deleted.
 *
 * The cookie can only be set while response headers are still unsent. The
 * module's Module::closeSession() listener therefore writes the session at
 * the end of the MVC dispatch, before the response is sent. A request that
 * ends otherwise (a controller streaming a file and exiting) has already sent
 * its headers: an anonymous session is then dropped, a database session is
 * written as usual.
 */
class SaveHandler implements SaveHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * @var SessionHandlerInterface The database handler (Omeka\Session\SaveHandler\Db)
     */
    protected $inner;

    /**
     * @var CookieCodec
     */
    protected $codec;

    /**
     * @var string
     */
    protected $cookieName;

    /**
     * @var int
     */
    protected $maxCookieBytes;

    /**
     * @var string[]
     */
    protected $forceDbContainers;

    /**
     * @var int Seconds a session stays valid, from session.gc_maxlifetime
     */
    protected $lifetime = 1440;

    /**
     * @var array Session ids that live in the database, as keys
     */
    protected $databaseIds = [];

    /**
     * @var array Database rows fetched by validateId(), so read() need not query again
     */
    protected $prefetched = [];

    /**
     * @var bool Whether this request has already replaced the incoming cookie
     */
    protected $cookieReplaced = false;

    public function __construct(
        SessionHandlerInterface $inner,
        CookieCodec $codec,
        string $cookieName,
        int $maxCookieBytes,
        array $forceDbContainers
    ) {
        $this->inner = $inner;
        $this->codec = $codec;
        $this->cookieName = $cookieName;
        $this->maxCookieBytes = $maxCookieBytes;
        $this->forceDbContainers = array_values($forceDbContainers);
    }

    #[\ReturnTypeWillChange]
    public function open($savePath, $name)
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        if ($lifetime > 0) {
            $this->lifetime = $lifetime;
        }
        return $this->inner->open($savePath, $name);
    }

    #[\ReturnTypeWillChange]
    public function close()
    {
        return $this->inner->close();
    }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        $payload = $this->cookiePayload($id);
        if ($payload !== null) {
            return $payload['data'];
        }
        if (array_key_exists($id, $this->prefetched)) {
            $data = $this->prefetched[$id];
            unset($this->prefetched[$id]);
        } else {
            $data = (string) $this->inner->read($id);
        }
        if ($data !== '') {
            $this->databaseIds[$id] = true;
        }
        return $data;
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data)
    {
        if ($this->holdsServerOnlyData()) {
            return $this->writeToDatabase($id, $data);
        }

        if ($this->headersSent()) {
            // Too late for a cookie. A database session is saved as usual. An
            // anonymous cookie session that ends in a streamed response holds
            // nothing a later request depends on (no form was rendered), so
            // it is dropped rather than stored.
            return isset($this->databaseIds[$id]) ? $this->inner->write($id, $data) : true;
        }

        $value = $this->codec->encode($id, time(), (string) $data);
        if (strlen($value) > $this->maxCookieBytes) {
            return $this->writeToDatabase($id, $data);
        }

        $this->sendCookie($value, 0);
        $this->cookieReplaced = true;

        // A session that was in the database but holds nothing server-only
        // any more (one that predates this module, or whose user logged out)
        // moves to the cookie, and its row goes.
        if (isset($this->databaseIds[$id])) {
            $this->inner->destroy($id);
            unset($this->databaseIds[$id]);
        }
        return true;
    }

    /**
     * PHP calls this instead of write() when the data did not change. The
     * cookie carries its own issue time, so it is reissued all the same.
     */
    #[\ReturnTypeWillChange]
    public function updateTimestamp($id, $data)
    {
        return $this->write($id, $data);
    }

    /**
     * Used by PHP when session.use_strict_mode is on (Omeka turns it on): an
     * id the client sends is only accepted if this handler knows it.
     */
    #[\ReturnTypeWillChange]
    public function validateId($id)
    {
        if ($this->cookiePayload($id) !== null) {
            return true;
        }
        $data = (string) $this->inner->read($id);
        $this->prefetched[$id] = $data;
        return $data !== '';
    }

    /**
     * Always reports success. The database handler returns false when there
     * was no row to delete, which is the normal case for a cookie session,
     * and PHP aborts session_regenerate_id(true) (called on login) when
     * destroying the old session fails.
     */
    #[\ReturnTypeWillChange]
    public function destroy($id)
    {
        $this->inner->destroy($id);
        unset($this->databaseIds[$id], $this->prefetched[$id]);
        $this->expireCookie();
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($maxlifetime)
    {
        return $this->inner->gc($maxlifetime);
    }

    protected function writeToDatabase($id, $data)
    {
        $this->databaseIds[$id] = true;
        $this->expireCookie();
        return $this->inner->write($id, $data);
    }

    /**
     * The incoming cookie's payload, if it is genuine, belongs to this
     * session id, and has not outlived the session lifetime.
     */
    protected function cookiePayload($id): ?array
    {
        if ($this->cookieReplaced || !isset($_COOKIE[$this->cookieName])) {
            return null;
        }
        $payload = $this->codec->decode($_COOKIE[$this->cookieName]);
        if ($payload === null || !hash_equals($payload['id'], (string) $id)) {
            return null;
        }
        if ($payload['issuedAt'] + $this->lifetime < time()) {
            return null;
        }
        return $payload;
    }

    /**
     * Whether any container that must stay on the server holds a value.
     */
    protected function holdsServerOnlyData(): bool
    {
        if (!isset($_SESSION) || (!is_array($_SESSION) && !$_SESSION instanceof \ArrayAccess)) {
            return false;
        }
        foreach ($this->forceDbContainers as $name) {
            if (isset($_SESSION[$name]) && $this->hasValue($_SESSION[$name])) {
                return true;
            }
        }
        return false;
    }

    /**
     * A Laminas container is an ArrayObject; Laminas_Auth keeps a null
     * "storage" entry after logout, which does not count as a value.
     */
    protected function hasValue($container): bool
    {
        if (is_array($container) || $container instanceof \Traversable) {
            foreach ($container as $value) {
                if ($value !== null && $value !== [] && $value !== '') {
                    return true;
                }
            }
            return false;
        }
        return $container !== null;
    }

    protected function expireCookie(): void
    {
        if (!isset($_COOKIE[$this->cookieName]) && !$this->cookieReplaced) {
            return;
        }
        if ($this->headersSent()) {
            return;
        }
        $this->sendCookie('', 1);
        $this->cookieReplaced = true;
    }

    protected function sendCookie(string $value, int $expires): void
    {
        $params = session_get_cookie_params();
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie($this->cookieName, $value, [
            'expires' => $expires,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']) || $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    protected function headersSent(): bool
    {
        return headers_sent();
    }
}
