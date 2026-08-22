<?php

declare(strict_types=1);

namespace Nubit\Platform\Identity;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * Written out rather than pulled in: the algorithm is thirty lines of HMAC and
 * a base32 codec, and a dependency here would sit on the authentication path of
 * every application built on the stack. The parts that are easy to get wrong —
 * the drift window, the constant-time comparison, and refusing to accept the
 * same code twice — are the parts a library would not decide for us anyway.
 */
final class Totp
{
    /** Six digits, thirty-second steps: what every authenticator app assumes. */
    public const int DIGITS = 6;
    public const int PERIOD = 30;

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct() {}

    /** A fresh base32 secret. 160 bits, the size RFC 4226 recommends for HMAC-SHA1. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Verifies a code against the current time.
     *
     * `$window` steps on either side are accepted, because a phone's clock and
     * a server's clock are never quite the same and a user typing a six-digit
     * code takes a few seconds. One step — thirty seconds each way — is the
     * usual compromise; widening it multiplies the codes an attacker may guess.
     *
     * @return int|null the matched time step, or null. The step is returned so
     *                  the caller can refuse to accept it a second time
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $at = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $currentStep = intdiv($at ?? time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; ++$offset) {
            $step = $currentStep + $offset;

            // hash_equals, not ===: a plain comparison leaks how many leading
            // digits were right through its timing.
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        // Dynamic truncation, RFC 4226 §5.4.
        $offset = ord($hash[19]) & 0x0F;
        $binary =
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /** The `otpauth://` URI an authenticator app reads from a QR code. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        // The alphabet is indexed through str_split rather than by offset:
        // five bits can only address 0..31, but the analyzer cannot see that
        // from bindec's signature, and an assertion here would be noise.
        $alphabet = str_split(self::ALPHABET);

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[(int) bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret));

        $bits = '';
        foreach (str_split($normalized) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if (false === $index) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === strlen($chunk)) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
