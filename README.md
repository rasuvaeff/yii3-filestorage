# rasuvaeff/yii3-filestorage

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![Build](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

One facade — `add()`, `find()`, `remove()`, `stream()`, `urlFor()` — over a
swappable physical backend and a swappable metadata backend. PSR-7 streaming
end to end, authoritative MIME detection, per-group accept rules, and delivery
that does not accidentally serve an uploaded HTML file from your own origin.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

**Status: `0.x`.** The API may still change while the database and Flysystem
backends are built against it. The path layout and the signed-token format are
already frozen — see [Frozen decisions](#frozen-decisions).

## Requirements

- PHP 8.3+
- `ext-fileinfo`
- `psr/clock` ^1.0
- `psr/http-message` ^2.0, `psr/http-factory` ^1.0 (and a PSR-17 implementation
  in your application, e.g. `nyholm/psr7` or `httpsoft/http-message`)
- `symfony/console` ^6.4 || ^7.0 || ^8.0
- `symfony/mime` ^6.4 || ^7.0 || ^8.0
- `yiisoft/files` ^2.0

## Installation

```bash
composer require rasuvaeff/yii3-filestorage
```

The core is intentionally incomplete on its own: it binds the facade and its
own services, but **not** `StoreInterface` and **not** `RepositoryInterface`.
Those come from a backend package, or from your application.

| You want | Install / bind |
|---|---|
| Files on local disk, metadata in a database | this package + `rasuvaeff/yii3-filestorage-db`, bind `FileSystemStore` |
| Files on S3/GCS/Azure | this package + `rasuvaeff/yii3-filestorage-flysystem` |
| Signed download URLs, uploads, `Range` support | add `rasuvaeff/yii3-filestorage-web` |
| Just trying it out | bind `FileSystemStore` and `Test\MemoryRepository` yourself (below) |

## Usage

### Wiring

```php
// config/common/di/filestorage.php
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;

return [
    StoreInterface::class => static fn (StreamFactoryInterface $streams): StoreInterface
        => new FileSystemStore(
            name: 'upload',
            rootPath: '/app/runtime/upload',
            streamFactory: $streams,
        ),

    // Development only — every record is lost when the process ends.
    RepositoryInterface::class => MemoryRepository::class,
];
```

Then check it:

```bash
./yii filestorage:check
```

### Storing

```php
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

// From an HTTP upload.
$file = $storage->add(
    Upload::fromUploadedFile($request->getUploadedFiles()['avatar'], $streamFactory),
    groupName: 'avatars',
);

// From something your application generated — a rendered PDF, an export.
$file = $storage->add(
    Upload::fromStream($pdfStream, 'invoice-2026-08.pdf', $streamFactory),
    groupName: 'documents',
    description: 'August invoice',
    metadata: ['invoiceId' => 4711],
);

// From a path.
$file = $storage->add(Upload::fromPath('/tmp/import.csv', $streamFactory));
```

`$file` is an immutable `File`: `id`, `storeName`, `groupName`, `relativePath`,
`originalName`, `mimeType`, `size`, `description`, `contentHash`, `metadata`,
`createdAt`, `updatedAt`. `toArray()` / `fromArray()` round-trip exactly,
including microseconds.

### Reading

```php
$file = $storage->find($id);

$stream = $storage->stream($file);   // PSR-7, the default read path
$bytes  = $storage->content($file);  // capped; throws ContentTooLargeException
$there  = $storage->exists($file);   // are the bytes still physically there
```

### URLs

```php
$url = $storage->urlFor($file);                    // the one to call
$url = $storage->urlFor($file, $expiresAt);        // explicit expiry
```

`urlFor()` applies the group's delivery policy and then tries, in order:

1. a permanent public URL — **only** if the group explicitly allows one;
2. a store-native presigned URL (S3 via `-flysystem`);
3. the application's signed proxy URL (`-web`).

`url()` and `temporaryUrl()` expose steps 1 and 2 raw, for infrastructure code.
Application code and templates should not branch on whether the store happens
to be public — that is what `urlFor()` is for.

### Groups and policies

A group is a use case, not a folder. Give it accept rules once, in `params`,
instead of re-validating before every `add()`:

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage' => [
        'defaultGroup' => 'common',
        'policies' => [
            'avatars' => [
                'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                'maxBytes' => 5_242_880,
                'maxPixels' => 40_000_000,
                'requireImageDimensions' => true,
            ],
            'documents' => [
                'allowedMimeTypes' => ['application/pdf'],
                'maxBytes' => 52_428_800,
            ],
            '*' => ['maxBytes' => 20_971_520],
        ],
        'delivery' => [
            '*' => ['allowDirectPublicUrl' => false, 'forceDownload' => true],
        ],
    ],
];
```

An upload that fails its policy throws `PolicyViolationException` **before the
store is touched**, so nothing is written.

### Parameters

| Key | Default | Meaning |
|---|---|---|
| `defaultGroup` | `common` | Group used when `add()` is not given one |
| `maxInlineBytes` | 8 MiB | Cap enforced by `content()` |
| `maxSpoolBytes` | 256 MiB | Cap on buffering a non-seekable upload |
| `integrityHashMaxBytes` | `0` | `0` leaves `contentHash` null; a positive value opts into a bounded SHA-256 |
| `defaultUrlTtl` | `PT1H` | Expiry `urlFor()` uses when none is given |
| `extensionOverrides` | `[]` | Media type ⇒ extension, over the `symfony/mime` table |
| `policies` | `['*' => …]` | Per-group accept rules |
| `delivery` | `['*' => …]` | Per-group delivery rules |

### Failure handling

Every `add()` is individually all-or-nothing: either a metadata row with its
object, or nothing.

| What failed | What you get |
|---|---|
| Policy rejects the upload | `PolicyViolationException`; nothing written |
| Byte cap crossed while copying | `UploadTooLargeException`; the partial object is removed |
| Metadata save fails after the write | `AddException`; the object is deleted on a best-effort basis |
| Object delete fails after the row is gone | `RemoveException`; the object is an orphan for `filestorage:gc` |

There is no `addMany()`. An atomic batch is impossible over a filesystem or an
object store, and a method with that name would promise one. Loop over `add()`
and handle partial failure explicitly.

### Deduplication contracts

`Storage` never shares bytes: every `add()` owns one unique object, which is
why compensation may safely delete what it just wrote. Content-addressed
sharing is a different lifecycle and lives in `rasuvaeff/yii3-filestorage-db` —
but the contracts it coordinates are declared here, so a consumer can implement
or fake them without depending on the database package.

| Type | Role |
|---|---|
| `Store\BlobLedgerInterface` | Who owns shared bytes, and for how long. Reserve → publish → commit, with removal only ever *scheduling* a blob |
| `Store\BlobId` | Physical ownership: one object in one store. Never a content hash, which is identical across stores, groups and tenants |
| `Store\BlobState` | `writing`, `active`, `pending_delete`, `deleting` — the four states every dedup failure happens between |
| `Store\BlobToken` | An opaque, ledger-issued handle. One per claim, so a crashed writer releases only its own |
| `Store\BlobReservation` | A writer's expiring claim while bytes are being published. Several may coexist on one blob |
| `Store\BlobLease` | Exclusive, expiring permission to delete. At most one per blob; stealable once it runs out, which is how a crashed collector is recovered |
| `Store\BlobRecord` | A read-only snapshot of a ledger row, for `gc`, `verify` and `stat` |
| `Exception\BlobBusyException` | Transient: the blob is being deleted, retry after the lease ends |
| `Exception\LedgerException` | Not transient: the reservation, file or content does not match what the ledger holds |

The rule the whole design turns on: **shared bytes are never deleted inside a
request.** The last release only marks a blob `pending_delete`; only the
collector deletes, only under a lease, and only while the reference and
reservation counts are still zero in the same statement that claims it.

### Tenant scope

Two contracts, both optional, both unbound by default:

| Interface | Bound by | Answers |
|---|---|---|
| `Repository\FileScopeProviderInterface` | your application | "which tenant is this request?" — from `rasuvaeff/yii3-tenancy`, a session, a subdomain, or a constant |
| `Repository\ScopedFileResolverInterface` | `rasuvaeff/yii3-filestorage-db` | "give me this file *in this scope*" |

The second exists because a signed download has no ambient tenant — that is the
point of signing it — and the tempting fix is to turn the tenant filter off for
downloads. That fix is a cross-tenant read of every file whose id leaks. Instead
the scope travels inside the signed token (`SignedPayload::$scopeId`) and is
matched as a second predicate.

## Frozen decisions

Two things cannot change after the first release, so they are settled now.

**Path layout — one directory per file.** Every generator emits
`<…>/<key>/original.<ext>`, never a bare filename, and `delete()` removes the
*directory*. That is what lets a thumbnail live at `<key>/thumb.webp` with no
schema change, and what stops derivatives leaking when a file is deleted. A
rendition is described by a `DerivativeDescriptor` — a *named* preset, never
free-form dimensions, because free-form parameters turn one upload into an
unbounded set of addressable derivatives — and a store reports one back as a
`DerivativeObject`. The
extension comes from the **detected** media type through the `symfony/mime`
table; the client filename never contributes it, and an unrecognised type
becomes `original.bin`.

**Token format.** `v1.<key-id>.<expires>.<base64url payload>.<base64url hmac>`,
HMAC-SHA256 over everything before the signature. The payload is canonical JSON
`{fileId, variant, scopeId}` — the variant is inside the signature, so a token
minted for a redacted or thumbnail rendition cannot be replayed for the
original. Key ids are part of the authenticated envelope, so rotation keeps
unexpired URLs valid while the previous key is still in the ring.

## Extending it

`StorageInterface` is a plain interface with a `final` implementation, so
quotas, metrics, tracing and antivirus scanning are **decorators you write**,
not packages you install:

```php
final readonly class QuotaStorage implements StorageInterface
{
    public function __construct(private StorageInterface $inner, private Quotas $quotas) {}

    public function add(Upload $upload, ?string $groupName = null, /* … */): File
    {
        $this->quotas->assertRoom($upload->size());

        return $this->inner->add($upload, $groupName, /* … */);
    }

    // … delegate the rest
}
```

Bind your decorator to `StorageInterface` in the application layer. Do not fork
the core for this.

## Testing your own code

Test doubles ship in `src/`, not `tests/`, so they are actually installed:

| Class | Use |
|---|---|
| `Test\InMemoryStore` | A store with no disk. Implements the base contract and maintenance only — **not** URLs or ranges, so you can test what your code does when a store cannot presign |
| `Test\MemoryRepository` | Metadata in an array |
| `Test\MemoryBlobLedger` | The dedup state machine in an array: revival, reservation expiry, lease stealing, conditional completion. What it cannot reproduce is concurrency — a PHP array has no isolation levels — so proving two writers race correctly still needs the database |

There is deliberately no clock double here: `InMemoryStore` and `Storage` take
any PSR-20 clock, and a Yii application already has
`Yiisoft\Test\Support\Clock\StaticClock`. Shipping a second one would be
duplication, not convenience.

```php
$store = new InMemoryStore('test', $streamFactory, new StaticClock($now));
$storage = new Storage(
    stores: new StoreRegistry([$store]),
    repository: new MemoryRepository(),
    // …
);

