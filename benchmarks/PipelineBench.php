<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Benchmarks;

use DateInterval;
use finfo;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Testo\Bench;

/**
 * The CPU-bound steps this package adds around an upload or a download.
 *
 * Storage itself is I/O bound, so the store is in memory here: what is being
 * measured is the work around the write, not the disk. Each benchmark is
 * compared against the cheapest thing that could possibly do the job, so the
 * cost of a guarantee is visible rather than assumed.
 *
 * @internal
 */
final class PipelineBench
{
    private static ?Storage $storage = null;
    private static ?HmacUrlSigner $signer = null;
    private static ?Psr17Factory $factory = null;
    private static ?string $token = null;
    private static ?ExtensionMap $extensions = null;

    /**
     * A representative upload of roughly 100 KiB — big enough that reading it
     * whole and reading a bounded prefix are not the same thing.
     */
    private const int BODY_REPEATS = 4_452;

    /**
     * The full ingress path — sniff, policy, path, write, metadata — against a
     * bare store write. The gap is what the guarantees cost per upload.
     */
    #[Bench(
        callables: ['bare write' => [self::class, 'bareWrite']],
        calls: 200,
        iterations: 10,
    )]
    public static function add(): File
    {
        return self::storage()->add(self::upload());
    }

    public static function bareWrite(): string
    {
        return self::upload()->stream()->getContents();
    }

    /**
     * The bounded sniff window against reading the whole object. This is the
     * comparison that justifies the window: detection must not scale with the
     * size of the file.
     */
    #[Bench(
        callables: ['whole body' => [self::class, 'detectFromWholeBody']],
        calls: 2_000,
        iterations: 10,
    )]
    public static function detectMediaType(): ?string
    {
        return (new FinfoMimeTypeDetector())->detect(self::upload());
    }

    public static function detectFromWholeBody(): string|false
    {
        return (new finfo(FILEINFO_MIME_TYPE))->buffer(self::upload()->stream()->getContents());
    }

    /**
     * The `symfony/mime` table against a hand-written array. If the difference
     * were large it would be an argument for the hand-written table — it is
     * not, which is why the table nobody has to maintain wins.
     */
    #[Bench(
        callables: ['hand-written array' => [self::class, 'handWrittenLookup']],
        calls: 100_000,
        iterations: 10,
    )]
    public static function resolveExtension(): string
    {
        return self::extensions()->extensionFor('image/jpeg');
    }

    public static function handWrittenLookup(): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png']['image/jpeg'] ?? 'bin';
    }

    /**
     * 128 bits from `random_bytes()` against `uniqid()`. Predictable keys are
     * how somebody guesses the path of a file that is not theirs, and the
     * measurement says that safety is not what makes an upload slow.
     */
    #[Bench(
        callables: ['uniqid' => [self::class, 'uniqidPath']],
        calls: 50_000,
        iterations: 10,
    )]
    public static function generatePath(): string
    {
        return (new RandomPathGenerator())->generate('avatars', self::upload(), 'image/png');
    }

    public static function uniqidPath(): string
    {
        return 'avatars/' . uniqid() . '/original.png';
    }

    /**
     * Minting one download URL against a bare HMAC. A page with fifty
     * thumbnails signs fifty tokens, so the envelope has to stay cheap.
     */
    #[Bench(
        callables: ['bare hmac' => [self::class, 'bareHmac']],
        calls: 50_000,
        iterations: 10,
    )]
    public static function signToken(): string
    {
        return self::signer()->sign(
            new SignedPayload('019b76da-a800-7366-abcb-3dc475ad3339', 'thumb'),
            new \DateTimeImmutable('2026-08-06T13:00:00+00:00'),
        );
    }

    public static function bareHmac(): string
    {
        return hash_hmac('sha256', 'f-1|thumb|1786021200', str_repeat('a', 32));
    }

    /**
     * Verification runs once per download request, and unlike signing it works
     * on attacker-controlled input — so it does strict shape, alphabet and
     * canonical-encoding checks on top of the comparison.
     */
    #[Bench(
        callables: ['bare hash_equals' => [self::class, 'bareVerify']],
        calls: 50_000,
        iterations: 10,
    )]
    public static function verifyToken(): ?SignedPayload
    {
        return self::signer()->verify(self::token());
    }

    public static function bareVerify(): bool
    {
        return hash_equals(
            hash_hmac('sha256', 'f-1|thumb|1786021200', str_repeat('a', 32)),
            hash_hmac('sha256', 'f-1|thumb|1786021200', str_repeat('a', 32)),
        );
    }

    private static function factory(): Psr17Factory
    {
        return self::$factory ??= new Psr17Factory();
    }

    private static function extensions(): ExtensionMap
    {
        return self::$extensions ??= new ExtensionMap();
    }

    private static function upload(): Upload
    {
        return Upload::fromStream(
            self::factory()->createStream(str_repeat('representative payload ', self::BODY_REPEATS)),
            'thing.txt',
            self::factory(),
        );
    }

    private static function storage(): Storage
    {
        if (self::$storage === null) {
            $clock = new StaticClock(new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'));

            self::$storage = new Storage(
                stores: new StoreRegistry([new InMemoryStore('memory', self::factory(), $clock)]),
                repository: new MemoryRepository(),
                pathGenerator: new RandomPathGenerator(),
                mimeTypeDetector: new FinfoMimeTypeDetector(),
                idGenerator: new Uuid7IdGenerator($clock),
                policies: new PolicyRegistry(),
                deliveryPolicies: new DeliveryPolicyRegistry(),
                clock: $clock,
                defaultUrlTtl: new DateInterval('PT1H'),
            );
        }

        return self::$storage;
    }

    private static function signer(): HmacUrlSigner
    {
        return self::$signer ??= new HmacUrlSigner(
            new StaticClock(new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00')),
            new SigningKeyRing('active', ['active' => str_repeat('a', 32)]),
        );
    }

    private static function token(): string
    {
        return self::$token ??= self::signer()->sign(
            new SignedPayload('019b76da-a800-7366-abcb-3dc475ad3339', 'thumb'),
            new \DateTimeImmutable('2026-08-06T13:00:00+00:00'),
        );
    }
}
