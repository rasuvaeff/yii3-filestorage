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
backends are built against it. The default path layout and the signed-token
format are already frozen — see [Frozen decisions](#frozen-decisions).

**The road to 1.0.** Already frozen: the directory-per-file layout, the
`v1.<key>.<expires>.<payload>.<hmac>` token format, the store/repository
contracts, and the group policy vocabulary — every backend package is built
against them. What blocks 1.0: the §16 foundation gate of the family plan
(notably real multi-node concurrency exposure for the blob ledger) and a
window of production time on the `-db`/`-flysystem` backends. In practice the
core has been stable since 0.1 — the 0.x is honesty about what *could* change,
not a forecast that it will.

## Requirements

- PHP 8.3 – 8.6
- `ext-fileinfo`
- `psr/clock` ^1.0
- `psr/http-message` ^2.0, `psr/http-factory` ^1.0 (and a PSR-17 implementation
  in your application, e.g. `nyholm/psr7` or `httpsoft/http-message`)
- `symfony/console` ^6.4 || ^7.0 || ^8.0
- `symfony/mime` ^6.4.49 || ^7.4.22 || ^8.1.10
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
| Image derivatives (thumbnails, presets) | add `rasuvaeff/yii3-filestorage-images` (processor: `ext-imagick` and/or `ext-gd`) |
| Attaching files to entities (avatar, invoice…) | add `rasuvaeff/yii3-filestorage-attachments` |
| Just trying it out | bind `FileSystemStore` and `Test\MemoryRepository` yourself (below) |

### Wiring the whole family

Each package's README shows its slice; this is the assembly. An application
with private files on local disk, image thumbnails, signed URLs and uploads:

```bash
composer require rasuvaeff/yii3-filestorage \
  rasuvaeff/yii3-filestorage-db \
  rasuvaeff/yii3-filestorage-web \
  rasuvaeff/yii3-filestorage-images
```

```php
// config/common/di/filestorage.php — the one binding the family does NOT
// provide: the physical store is your deployment's choice.
StoreInterface::class => static fn (StreamFactoryInterface $streams): StoreInterface
    => new FileSystemStore(
        name: 'upload',
        rootPath: '/app/runtime/upload',
        streamFactory: $streams,
        // Where filestorage:gc moves what it deletes: outside the root and any
        // web root, on the same filesystem. Without it gc cannot delete here.
        quarantinePath: '/app/runtime/upload-quarantine',
    ),

// Optional: record image dimensions into File::$metadata on upload.
StorageInterface::class => static fn (Storage $storage): StorageInterface
    => new ImageMetadataStorage($storage),
```

```php
// config/common/params.php — group policies (core), tables (-db), presets (-images)
return [
    'rasuvaeff/yii3-filestorage' => [
        'policies' => [
            '*' => ['maxBytes' => 20_971_520],
            'avatar' => ['allowedMimeTypes' => ['image/png', 'image/jpeg'], 'maxBytes' => 2_097_152],
        ],
    ],
    'rasuvaeff/yii3-filestorage-db' => [
        'fileTable' => 'filestorage_file',
        'blobTable' => 'filestorage_blob',
        'blobReservationTable' => 'filestorage_blob_reservation',
        'tablePrefix' => '',
    ],
    'rasuvaeff/yii3-filestorage-images' => [
        'presets' => [
            'thumb' => ['version' => 1, 'width' => 200, 'height' => 200, 'fit' => 'cover', 'format' => 'webp'],
        ],
    ],
];
```

```php
// config/common/routes.php
Route::get('/files/{token}')->action(FileDownloadAction::class),
Route::post('/avatars')->action(fn (FileUploadAction $action) => $action), // group from wiring
```

```php
// config/common/di/db-migration.php — the family's migrations register by
// namespace (yiisoft/db-migration ^2.1; see the -db README)
MigrationService::class => [
    'setSourceNamespaces()' => [['Rasuvaeff\\Yii3FilestorageDb\\Migration']],
],
```

With all four installed, no other wiring is needed: `-db` binds the
repository and its migrations, `-web` binds the signer and
`ProxyUrlGeneratorInterface`, `-images` binds the presets, the processor and
`DerivativeResolverInterface` — and `Storage::urlFor()` /
`Derivatives::urlFor()` fall back to the signed proxy route for the private
filesystem store. Attachments (`-attachments`) and Flysystem stores
(`-flysystem`) join the same way when needed; each binds only its own keys,
so no two family packages can conflict in a merge.

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
            quarantinePath: '/app/runtime/upload-quarantine',
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

// From an RFC 2397 data URL — rich-text editors, JSON APIs, mobile clients.
$file = $storage->add(Upload::fromDataUrl(
    'data:image/png;base64,' . $base64Payload,
    'logo.png',
    $streamFactory,
    $clientHint, // the URL's media type claim, by reference — a hint, not a fact
    maxSpoolBytes: 5_242_880, // cap on the decoded payload; 0 = none
));
```

`fromDataUrl()` accepts parameters (`;charset=utf-8`), any case of the type and
of `;base64`; the hint comes back lower-cased.

The client's filename is normalised once, in `Upload`: control characters and
bidi controls (U+202E turns `invoice<U+202E>gpj.exe` into a visible `invoiceexe.jpg`)
are removed, invalid UTF-8 becomes U+FFFD, and the name is cut to
`File::MAX_ORIGINAL_NAME_LENGTH` (255) characters keeping its extension, so it
fits a `VARCHAR(255)` everywhere. It stays metadata and never enters a path.

`$file` is an immutable `File`: `id`, `storeName`, `groupName`, `relativePath`,
`originalName`, `mimeType`, `size`, `description`, `contentHash`, `metadata`,
`createdAt`, `updatedAt`. `toArray()` / `fromArray()` round-trip exactly,
including microseconds.

### Updating metadata

Rows are updated through the same immutability the rest of the API uses —
`File::withMetadata()` returns a new `File` and the repository persists it.
Inject `RepositoryInterface` next to the facade and `save()` the new value:

```php
$file = $storage->find($id);

$repository->save($file->withMetadata(
    [...$file->metadata, 'width' => 320, 'height' => 200],
    $clock->now(),
));
```

The timestamp argument is the point: `updatedAt` is part of the download
validator, so a metadata change that left it untouched would serve stale
caches. `description` travels the same way — build the new `File` via
`File::fromArray()` with the field changed, then `save()` it.

This relies on `RepositoryInterface::save()` being an **upsert** by `File::$id`
— that is its contract. A repository of your own that only inserts breaks this
recipe and every decorator that updates metadata.

### The files of one group

```php
use Rasuvaeff\Yii3Filestorage\GroupFiles;

foreach ((new GroupFiles($repository, 'avatars'))() as $file) { /* ... */ }

// Or only those whose metadata carries a key — "every image we know the size of".
foreach ((new GroupFiles($repository, 'avatars', metadataKey: 'width'))() as $file) { /* ... */ }
```

The same maintenance walk the commands use, filtered by group (and optionally
by metadata-key presence). It takes the maintenance repository — the one
`gc`/`stat` run through (`$repository instanceof MaintenanceRepositoryInterface`;
every real backend has it, `Test\MemoryRepository` included). Honest about the cost: it pages the whole ledger —
right for a backfill or an audit, wrong for a request. A backend that can
filter natively should implement the same shape as a `WHERE`; this class is
the contract that method would satisfy.

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

`url()` and `temporaryUrl()` expose steps 1 and 2 for infrastructure code. Raw
is only half right: `url()` ignores the delivery policy entirely, while
`temporaryUrl()` hands the group's `DeliveryOptions` to the store and gets null
back when the store cannot honour them. What neither consults is
`allowDirectPublicUrl` — that gate belongs to `urlFor()`.
Application code and templates should not branch on whether the store happens
to be public — that is what `urlFor()` is for.

The proxy generator reads the tenant from the current request. A URL minted
outside one — a queue job, a mail — takes a generator pinned to the file's
tenant through `Storage::withProxyUrls()`, which returns a copy of the facade
and leaves the shared one alone:

```php
$url = $storage->withProxyUrls($proxyUrls->forScope($tenantId))->urlFor($file);
```

It is a method of the concrete `Storage` (not of `StorageInterface`, so
decorators keep compiling); where `StorageInterface` is bound to a decorator,
resolve `Storage::class` for this — URLs do not depend on the decorator.

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
store is touched**, so nothing is written. Catch it for every rejection, or the
specific branches an HTTP layer maps to different statuses:

```php
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\SizeLimitExceededException;
use Rasuvaeff\Yii3Filestorage\Exception\UnsupportedMediaTypeException;

try {
    $file = $storage->add($upload, groupName: 'avatars');
} catch (SizeLimitExceededException) {
    // 413 — the group's maxBytes (known size or crossed mid-copy), the spool
    // cap, a data URL over its cap, or PHP's upload_max_filesize.
} catch (UnsupportedMediaTypeException $e) {
    // 415
} catch (PolicyViolationException $e) {
    // 400/422 — pixel limit, unmeasurable image
}
```

**Pixel limit.** `maxPixels` (40 M by default) is read from the image header;
pixels are never decoded. A JPEG is walked marker by marker to its frame
header, however much metadata (ICC, XMP, Photoshop) precedes it, and skips
stray bytes between segments as libjpeg does; a second frame header before the
scan is refused (Pillow would measure the last one). A GIF is walked block by block:
every frame carries its own size, and one that does not fit inside the logical
screen is refused — a 1×1 screen around an 8000×8000 frame is the GIF bomb. A
TIFF is measured from its directory chain wherever the writer put it (the
largest page counts, a directory without dimensions is skipped; BigTIFF is not measured). A JPEG, PNG, GIF, WebP or TIFF
whose dimensions cannot be established is **refused** while `maxPixels > 0` —
a hidden header is how a decompression bomb gets past a fixed window. Every
other raster format (AVIF, HEIC, BMP, ICO…) is measured from its first 256 KiB
only, and passes unmeasured when its size is not found there, unless
`requireImageDimensions` is on — set it, or an allow-list without such
formats, on groups whose files reach an image decoder.

**References the application owns.** By default a `File` row *is* the
reference: it lives until the application removes it
(`'referencesOwnedBy' => 'rows'`). An application whose references are URLs
inside text or JSON sets `'referencesOwnedBy' => 'application'` on those groups
and binds a `Reference\ReferenceCheckerInterface` — by path, batched per page:

```php
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

final readonly class TextColumnReferences implements ReferenceCheckerInterface
{
    public function referenced(string $storeName, array $objects): array
    {
        // Build an index of the paths your text columns mention once per run,
        // then answer from it.
        return array_values(array_filter(
            $objects,
            fn (StoredObjectId $object): bool => $this->index->mentions($object->relativePath),
        ));
    }
}
```

`filestorage:gc` then releases rows of those groups that are older than
`--min-age` and that the checker no longer names: a shared blob through the
ledger, a unique object into the store's quarantine, restorable for
`--retention` (7 days by default). The checker is asked again right before a
blob is quarantined and during the orphan sweep, so a URL pasted back into a
text in the meantime keeps its bytes.

### Parameters

| Key | Default | Meaning |
|---|---|---|
| `defaultGroup` | `common` | Group used when `add()` is not given one |
| `maxInlineBytes` | 8 MiB | Cap enforced by `content()` |
| `integrityHashMaxBytes` | `0` | `0` leaves `contentHash` null; a positive value opts into a bounded SHA-256 |
| `defaultUrlTtl` | `PT1H` | Expiry `urlFor()` uses when none is given |
| `extensionOverrides` | `[]` | Media type ⇒ extension, over the `symfony/mime` table; keys in any case. Active and executable types (HTML, SVG, XML and every `+xml` type, JS, scripts, executables) are stored as `.bin` unless overridden here |
| `activeMediaTypes` | `['extra' => [], 'allowInline' => []]` | The family's one active-content list (`Mime\ActiveMediaTypes`, bound in DI). `extra` adds types; `allowInline` lets plugin-tier types (`application/pdf`) out, so a group with `forceDownload => false` shows them inline — on the proxy and on a presigned URL alike. A scriptable type cannot be let out |
| `policies` | `['*' => …]` | Per-group accept rules and `referencesOwnedBy` |
| `delivery` | `['*' => …]` | Per-group delivery rules |
| `gc` | `leaseTtl` `PT5M`, `gracePeriod` `PT1H`, `retention` `P7D` | `filestorage:gc` intervals (ISO 8601, strictly positive): blob lease, the wait of a released blob and default `--min-age` (keep it ≥ `-db`'s `deleteGracePeriod`), default `--retention` |

### Failure handling

Every `add()` is individually all-or-nothing: either a metadata row with its
object, or nothing.

| What failed | What you get |
|---|---|
| Policy rejects the upload | `PolicyViolationException`; nothing written |
| Invalid metadata, group name or other caller field | `InvalidArgumentException`; nothing written |
| Group's `maxBytes` crossed while copying | `UploadSizeException` (a `PolicyViolationException` and a `SizeLimitExceededException`); the partial object is removed |
| Anything failing after the write (metadata save, an unusable store answer) | `AddException`; the object is deleted on a best-effort basis |
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

**Local stores are content-addressable (0.10).** `FileSystemStore` and
`PublicFileSystemStore` implement `ContentAddressableStoreInterface`:
`putIfAbsent()` stages under a random `.part` name and publishes with `link()`,
which refuses an existing target, so two writers of the same content both
succeed and existing bytes are reused after a size check (a different size is a
`StoreException`, a reuse over the caller's `maxBytes` an `UploadSizeException`).
That is what lets `-db`'s deduplication run on a local disk, public or not.

**A key generator must fit the store's layout.** They are configured in two
places, and a mismatch deletes other files' bytes: flat keys
(`FlatSha256KeyGenerator`) under the default `DirectoryLayout` made deleting one
blob delete its whole `ab/cd/` bucket. `DirectoryLayout` now refuses any file not
named `original` / `original.<ext>` (a store reports that as `StoreException`), and `Store\Layout\LayoutCompatibility`
checks a pair behaviourally — it takes two keys that share a directory and asks
the layout what deleting each removes and where each one's derivative goes.
Content keys share the fan-out prefix by construction; for a path generator it
draws up to 4096 paths looking for two in one directory, and probes the first
two when there are none (a key directory per file). A layout that misbehaves
only for shapes the generator rarely emits can still pass. Call it wherever a
generator meets a store; `-db`'s factory and `filestorage:check` do:

```php
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;

LayoutCompatibility::assertContentKeys($keys, $store);           // InvalidConfigException
LayoutCompatibility::assertPaths($pathGenerator, $store, $streams);
$problem = LayoutCompatibility::contentKeysProblem($keys, $store->layout()); // ?string
```

A second probe asks whether a content-key generator keeps the **tenant** in the
key at all: it generates one digest under two scopes and compares.
`FlatSha256KeyGenerator(prefix: …)` does not, so two tenants' identical bytes
would share one object. Only the caller knows whether that is intended (`-db`'s
`global` deduplication scope shares on purpose), so `filestorage:check` reports
it as a **warning** when a `FileScopeProviderInterface` is bound, and `-db`
refuses it at boot under its tenant scopes:

```php
$problem = LayoutCompatibility::tenantProblem($keys);                  // ?string, scopes 'tenant-a'/'tenant-b'
$problem = LayoutCompatibility::tenantProblem($keys, $scopeA, $scopeB); // your real scope strings
LayoutCompatibility::assertTenantKept($keys);                          // InvalidConfigException
```

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

**Path layout — one directory per file, by default.** `DirectoryLayout` is the
frozen default: every generator emits
`<…>/<key>/original.<ext>`, never a bare filename, and `delete()` removes the
*directory*. That is what lets a thumbnail live at `<key>/thumb.webp` with no
schema change, and what stops derivatives leaking when a file is deleted. A
rendition is described by a `DerivativeDescriptor` — a *named* preset, never
free-form dimensions, because free-form parameters turn one upload into an
unbounded set of addressable derivatives — and a store reports one back as a
`DerivativeObject`. Serving a rendition over HTTP goes through
`Store\DerivativeResolverInterface` (`-images`' `Derivatives` implements it,
`-web` consumes it — neither depends on the other), and
`Url\ProxyUrlGeneratorInterface::variantUrl()` mints the signed proxy URL for
one, with the variant inside the HMAC. The
extension comes from the **detected** media type through the `symfony/mime`
table; the client filename never contributes it, and an unrecognised type
becomes `original.bin`.

A store may instead be configured **once, before it holds data**, with
`Store\Layout\FlatSiblingLayout` — for a flat content-addressed tree such as
`blobs/ab/cd/<sha>.<ext>` (`Path\FlatSha256KeyGenerator`), with derivatives
beside the original as `<sha>-<name>.<ext>`. `delete()` then removes the
original and every `<sha>-*` sibling; a base must not contain `-` or `.`, and a
file whose name does not fit is refused rather than guessed at. The layout is
chosen per store through its constructor (`layout:`), and `StoreInterface::layout()`
tells `gc` and derivative writers which one is in force. `DirectoryLayout`
refuses a flat key outright (see *Deduplication contracts*).

`FlatSha256KeyGenerator(prefix: 'blobs')` adopts an existing tree as it is:
keys become `blobs/ab/cd/<sha>.<ext>` and the scope argument is **ignored**, so
it is for a single-tenant installation whose deduplication scope is global
anyway. Without `prefix` the scope leads, as before.

A root that is also a web root usually holds the application's own files.
**The root belongs to the store**: `gc --orphans` treats every file under it
without a row as an orphan. Hidden entries (`.htaccess`, `.well-known/`) are
never listed; anything else the application keeps there must be excluded with
`ownedPrefixes` — the inventory is then those subtrees only, and a write outside
them is refused. Listing group names there works until a group is named like
one of the application's directories; `RandomPathGenerator(prefix: 'files')`
puts every group under one subtree (`files/<group>/ab/cd/<key>/original.<ext>`),
so `ownedPrefixes: ['blobs', 'files']` covers every write. `webRoot` lets
`PublicFileSystemStore` refuse a quarantine inside the document root (the inner
store can only check its own root):

```php
new PublicFileSystemStore(
    new FileSystemStore(
        name: 'uploads',
        rootPath: '/app/public/uploads',
        streamFactory: $streams,
        quarantinePath: '/app/runtime/uploads-quarantine',
        layout: new FlatSiblingLayout(),
        ownedPrefixes: ['blobs', 'files'],
    ),
    baseUrl: '/uploads',
    webRoot: '/app/public',
);

// and, in the application's DI, the prefixed path generator:
PathGeneratorInterface::class => static fn (ExtensionMap $extensions): PathGeneratorInterface
    => new RandomPathGenerator($extensions, prefix: 'files'),
```

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

Bind your decorator to `StorageInterface` in the application layer, and take the
inner one as `Storage`, not as `StorageInterface`:

```php
// config/common/di/filestorage.php
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;

return [
    StorageInterface::class => static fn (Storage $inner, Quotas $quotas): StorageInterface
        => new QuotaStorage($inner, $quotas),
];
```

Core binds two ids for one facade: `Storage::class` builds it, and
`StorageInterface::class` is an alias to that. `Storage::class` is the id that
still means "the plain facade" after the application has rebound the interface —
a decorator asking for `StorageInterface` would be handed itself, and the
container answers `CircularReferenceException` for a recipe that reads
perfectly. The constructor above stays typed `StorageInterface` so the class is
testable with any double; only the wiring names the concrete id.

Do not fork the core for this.

**Writing a store of your own.** `StoreInterface` now has `layout()` (return a
`DirectoryLayout` unless you mean otherwise) and `delete()` removes what
`ObjectLayoutInterface::deletion()` names. A `MaintenanceStoreInterface` lists
`StoredObject` (id, last-modified time, size — return null for the time when
the backend cannot tell, and `gc` will never delete that object) and implements
the quarantine: `quarantine(ObjectSet, $now)` moves the existing members of a
set under one `QuarantinedObject` entry, `restore()` puts them back without
overwriting a key that exists again, `purge()` deletes the entry, `quarantined()`
lists entries oldest first, and `quarantineProblem()` tells `filestorage:check`
what is wrong with the configuration. The quarantine must never appear in
`objects()` and must not be publicly readable. `ReferenceAuthority` on a group
policy is the only thing the application configures for references it owns.

## Testing your own code

Test doubles ship in `src/`, not `tests/`, so they are actually installed:

| Class | Use |
|---|---|
| `Test\InMemoryStore` | A store with no disk. Implements the base contract and maintenance only — **not** URLs or ranges, so you can test what your code does when a store cannot presign |
| `Test\UrlCapableInMemoryStore` | The URL-capable variant, by composition over `InMemoryStore` — `StoreUrlProviderInterface` with `publicUrls`/`temporaryUrls` toggles, so the fallback ladder of `urlFor()` (public → temporary → proxy) is exercisable without an object store |
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
| Original filename | Metadata only. It never enters a path; `Upload` looks at its first 4 KiB only (plus the extension), removes control characters, bidi and other invisible format characters (except the zero-width joiner and non-joiner) and line/paragraph separators, and cuts it to 255 characters |
| Ingress size | A non-seekable upload is spooled with a finite cap; stores enforce the group's `maxBytes` *while copying* and remove partial output |
| Decompression bombs | `maxPixels` is checked from the image header; JPEG is walked marker by marker, so metadata padding cannot hide the frame header; a GIF frame larger than its screen is refused; a TIFF directory is found wherever it sits. A JPEG/PNG/GIF/WebP/TIFF whose dimensions cannot be established is refused; other formats are measured from a 256 KiB prefix only. Pixels are never decoded |
| Active content | One list, `Mime\ActiveMediaTypes` (scriptable tier: HTML, XHTML, SVG, XML and any `+xml` type, XSLT, MathML, JavaScript, RSS/Atom; plugin tier: PDF, Flash), one instance configured by the `activeMediaTypes` parameter. `DeliveryPolicyRegistry` carries it, so `DeliveryOptions` forces `attachment` for all of them and a presigned URL never serves them inline; `check` refuses all but the plugin tier on public URLs; `ExtensionMap` stores scriptable types as `.bin` |
| Content-addressed public paths | A deduplicated object's key is the SHA-256 of its bytes (`blobs/ab/cd/<sha>.<ext>`). On a public store anyone holding the same bytes can compute the URL and learn whether the site stores them — and fetch them. Random keys (128 bits) are not guessable; content keys are guessable by design. Keep groups whose files are private out of a content-addressed public store |
| Direct public URLs | Off by default. `filestorage:check` **fails** when a group combines them with a permissive or scriptable allow-list — and when a publicly readable store is registered, for every group, since it serves objects by path whatever `allowDirectPublicUrl` says. A store says so through `Store\PubliclyReadableStoreInterface::isPubliclyReadable()`: `PublicFileSystemStore` always, a bucket store when configured as public (answering `publicUrl()` is not enough — an S3 adapter builds object URLs for private buckets too) |
| Signed URLs | HMAC-SHA256 over version, key id, expiry and canonical payload; strict length and schema checks; `hash_equals()`; key-ring rotation; keys shorter than 32 bytes are a configuration error |
| Deduplication | Ownership is a physical `BlobId`, never a hash count — a hash is content identity and says nothing about who owns the bytes |

`finfo` recognising `image/svg+xml` says nothing about whether serving it inline
is safe: an SVG served from your own origin is a stored-XSS primitive. Keep SVG
out of any group that allows direct public URLs, and let `-web` force a download.
An application that really serves public SVG through `<img>` opts back into the
`.svg` extension explicitly: `'extensionOverrides' => ['image/svg+xml' => 'svg']`.

Uploaded images keep their EXIF, which includes GPS coordinates on most phone
photos. Nobody expects an avatar upload to publish their home address — strip or
re-encode if the files will be served publicly.

## Console

| Command | Does |
|---|---|
| `filestorage:check` | Reports wiring, per-store layout and capabilities, tenancy and per-group rules; fails on an unsafe delivery combination, on tenant mode with no scoped resolver, and on a path generator that the store's layout cannot delete one by one; warns about a content-key generator the layout cannot delete one by one (on every store: only `-db` knows which store deduplication writes to, and its factory refuses the pair); warns about a `PublicFileSystemStore` owning its whole root or with no `webRoot`, about a content-key generator that ignores the tenant while a scope provider is bound, and about an application-owned group with no reference checker. Stores that are publicly readable list the `public` capability |
| `filestorage:stat` | Counts and sizes by group, plus how much sharing has saved (physical figures withheld under tenancy) |
| `filestorage:verify` | Reports rows whose object is missing; `--deep` re-reads each one and compares its hash — `corrupt` for wrong bytes or length, `unreadable` for a read cut short before EOF |
| `filestorage:backfill-hash` | Fills in `contentHash` on rows written before integrity hashing was on; a read cut short, or one whose length differs from the row, writes nothing |
| `filestorage:gc` | Collects unreferenced shared blobs, and with `--orphans` sweeps objects no row points at and the `.part` files crashed writes left |
| `filestorage:import <dir>` | Ingests a directory tree through the ordinary write path, skipping what a manifest says is already imported |

`gc`, `backfill-hash` and the `-db` package's `deduplicate` **report by default
and act only under `--apply`**. A command whose first run deletes is one somebody
eventually runs against the wrong database. `verify` has no `--apply` at all:
what to do about a missing object — restore, re-upload, delete the row — is not
a decision a command should make for you.

The four report commands (`check`, `stat`, `verify`, `gc`) take
`--format=json`: stdout becomes exactly one machine-consumable object — stable
keys, counts and rows instead of tables — and everything the text mode would
have printed travels inside it under `log`, so a dashboard or a cron wrapper
parses instead of scraping.

`verify`, `backfill-hash` and `stat` page by id and print where they stopped, so
a table too large for one run is a sequence of bounded runs:

```bash
./yii filestorage:verify --limit=10000
# Last id: 019603f2-…
./yii filestorage:verify --limit=10000 --after=019603f2-…
./yii filestorage:stat --limit=100000 --after=019603f2-…   # JSON: nextCursor
```

A windowed `stat` (`--after`, or stopped at `--limit`) says so: its distinct
objects and sharing are counted within the window only (JSON: `windowed`).

`gc --orphans` pages a store by path instead: `--max-scan=N` bounds the objects
examined, and when it or `--limit` stops the sweep early it prints
`Resume with --orphans --store=<name> --after=<path>` (JSON: `nextCursor`).
The cursor belongs to one store, and a run without `--store` always starts at
the first one — so with more than one store, cron the sweep per store:

```bash
./yii filestorage:gc --apply --orphans --store=uploads --max-scan=50000
./yii filestorage:gc --apply --orphans --store=private --max-scan=50000
```

When `--limit` is used up by released rows and blobs, the sweep does not start
and says so (`Orphan sweep skipped`, JSON `orphanSweepSkipped: true`) instead of
reporting zero orphans.

`gc`, `verify`, `backfill-hash` and `stat` need a backend implementing
`MaintenanceRepositoryInterface` (`-db` does). `gc` additionally collects shared
blobs only when a `BlobLedgerInterface` is bound; without one it still sweeps
orphans. Under `--apply` it is the **only** thing in this family that deletes
bytes another request might want, and it does so under an exclusive, expiring
lease — see the deduplication section of `-db`.

**How `gc` deletes (0.9).** Every deletion is two-phase. Bytes are first moved
into the store's **quarantine** (`MaintenanceStoreInterface::quarantine()`), the
decision is committed, and only then are they purged — or restored, with
put-if-absent semantics, when the decision turned out wrong. A run starts by
finishing what earlier runs left there. Without a quarantine
(`FileSystemStore`'s `quarantinePath`) `gc` cannot delete from a store, and
`filestorage:check` warns about it.

| Rule | Why |
|---|---|
| A shared blob is purged only after `completeDeletion()` succeeds; if it is refused, the bytes go back and a writer's re-publication of them is kept | A collector paused past its lease used to delete bytes a writer had re-published meanwhile |
| An orphan is a candidate only when it is older than `--min-age` (default `PT1H`) *measured from the moment its page's rows were looked up*, and an object without a timestamp never is | An upload between publishing its bytes and saving its row, or committed while the walk was elsewhere, used to lose its bytes. `--orphans --apply` no longer needs uploads stopped, as long as publication-to-row stays under `--min-age` |
| Orphans and released application-owned files stay in the quarantine for `--retention` (default `P7D`); blob deletions the ledger committed are purged at once | A mistaken sweep is reversible for a week. The quarantine must be outside anything the web server serves: the store checks its root, `PublicFileSystemStore(webRoot:)` the document root |
| An entry the ledger still knows is put back, unless another collector holds a live lease on it | Recovery of a collector that crashed between the two phases |
| Every other entry is checked against the rows (matched through the layout) and the `ReferenceCheckerInterface` on every run, whatever its age; a referenced entry is restored at once, with a warning | A release interrupted between quarantine and row deletion leaves a live row over quarantined bytes — the next run gives them back (and, if nothing references the file, finishes the release); a file released by mistake comes back as soon as it is referenced again |
| Under a bound `FileScopeProviderInterface` that answers a scope, a quarantine entry is restored when a row matches but never purged (warning) | The rows are one tenant's: a miss proves nothing. Purge from an entry point that leaves the provider unbound, or where it answers no scope (`-db`'s `globalMaintenanceAccess`) |
| One entry that cannot be restored or purged is skipped and counted (JSON `quarantine.failed`); a failed restore after a refused commit or a failed row deletion is a warning, and the next run restores it — the ledger or the row still points at it | One bad entry used to stop the whole quarantine on every run, and one failed restore ended the run |
| A quarantine that fails half-way puts back what it already moved | A row whose original was half-quarantined would have lost it to the retention |
| The orphan inventory never contains hidden entries, and only the `ownedPrefixes` subtrees when the store declares them | The root of a public store is often shared with the application's own files |
| A key the layout refuses is kept and warned about, never deleted with its directory | A flat key under `DirectoryLayout` used to take its whole fan-out bucket with it |
| Quarantining an orphan removes its emptied key directory (`DirectoryLayout`), never a fan-out directory | One empty `<group>/ab/cd/<key>/` was left per swept orphan |
| With `--orphans`, once the walk of a store is complete, `.part` files not written to for `--min-age` are removed outright (JSON `abandonedPartials`), within `--limit` | A crash mid-write left them for good — no listing saw them, and a public root served them. Stores opt in through `Store\AbandonedPartialsInterface`; both local stores do |

**Cost.** Every run reads the whole quarantine: per page of 500 entries, one row
lookup, one checker call and one ledger lookup — one call when the ledger
implements `Store\BlobLedgerBatchLookupInterface` (`Test\MemoryBlobLedger` does),
otherwise one per object. After `deduplicate --apply` and `gc --orphans --apply`
the quarantine holds an entry per object for the whole retention, so a
per-object ledger is that many queries per run. With `--orphans`, the `.part`
sweep walks each completed store a second time.

To undo a mistaken sweep by hand within the retention, move the objects back
from `<quarantinePath>/<token>/<relative path>` to `<rootPath>/<relative path>`;
the token starts with the Unix time of the quarantine.

| Option | Default | Meaning |
|---|---|---|
| `--apply` | off | Actually delete |
| `--orphans` | off | Also sweep objects no row points at |
| `--store=<name>` | every store | Limit the orphan sweep to one store |
| `--after=<path>` | — | Resume that store's sweep (needs `--orphans --store`) |
| `--limit=N` | 1000 | Stop after N deletions (releases, blobs and orphans together) |
| `--max-scan=N` | 0 (no limit) | Stop the sweep after examining N objects |
| `--min-age=<ISO 8601>` | `PT1H` | Minimum age of an orphan object or a released row |
| `--retention=<ISO 8601>` | `P7D` | How long quarantined orphans and released files stay restorable |

The defaults come from the `gc` parameters; the constructor refuses a zero or
negative lease, grace period or retention.

Order matters after enabling deduplication: `deduplicate --apply` repoints the
rows, and the objects they used to point at become orphans that
`gc --orphans --apply` reclaims. Under tenancy that last step moves — `deduplicate`
runs per tenant under the ambient scope, while the sweep refuses under a bound
scope provider and runs once with it unbound (see below).

### Importing an existing directory tree

`filestorage:import` walks a directory and puts every file through `add()` — the
same policy check, MIME detection, store write and metadata row as a live
upload. It never inserts a row or writes an object directly, which is also why
an application that enabled deduplication gets deduplicated imports for free:
the command resolves `StorageInterface`, and that is the key such an application
overrode.

```bash
./yii filestorage:import /srv/legacy/invoices                          # a report
./yii filestorage:import /srv/legacy/invoices --group=documents --apply
./yii filestorage:import /srv/legacy/avatars  --group=avatars   --apply
```

| Option | Default | Notes |
|---|---|---|
| `--apply` | off | Without it nothing is imported and the manifest is untouched |
| `--group` | `defaultGroup` | One group for the whole run. Different groups are different runs over different subdirectories |
| `--store` | the default store | Which physical store to write to |
| `--limit` | 1000 | Files per run. It bounds the work, not the memory — the listing is built and sorted up front, so it is proportional to the whole tree |
| `--manifest` | `build/filestorage-import.jsonl` | Where completed imports are recorded, and read back to skip them |

**The manifest is what makes a second run safe.** `add()` has no natural key, so
without it a re-run writes a second row *and* a second object for every file.
The manifest is JSON Lines, one `{"source":…,"path":…,"id":…}` per completed
file, flushed as it goes — so a run that was killed halfway leaves what it
finished recorded, and the next run picks up only what is new. Keep it, or a
later run duplicates everything. Entries are matched on `source`, the absolute
path, so **one manifest covers several roots**: the two commands above share the
default and neither skips the other's files, even when both trees hold a
`notes.txt`.

A file whose policy rejected it is reported, skipped, and **not** recorded — so
the run after you widen the policy retries exactly those. The command exits
non-zero when anything failed.

Symbolic links are not followed (a link in a legacy tree can point outside the
directory you named), entries beginning with a dot are skipped at every depth,
and the source path is stored in the row's `metadata['importSource']`.

### Tenancy and the orphan sweep

`filestorage:check` reports which mode you are in, and **fails** when a
`FileScopeProviderInterface` is bound with nothing binding
`ScopedFileResolverInterface`: a signed download then has no scoped way to
resolve a file, and resolving by id alone reads any file whose id leaks. Install
a repository backend that ships a resolver (`-db` does), or unbind the scope
provider if the installation is not multi-tenant. The reverse — a resolver with
no scope provider — is the ordinary single-scope case and is fine.

**`gc --orphans` refuses to run when a `FileScopeProviderInterface` is bound**,
and the refusal is deliberate rather than a limitation to work around. An object
is an orphan when *no* row anywhere points at it. The referenced-set comes from
the repository, which filters by the current tenant; the object listing is
physical and filters by nothing. Comparing the two under tenancy classifies every
other tenant's objects as orphans, and `--apply` deletes them. There is no tenant
to run it "as", because no single tenant's rows can prove an object unreferenced.

Run the sweep from a maintenance entry point that leaves the scope provider
unbound, where the repository sees every row. With `-db` 0.4 a provider that is
bound but answers `null` — a console process with no tenant — no longer means
"every tenant": `DbRepository` throws `MissingScopeException`, so `gc`, `stat`,
`verify` and `backfill-hash` fail there unless the entry point leaves the
provider unbound or opts in with `-db`'s `globalMaintenanceAccess` parameter — it
applies only to the `MaintenanceRepositoryInterface` and `BlobLedgerInterface`
bindings (see `-db`'s
`UPGRADE.md`). Blob collection is unaffected:
the ledger is keyed by physical identity, not by tenant, so plain `gc --apply`
works in either kind of installation.

A maintenance entry point is a console config set of its own, with the scope
provider deliberately absent — `filestorage:check` reports which mode it runs
in, so a wrong wiring is visible before anything sweeps:

```php
// config/console-maintenance/di/filestorage.php — the unscoped skeleton:
// same stores, same repository, NO FileScopeProviderInterface binding.
// A store that can list AND quarantine objects: gc --orphans --apply walks it
// and moves each orphan into its quarantine — a read-only binding cannot sweep.
StoreInterface::class => /* the production store, with listing + deletion */,
RepositoryInterface::class => /* the -db repository */,
// FileScopeProviderInterface: deliberately NOT bound here.

// config/console-maintenance/params.php (or your entry script's bootstrap):
// run `./yii filestorage:check` first — it must print "Single-scope".
```

```bash
APP_CONFIG_ROOT=config/console-maintenance ./yii filestorage:gc --orphans --apply
```

`filestorage:stat` reads through the same scoped repository and splits along the
same line, one step milder. Counts and byte totals are *logical* — they describe
rows, and one tenant's rows are a correct answer to "how much does this tenant
have", so they are still printed, under a `Group (current scope)` header.
"Distinct objects" and the sharing savings are *physical*: they claim to know how
many objects exist and how many rows point at each, and a tenant-filtered walk
cannot see the rows in other scopes pointing at the same ones. It would report
more distinct objects than exist and less sharing than there is, so under a bound
scope provider **both are withheld** rather than estimated. Run `stat` with the
provider unbound for the physical figures.

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
