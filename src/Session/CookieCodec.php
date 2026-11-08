<?php

namespace LazySession\Session;

use InvalidArgumentException;
use SodiumException;

/**
 * Turns session data into a cookie value and back.
 *
 * The value is authenticated encryption (libsodium secretbox, XSalsa20 +
 * Poly1305) of the session id, the time it was issued and the serialized
 * session data. A visitor can neither read nor alter it; anything that fails
 * to decrypt is treated as no cookie at all.
 *
 * The session id is inside the encrypted payload, so a value only unlocks the
 * session it was issued for.
 */
class CookieCodec
{
    /**
     * Format marker, so a later format can be told apart from this one.
     */
    const PREFIX = 'v1.';

    /**
     * Refuse to inflate more than this. Session data that large never fits in
     * a cookie in the first place, so a bigger result means a forged value.
     */
    const MAX_INFLATED_BYTES = 65536;

    /**
     * @var string
     */
    protected $key;

    /**
     * @param string $key Raw 32-byte secretbox key
     */
    public function __construct($key)
    {
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'LazySession needs a %d-byte key.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ));
        }
        $this->key = $key;
    }

    /**
     * @return string A cookie-safe value (URL-safe base64 behind a prefix)
     */
    public function encode(string $id, int $issuedAt, string $data): string
    {
        $plain = pack('Nn', $issuedAt, strlen($id)) . $id . $data;
        $packed = gzdeflate($plain, 6);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($packed, $nonce, $this->key);
        return self::PREFIX . sodium_bin2base64($nonce . $box, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * @return array|null ['id' => string, 'issuedAt' => int, 'data' => string],
     *   or null for anything that is not a value this key produced
     */
    public function decode($value): ?array
    {
        if (!is_string($value) || strpos($value, self::PREFIX) !== 0) {
            return null;
        }
        try {
            $raw = sodium_base642bin(substr($value, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException $e) {
            return null;
        }
        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $packed = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        } catch (SodiumException $e) {
            return null;
        }
        if ($packed === false) {
            return null;
        }
        $plain = @gzinflate($packed, self::MAX_INFLATED_BYTES);
        if ($plain === false || strlen($plain) < 6) {
            return null;
        }
        $header = unpack('NissuedAt/nidLength', substr($plain, 0, 6));
        $id = substr($plain, 6, $header['idLength']);
        if (strlen($id) !== $header['idLength']) {
            return null;
        }
        return [
            'id' => $id,
            'issuedAt' => $header['issuedAt'],
            'data' => (string) substr($plain, 6 + $header['idLength']),
        ];
    }
}
