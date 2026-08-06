---
name: rasuvaeff-yii3-filestorage
description: >-
  DI-native file storage for Yii3 with rasuvaeff/yii3-filestorage — Storage
  facade, File and Upload value objects, StoreInterface plus its optional
  URL/range/content-addressable/maintenance/derivative capabilities,
  RepositoryInterface, path generators, finfo MIME detection, per-group upload
  and delivery policies, HMAC signed tokens, the BlobLedgerInterface dedup
  contracts, and the InMemoryStore, MemoryRepository and MemoryBlobLedger test
  doubles. Use when writing, reviewing or debugging file
  upload, storage or download code in a project that has this package installed.
---

# rasuvaeff/yii3-filestorage

One facade over a swappable physical store and a swappable metadata repository.
Namespace `Rasuvaeff\Yii3Filestorage\`. Full API reference: `llms.txt`.

## Safety rules — verify these on every change

1. **The client media type is never authoritative.** `Upload::$clientMediaTypeHint`
   exists for diagnostics. Only `MimeTypeDetectorInterface::detect()` output may
   reach an `UploadPolicy`, a path extension or a response `Content-Type`. An
   attacker controls the hint completely.

2. **Paths are generated, never taken from input.** They come from a
   `PathGeneratorInterface`; `StoredObjectId` rejects `..`, NUL, backslashes and
   absolute paths, and local stores re-check containment with `realpath()` after
   resolving. `File::$originalName` is metadata — it must not enter a path.

3. **Two things are frozen and cannot change.** The layout
   `<…>/<key>/original.<ext>` (a directory per file, so `delete()` removes the
   directory and derivatives cannot leak) and the token format
   `v1.<key-id>.<expires>.<b64url payload>.<b64url hmac>`. `relativePath` is
   persisted and tokens are already in browsers.

4. **`urlFor()`, not `url()`.** `url()` and `temporaryUrl()` are raw capabilities
   that ignore delivery policy. `urlFor()` applies it: a permanent public URL
   only where the group allows one, otherwise presigned, otherwise the signed
   proxy route. Direct public URLs bypass every response header `-web` enforces.

5. **Direct public delivery plus active content is a stored-XSS hole.** HTML,
   SVG and XML served from your own origin execute as your site.
   `filestorage:check` fails that combination — do not work around it.

6. **Byte caps are enforced while copying, not after.** An unknown-length body
   must be stopped mid-write and its partial output removed. A check after the
   fact has already spent the disk.

7. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

8. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`.

## Gotchas

- `Upload::stream()` is always rewound. Read it to the end freely; the next call
  rewinds again. Do not call `moveTo()` on a PSR-7 uploaded file yourself — it
  invalidates the stream the `Upload` holds.
- Capabilities are `instanceof` checks against separate interfaces, not nullable
  methods on `StoreInterface`. The exception is `StoreUrlProviderInterface`,
  whose methods return null because one `FlysystemStore` class must wrap
  adapters that differ at runtime.
- `Test\InMemoryStore` deliberately implements neither URLs nor ranges. That is
  how you test what your code does when a store cannot presign.
- No clock double ships with the package. Everything takes a PSR-20 clock —
  use `Yiisoft\Test\Support\Clock\StaticClock` rather than adding another.
- Timestamps serialise as `Y-m-d\TH:i:s.uP`. `DateTimeInterface::ATOM` drops
  microseconds and breaks the `File` round-trip against a PSR-20 clock.
- `add()` is all-or-nothing per file. There is no `addMany()`, because an atomic
  batch is impossible over a filesystem or an object store.
