<?php

/**
 * Checks the module's wiring and the save handler's decisions.
 *
 * It deliberately avoids booting Omeka: it loads Omeka's autoloader (for the
 * Laminas interfaces), reads the module's configuration, and drives the save
 * handler against an in-memory stand-in for the database handler. That keeps
 * the test runnable from a shell, in release.sh, and on a machine where the
 * database is not reachable.
 *
 *   php test/verify-wiring.php
 *
 * Set OMEKA_PATH if Omeka is not the grandparent directory of this module.
 */

use LazySession\Session\CookieCodec;
use LazySession\Session\ExpiredContainers;
use LazySession\Session\SaveHandler;

$moduleDir = dirname(__DIR__);

/**
 * Find the Omeka installation this module belongs to: two directories up, an
 * installation beside the (possibly symlinked) modules directory, or
 * OMEKA_PATH.
 */
function findOmeka($moduleDir)
{
    $candidates = [];
    if ($fromEnv = getenv('OMEKA_PATH')) {
        $candidates[] = $fromEnv;
    }
    $candidates[] = dirname($moduleDir, 2);
    $siblings = glob(dirname($moduleDir, 2) . '/omeka-s*', GLOB_ONLYDIR) ?: [];
    rsort($siblings);
    $candidates = array_merge($candidates, $siblings);

    $usable = [];
    foreach ($candidates as $candidate) {
        if (file_exists($candidate . '/vendor/autoload.php')
            && file_exists($candidate . '/application/Module.php')
        ) {
            if (realpath($candidate . '/modules') === dirname($moduleDir)) {
                return $candidate;
            }
            $usable[] = $candidate;
        }
    }
    return $usable ? reset($usable) : null;
}