$file = $storage->add(Upload::fromStream($stream, 'a.txt', $streamFactory));

Assert::same($store->writeCount(), 1);
Assert::same($store->bytesAt($file->relativePath), 'hello');
```

## Security

| Boundary | Rule |
|---|---|
| Media type | Only `finfo` output is authoritative. The client-supplied type is kept for diagnostics and never reaches a policy, a path, or a response header |
| Paths | Always generated, never taken from a request. `StoredObjectId` rejects `..`, NUL, backslashes and absolute paths; local stores re-check containment with `realpath()` after resolving, so a planted symlink cannot read outside the root |
| Original filename | Metadata only. It never enters a path, and CR/LF/NUL are stripped before it reaches a header |
| Ingress size | A non-seekable upload is spooled with a finite cap; stores enforce the group's `maxBytes` *while copying* and remove partial output |
| Decompression bombs | `maxPixels` is checked from the image header via `getimagesizefromstring()`. Pixels are never decoded |
| Direct public URLs | Off by default. `filestorage:check` **fails** when a group combines them with a permissive or active-content allow-list |
| Signed URLs | HMAC-SHA256 over version, key id, expiry and canonical payload; strict length and schema checks; `hash_equals()`; key-ring rotation; keys shorter than 32 bytes are a configuration error |
| Deduplication | Ownership is a physical `BlobId`, never a hash count — a hash is content identity and says nothing about who owns the bytes |

`finfo` recognising `image/svg+xml` says nothing about whether serving it inline
is safe: an SVG served from your own origin is a stored-XSS primitive. Keep SVG
out of any group that allows direct public URLs, and let `-web` force a download.

Uploaded images keep their EXIF, which includes GPS coordinates on most phone
photos. Nobody expects an avatar upload to publish their home address — strip or
re-encode if the files will be served publicly.

## Console

| Command | Does |
|---|---|
| `filestorage:check` | Reports wiring, per-store capabilities and per-group rules; fails on an unsafe delivery combination |
| `filestorage:stat` | Counts and sizes by group, plus how much sharing has saved |
| `filestorage:verify` | Reports rows whose object is missing; `--deep` re-reads each one and compares its hash |
| `filestorage:backfill-hash` | Fills in `contentHash` on rows written before integrity hashing was on |
| `filestorage:gc` | Collects unreferenced shared blobs, and with `--orphans` sweeps objects no row points at |

`gc`, `backfill-hash` and the `-db` package's `deduplicate` **report by default
and act only under `--apply`**. A command whose first run deletes is one somebody
eventually runs against the wrong database. `verify` has no `--apply` at all:
what to do about a missing object — restore, re-upload, delete the row — is not
a decision a command should make for you.

All four page by id and print the last one they reached, so a table too large
for one run is a sequence of bounded runs:

```bash
./yii filestorage:verify --limit=10000
# Last id: 019603f2-…
./yii filestorage:verify --limit=10000 --after=019603f2-…
```

`gc`, `verify`, `backfill-hash` and `stat` need a backend implementing
`MaintenanceRepositoryInterface` (`-db` does). `gc` additionally collects shared
blobs only when a `BlobLedgerInterface` is bound; without one it still sweeps
orphans. Under `--apply` it is the **only** thing in this family that deletes
bytes another request might want, and it does so under an exclusive, expiring
lease — see the deduplication section of `-db`.

Order matters after enabling deduplication: `deduplicate --apply` repoints the
rows, and the objects they used to point at become orphans that
`gc --orphans --apply` reclaims.

## Examples

Runnable scripts live in [examples/](examples/README.md).

## Development

```bash
make build          # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

No PHP on the host — everything runs in the `composer:2` Docker image.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
