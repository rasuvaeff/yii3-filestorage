<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The signer reads the clock only to check expiry, so "later" is expressed by
 * building a signer at a later moment rather than by mutating a clock.
 */
$signerAt = static function (string $moment, SigningKeyRing $keys): HmacUrlSigner {
    return new HmacUrlSigner(new StaticClock(new DateTimeImmutable($moment)), $keys);
};

$august = new SigningKeyRing(activeKeyId: '2026-08', keys: ['2026-08' => str_repeat('a', 32)]);
$signer = $signerAt('2026-08-06T12:00:00+00:00', $august);

$payload = new SignedPayload(fileId: '019b76da-a800-7366-abcb-3dc475ad3339', variant: 'thumb');
$expiresAt = new DateTimeImmutable('2026-08-06T13:00:00+00:00');
$token = $signer->sign($payload, $expiresAt);

echo "Token:\n  {$token}\n\n";
echo "It decomposes as v1.<key-id>.<expires>.<payload>.<hmac>, and everything\n";
echo "before the signature is covered by it.\n\n";

$verified = $signer->verify($token);
echo 'verify() -> fileId=' . $verified?->fileId . ' variant=' . var_export($verified?->variant, true) . "\n\n";

// --- Tampering ----------------------------------------------------------

echo "Tampering with any segment fails verification:\n";
[$version, $keyId, $expires, $encodedPayload, $signature] = explode('.', $token);

$forgedPayload = rtrim(strtr(base64_encode(
    (new SignedPayload(fileId: '019b76da-a800-7366-abcb-3dc475ad3339'))->toCanonicalJson(),
), '+/', '-_'), '=');

$tampered = [
    'variant swapped to the original' => "{$version}.{$keyId}.{$expires}.{$forgedPayload}.{$signature}",
    'expiry pushed a year out' => $version . '.' . $keyId . '.' . ($expires + 31_536_000) . ".{$encodedPayload}.{$signature}",
    'signature altered' => "{$version}.{$keyId}.{$expires}.{$encodedPayload}." . strrev($signature),
    'unknown key id' => "{$version}.someoneelse.{$expires}.{$encodedPayload}.{$signature}",
];
foreach ($tampered as $label => $forged) {
    printf("  %-32s verify() = %s\n", $label, var_export($signer->verify($forged), true));
}

echo "\nThe first one matters most: the variant is inside the signature, so a\n";
echo "token minted for a thumbnail cannot be replayed for the original — which\n";
echo "is the difference between a smaller image and a redacted one.\n\n";

// --- Expiry -------------------------------------------------------------

$later = $signerAt('2026-08-06T13:00:01+00:00', $august);
echo 'One hour and one second later, verify() = ' . var_export($later->verify($token), true) . "\n\n";

// --- Rotation -----------------------------------------------------------

echo "Rotation: a new active key, the old one kept for verification.\n";

$september = new SigningKeyRing(
    activeKeyId: '2026-09',
    keys: [
        '2026-09' => str_repeat('b', 32),
        '2026-08' => str_repeat('a', 32),  // keep for at least the maximum URL TTL
    ],
);

$oldToken = $signerAt('2026-09-01T12:00:00+00:00', $august)
    ->sign($payload, new DateTimeImmutable('2026-09-01T13:00:00+00:00'));

$rotated = $signerAt('2026-09-01T12:00:00+00:00', $september);

echo '  URL signed with the old key still verifies: '
    . var_export($rotated->verify($oldToken)?->fileId, true) . "\n";
echo '  New URLs use the new key: '
    . explode('.', $rotated->sign($payload, new DateTimeImmutable('2026-09-01T13:00:00+00:00')))[1] . "\n";

$retired = $signerAt('2026-09-01T12:00:00+00:00', new SigningKeyRing(
    activeKeyId: '2026-09',
    keys: ['2026-09' => str_repeat('b', 32)],
));
echo '  Once the old key is dropped, its URLs stop working: '
    . var_export($retired->verify($oldToken), true) . "\n\n";

// --- Weak keys ----------------------------------------------------------

try {
    new SigningKeyRing(activeKeyId: 'weak', keys: ['weak' => 'hunter2']);
} catch (InvalidConfigException $e) {
    echo "A short secret is a configuration error, not a weaker-but-working setup:\n";
    echo "  {$e->getMessage()}\n";
}