$omekaPath = findOmeka($moduleDir);
if (null === $omekaPath) {
    fwrite(STDERR, "Cannot find an Omeka installation for the Laminas classes. Set OMEKA_PATH.\n");
    exit(2);
}
require $omekaPath . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($moduleDir) {
    $prefix = 'LazySession\\';
    if (strpos($class, $prefix) === 0) {
        $file = $moduleDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

$failures = 0;
function check($description, $condition)
{
    global $failures;
    printf("%s %s\n", $condition ? 'ok  ' : 'FAIL', $description);
    if (!$condition) {
        $failures++;
    }
}

/**
 * In-memory stand-in for Omeka\Session\SaveHandler\Db.
 */
class MemoryHandler implements SessionHandlerInterface
{
    public $rows = [];
    public $reads = 0;
    public $writes = 0;

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        $this->reads++;
        return $this->rows[$id] ?? '';
    }

    public function write($id, $data): bool
    {
        $this->writes++;
        $this->rows[$id] = $data;
        return true;
    }

    public function destroy($id): bool
    {
        $existed = isset($this->rows[$id]);
        unset($this->rows[$id]);
        return $existed;
    }

    public function gc($max): int|false
    {
        return 0;
    }
}

/**
 * The real handler, with cookie output and headers_sent() made observable.
 */
class TestHandler extends SaveHandler
{
    public $cookies = [];
    public $sent = false;

    protected function sendCookie(string $value, int $expires): void
    {
        $this->cookies[] = ['value' => $value, 'expires' => $expires];
    }

    protected function headersSent(): bool
    {
        return $this->sent;
    }
}

$config = include $moduleDir . '/config/module.config.php';
$options = $config['lazysession'];
$key = sodium_crypto_secretbox_keygen();
$codec = new CookieCodec($key);

function handler(MemoryHandler $db, $maxBytes = null)
{
    global $codec, $options;
    $handler = new TestHandler($db, $codec, $options['cookie_name'], $maxBytes ?? $options['max_cookie_bytes'], $options['force_db_containers']);
    $handler->open('', 'test');
    return $handler;
}

function reset_request(array $cookies = [], array $session = [])
{
    $_COOKIE = $cookies;
    $_SESSION = $session;
}

// --- Configuration -------------------------------------------------------

$ini = parse_ini_file($moduleDir . '/config/module.ini', true);
check('module.ini parses, with an [info] section and a version', isset($ini['info']['version']));
check('module.ini name matches the module directory and namespace', ($ini['info']['name'] ?? '') === 'LazySession');
check('Omeka is told to use the module save handler', ($config['session']['save_handler'] ?? null) === SaveHandler::class);
check('the save handler has a factory', isset($config['service_manager']['factories'][SaveHandler::class]));
check('the factory class exists', class_exists($config['service_manager']['factories'][SaveHandler::class]));
check('Laminas_Auth always forces a database session', in_array('Laminas_Auth', $options['force_db_containers'], true));

// --- Cookie codec --------------------------------------------------------

$sessionData = '__Laminas|a:1:{s:20:"_REQUEST_ACCESS_TIME";d:1.5;}Comment|a:1:{s:8:"pow_salt";s:4:"abcd";}';
$value = $codec->encode('abc123', 1700000000, $sessionData);
$decoded = $codec->decode($value);
check('codec round-trips id, issue time and data', $decoded === ['id' => 'abc123', 'issuedAt' => 1700000000, 'data' => $sessionData]);
check('codec output is cookie-safe', (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value));
$tampered = substr($value, 0, -2) . (substr($value, -2, 1) === 'A' ? 'B' : 'A') . substr($value, -1);
check('a tampered value is rejected', $codec->decode($tampered) === null);
check('a value made with another key is rejected', (new CookieCodec(sodium_crypto_secretbox_keygen()))->decode($value) === null);
check('garbage is rejected', $codec->decode('v1.not-base64!') === null && $codec->decode('') === null && $codec->decode(null) === null);
$threw = false;
try {
    new CookieCodec('short');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a key of the wrong length is refused', $threw);

// --- Anonymous visitor ---------------------------------------------------

reset_request();
$db = new MemoryHandler;
$h = handler($db);
check('a request without cookies reads an empty session', $h->read('sid1') === '');
$h->write('sid1', $sessionData);
check('an anonymous session is not written to the database', $db->writes === 0 && $db->rows === []);
check('an anonymous session is sent as a session cookie', count($h->cookies) === 1 && $h->cookies[0]['expires'] === 0);
$issued = $h->cookies[0]['value'];

reset_request([$options['cookie_name'] => $issued]);
$db = new MemoryHandler;
$h = handler($db);
check('strict mode accepts the id the cookie was issued for', $h->validateId('sid1') === true);
check('the next request gets the data back from the cookie', $h->read('sid1') === $sessionData);
check('without touching the database', $db->reads === 0);

reset_request([$options['cookie_name'] => $issued]);
$db = new MemoryHandler;
$h = handler($db);
check('the cookie does not unlock another session id', $h->validateId('sid2') === false && $h->read('sid2') === '');

reset_request([$options['cookie_name'] => $codec->encode('sid1', time() - 100000, $sessionData)]);
$h = handler(new MemoryHandler);
check('a cookie older than session.gc_maxlifetime is ignored', $h->read('sid1') === '');

// --- Sessions that must stay on the server -------------------------------

reset_request([$options['cookie_name'] => $issued], ['Laminas_Auth' => new ArrayObject(['storage' => 7])]);
$db = new MemoryHandler;
$h = handler($db);
$h->read('sid1');
$h->write('sid1', 'Laminas_Auth|...');
check('a logged-in session is written to the database', isset($db->rows['sid1']));
check('and its cookie is expired', count($h->cookies) === 1 && $h->cookies[0]['expires'] === 1);

reset_request([], ['Laminas_Auth' => new ArrayObject(['storage' => null])]);
$db = new MemoryHandler;
$h = handler($db);
$h->read('sid3');
$h->write('sid3', $sessionData);
check('an empty Laminas_Auth container (after logout) does not force the database', $db->rows === [] && count($h->cookies) === 1);

reset_request([], ['TwoFactorTotp' => new ArrayObject(['userId' => 3])]);
$db = new MemoryHandler;
$h = handler($db);
$h->read('sid4');
$h->write('sid4', 'x');
check('two-factor login state is written to the database', isset($db->rows['sid4']));

reset_request();
$db = new MemoryHandler;
$db->rows['sid5'] = 'existing';
$h = handler($db);
check('strict mode accepts a session that exists in the database', $h->validateId('sid5') === true);
check('a database session is read from the database once', $h->read('sid5') === 'existing' && $db->reads === 1);
$h->write('sid5', 'changed');
check('an anonymous database session moves to the cookie', count($h->cookies) === 1 && $h->cookies[0]['expires'] === 0);
check('and its row is deleted', !isset($db->rows['sid5']));

reset_request([], ['Laminas_Auth' => new ArrayObject(['storage' => 7])]);
$db = new MemoryHandler;
$db->rows['sid9'] = 'existing';
$h = handler($db);
$h->read('sid9');
$h->write('sid9', 'changed');
check('a logged-in database session stays in the database', $db->rows['sid9'] === 'changed' && $h->cookies === []);

reset_request();
$db = new MemoryHandler;
$h = handler($db, 200);
$h->read('sid6');
$h->write('sid6', bin2hex(random_bytes(400)));
check('a session too large for the cookie falls back to the database', isset($db->rows['sid6']) && $h->cookies === []);

// --- Requests that already sent their headers ----------------------------

reset_request();
$db = new MemoryHandler;
$h = handler($db);
$h->sent = true;
$h->read('sid7');
$h->write('sid7', $sessionData);
check('an anonymous session ending in a streamed response is dropped', $db->rows === [] && $h->cookies === []);

reset_request();
$db = new MemoryHandler;
$db->rows['sid8'] = 'existing';
$h = handler($db);
$h->sent = true;
$h->read('sid8');
$h->write('sid8', 'changed');
check('a database session is still written after the headers were sent', $db->rows['sid8'] === 'changed');

// --- Destroy, regenerate -------------------------------------------------

reset_request([$options['cookie_name'] => $issued]);
$db = new MemoryHandler;
$h = handler($db);
check('destroying a cookie session reports success (session_regenerate_id needs it)', $h->destroy('sid1') === true);
check('and expires the cookie', count($h->cookies) === 1 && $h->cookies[0]['expires'] === 1);
check('after which the old cookie no longer counts in this request', $h->read('sid1') === '');

// --- Expired containers --------------------------------------------------

$session = [
    '__Laminas' => [
        '_REQUEST_ACCESS_TIME' => 1.5,
        'Laminas_Validator_Csrf_salt_csrf_1' => ['EXPIRE' => 100],
        'Laminas_Validator_Csrf_salt_csrf_2' => ['EXPIRE' => 300],
    ],
    'Laminas_Validator_Csrf_salt_csrf_1' => new ArrayObject(['hash' => 'a']),
    'Laminas_Validator_Csrf_salt_csrf_2' => new ArrayObject(['hash' => 'b']),
    'Comment' => new ArrayObject(['pow_salt' => 'c']),
];
$removed = ExpiredContainers::prune($session, 200);
check('expired containers are removed with their metadata', $removed === ['Laminas_Validator_Csrf_salt_csrf_1']
    && !isset($session['Laminas_Validator_Csrf_salt_csrf_1'])
    && !isset($session['__Laminas']['Laminas_Validator_Csrf_salt_csrf_1']));
check('live containers and other metadata are kept', isset($session['Laminas_Validator_Csrf_salt_csrf_2'], $session['Comment'])
    && $session['__Laminas']['_REQUEST_ACCESS_TIME'] === 1.5);

printf("\n%s\n", $failures ? "$failures check(s) failed." : 'All checks passed.');
exit($failures ? 1 : 0);
