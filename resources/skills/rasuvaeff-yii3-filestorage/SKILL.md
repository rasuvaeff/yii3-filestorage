---
name: rasuvaeff-yii3-filestorage
description: >-
  DI-native file storage for Yii3 with rasuvaeff/yii3-filestorage — Storage
  facade, File and Upload value objects, StoreInterface plus its optional
  URL/range/content-addressable/maintenance/derivative capabilities,
  RepositoryInterface, path generators, finfo MIME detection, per-group upload
  and delivery policies, HMAC signed tokens, the BlobLedgerInterface dedup
  contracts, the filestorage:check/stat/verify/backfill-hash/gc console
  commands, and the InMemoryStore, MemoryRepository and MemoryBlobLedger test
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

7. **Maintenance commands report before they act.** `filestorage:gc` and
   `filestorage:backfill-hash` are dry-run unless given `--apply`, and nothing
   in `gc` — sweeping expired reservations included — runs under a dry run,
   because the sweep writes. `filestorage:verify` never has `--apply`: what to
   do about a missing object is not a decision a command makes for you.

8. **`gc --orphans` refuses under tenancy, and that refusal must not be
   removed.** An object is an orphan when no row anywhere points at it; the
   referenced-set comes from a tenant-filtered repository while the object
   listing is physical, so the difference is other tenants' live files. There is
   no tenant to run it "as". Run the sweep with `FileScopeProviderInterface`
   unbound. `filestorage:stat` splits on the same line one step milder: its
   logical counts stay (labelled `Group (current scope)`), its physical
   "Distinct objects" and sharing savings are withheld under a bound provider
   rather than estimated from a partial view.

9. **`gc --apply` is the only thing here that deletes shared bytes.** It takes
   an exclusive, expiring lease per blob and removes the ledger row only if the
   blob is still unreferenced in the statement that acts. Never delete a shared
   object anywhere else, and never inside a request.

10. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

11. **Verification is mandatory.** Never claim "done" without a fresh green
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
- The maintenance commands need a backend implementing
  `MaintenanceRepositoryInterface`. An installation without one simply has no
  maintenance commands, which is better than a container error under cron.
- They all page by id and print the last one reached; resume with
  `--after=<id>`. `gc --orphans` walks every store unless `--store=` narrows it,
  and `--limit` is a budget for the whole pass, not per store.
- `add()` is all-or-nothing per file. There is no `addMany()`, because an atomic
  batch is impossible over a filesystem or an object store.
