# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Pre-1.0 fix wave from the 1.0 review (2026-11-27). Planned as 0.10.0; the family
moves to `^0.10` together.

- Fix (review A1 / summary A2): `maxPixels` could be bypassed by APPn padding — a 60000×60000 JPEG of 325 KB passed every policy because its frame header sat past the 256 KiB header window and "no dimensions found" meant "skip". A JPEG is now walked marker by marker to its frame header with `seek()`, and a JPEG, PNG, GIF or WebP whose dimensions cannot be established is **refused** while `maxPixels > 0` (other formats still only with `requireImageDimensions`). Legitimate JPEGs with large ICC/XMP blocks before the frame are now measured instead of refused under `requireImageDimensions`.
- Fix (review A2 / summary A3): `Storage::add()` validated metadata, called the id generator and the clock *after* `store->write()` and outside its compensation — invalid metadata left an object with no row and surfaced as `InvalidArgumentException`. Everything the caller supplies is validated before the write; anything failing after it is an `AddException`.
- Fix (B1): MIME sniffing and image-header reads loop until the window is full instead of trusting one `read()`; a short-reading stream no longer turns the pixel check off or misdetects the type.
- Fix (B2): `Upload::fromDataUrl()` accepts RFC 2397 parameters (`;charset=utf-8`), upper-case types and `;Base64`; the hint is lower-cased. Malformed parameters are refused (`Malformed parameter "…"`).
- Fix (C1): `fromDataUrl()`'s `maxSpoolBytes` is enforced on the decoded payload (`UploadTooLargeException`); it used to be ignored.
- Fix (C2): `HmacUrlSigner::sign()` throws `InvalidArgumentException` for an expiry `verify()` could never accept (before 1970, more than 11 digits).
- Fix (C4): `Storage` rejects negative `maxInlineBytes` / `integrityHashMaxBytes`; `File` rejects `NAN`/`INF` metadata values.
- Fix (C7): `filestorage:import` no longer creates a local `scheme:` directory for a manifest at a stream wrapper's root.
- Enh (B3): `Exception\SizeLimitExceededException` — one marker for every "too large": `UploadSizeException` and `UploadTooLargeException` implement it. `FileSystemStore` and `InMemoryStore` throw `UploadSizeException` (a `PolicyViolationException`) when a group's `maxBytes` is crossed mid-copy, and `Upload::fromUploadedFile()` throws `UploadTooLargeException` for `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE`. `StorageInterface::add()` lists every exception it throws.
- Enh (B4): `Upload` normalises the client's filename: control and bidi characters removed, invalid UTF-8 replaced with U+FFFD, cut to `File::MAX_ORIGINAL_NAME_LENGTH` (255) characters keeping the extension.
- Enh (A5 core, C5): `Mime\ActiveMediaTypes` — the family's one active-content list (scriptable tier + plugin tier), with the API of `-web`'s class. `DeliveryOptions` forces `attachment` for every active type whatever the policy says, so a presigned URL never serves SVG/HTML inline; `filestorage:check` uses the scriptable tier (adds JavaScript, XSLT, MathML, RSS/Atom; PDF stays allowed on public URLs).
- Enh (B5): `filestorage:check` treats every group as directly reachable when any registered store hands out public URLs, and fails permissive or scriptable groups then. `ExtensionMap` stores scriptable types and types known by an executable extension (`ExtensionMap::INERT_EXTENSIONS`: html, svg, xml, js, pl, sh, php, exe, …) as `.bin`; `extensionOverrides` is the explicit way back. Only new files are affected.
- Docs (B6, B7, C3): `File::directory()` is the dirname of the path, shared under flat and dedup layouts — never a deletion set. `RepositoryInterface::save()` is an upsert by id with the scope assigned once on insert. The integrity hash is a separate pass before the copy.

**BC breaks:**

- A JPEG/PNG/GIF/WebP whose dimensions cannot be read is refused while `maxPixels > 0` (it was accepted). Tests that upload fake image bytes under a default policy must use real headers or `maxPixels: 0`.
- Stores throw `UploadSizeException` instead of `UploadTooLargeException` for the group cap; catch `SizeLimitExceededException`. Third-party stores should switch too.
- `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE` raise `UploadTooLargeException` instead of `UploadFailedException`.
- New uploads of HTML, SVG, XML, JS, scripts and executables get the `.bin` extension; `DeliveryOptions::$forceDownload` is true for active types.
- `filestorage:check` fails a permissive or scriptable group when a public store is registered, even with `allowDirectPublicUrl: false`.
- `Upload::$originalName` is normalised and may differ from the client's name.

## 0.9.0 — 2026-11-27

Pre-1.0 minor with breaking interface changes: every store, repository and
content-addressed key generator implemented outside this package must be
updated, and the family moves to `^0.9` together.

- Fix #45: `filestorage:gc --orphans` no longer deletes the bytes of an upload between publication and its row being saved, nor of a file committed while the walk was elsewhere. References are looked up per listing page *after* the page is listed, and an object is a candidate only when it is older than `--min-age` (default `PT1H`) measured from that lookup; an object whose store reports no timestamp is never swept. Running `--orphans --apply` with uploads stopped is no longer required.
- Fix #43 (remaining window after 0.8.4): `gc` deletes in two phases — quarantine, `completeDeletion()`, then purge; when the ledger refuses (a collector paused past its lease), the bytes are restored with put-if-absent semantics and a writer's re-publication is kept. Each run first sweeps the quarantines: an entry the ledger still knows is restored unless another collector holds a live lease (crash recovery); anything else is purged after `--retention` (default `P7D`). Committed blob deletions are purged at once.
- Enh #46: `gc --orphans --store=<name> --after=<path>` resumes a sweep, `--max-scan=N` bounds the objects examined, and an early stop prints `nextCursor` (JSON `nextCursor: {store, after}`). `stat` takes `--limit` / `--after` and reports `nextCursor`.
- Enh #47: references the application owns. `Reference\ReferenceCheckerInterface` (by path, batched), `Reference\ReferenceAuthority`, `UploadPolicy::$referencesOwnedBy` (`PolicyRegistry::fromArray()` key `referencesOwnedBy`: `rows` | `application`). `gc` releases rows of application-owned groups older than `--min-age` that the checker no longer names — a shared blob through the ledger, a unique object into the quarantine — and asks again before quarantining a blob and in the orphan sweep. `GcCommand` takes `references`, `policies` and `retention`; core DI passes the policy registry and an optional bound checker.
- Enh #48: object layout strategy. `Store\Layout\ObjectLayoutInterface`, `DirectoryLayout` (the default, bit for bit the previous behaviour) and opt-in `FlatSiblingLayout` (`<base>.<ext>`, derivatives `<base>-<name>.<ext>`), `ObjectSet`; `Path\FlatSha256KeyGenerator` (`<scope>/ab/cd/<sha>.<ext>`). The frozen rule "path layout" becomes "`DirectoryLayout` is the frozen default; `FlatSiblingLayout` is an opt-in layout".
- `filestorage:check` warns when a maintenance store has no usable quarantine (none configured, or on another filesystem than the root).
- `gc --format=json` adds `releasedRows`, `orphansTooNew`, `orphansUndated`, `nextCursor` and `quarantine: {restored, purged, kept}`; text output adds the kept-young/undated count and the resume hint.

**BC breaks** (implementers; callers of the facade are unaffected):

- `Store\StoreInterface`: new `layout(): ObjectLayoutInterface`; `delete(File)` removes what `layout()->deletion()` names (unchanged for `DirectoryLayout`).
- `Store\MaintenanceStoreInterface::objects()` yields `Store\StoredObject` (`id`, `lastModified`, `size`, plus `relativePath` mirroring the id) instead of `StoredObjectId`. Code that only read `->relativePath` keeps working; code that compared or passed the items as `StoredObjectId` must use `->id`.
- `Store\MaintenanceStoreInterface`: new `quarantine(ObjectSet, DateTimeImmutable): ?QuarantinedObject`, `restore(QuarantinedObject)`, `purge(QuarantinedObject)`, `quarantined(?string, int): iterable<QuarantinedObject>`, `quarantineProblem(): ?string`. The quarantine must be hidden from `objects()` and not publicly readable.
- `Repository\MaintenanceRepositoryInterface`: new `referencedPaths(string $storeName, array $directories): list<string>` — paths of the store's rows whose `File::directory()` is one of the given directories.
- `Path\ContentAddressedKeyGeneratorInterface::generate()` takes `?string $extension = null`; `Sha256KeyGenerator` ignores it, so existing keys do not move.
- `FileSystemStore` takes `?string $quarantinePath = null` and `?ObjectLayoutInterface $layout = null`. **Without a quarantine `gc` no longer deletes from a store** — configure one outside the root (and outside any web root), on the same filesystem. A quarantine inside or around the root is rejected.
- `filestorage:gc` behaviour: orphans younger than `--min-age` (1 hour) are kept; swept orphans and released files stay in the quarantine for `--retention` (7 days) instead of being unlinked; under `DirectoryLayout` a collected blob's whole key directory (its derivatives included) goes with it, instead of the derivatives waiting for the orphan sweep; `--after` without `--orphans --store` is an error.
- `Test\InMemoryStore::corrupt()` stamps a newly placed object with the clock (an existing one keeps its timestamp); new `touch()` and `isQuarantined()`; constructor takes `?ObjectLayoutInterface $layout = null`.

## 0.8.4 — 2026-11-27

- Fix: `filestorage:gc` skips a blob's physical delete when less than half of its lease is left, and returns the blob to the queue. A collector paused past its lease could delete bytes a writer had re-published meanwhile; this narrows that window to a pause between the check and the unlink, it does not close it (#43).
- Docs: known limits of `gc` until 0.9 — the lease guards the ledger row, not the unlink; `gc --orphans --apply` must run with uploads stopped, because the sweep has no minimum object age.
- Docs: `ContentAddressableStoreInterface` — check-then-write disqualifies an adapter only *without atomic visibility*; the guarantee is atomic publication of identical content, not a single creator. `StoreResult::$created` is advisory for content-addressed puts and nothing may delete on it.
- Dist: `composer-require-checker.json` is export-ignored.

## 0.8.3 — 2026-11-27

- Docs: the agent skill and `llms.txt`: maintenance commands are always registered (a container error without `MaintenanceRepositoryInterface`), and only `verify`/`backfill-hash` take `--after`.

## 0.8.2 — 2026-11-27

- **Fixed:** since 0.8.1 the flags `gc --apply`, `gc --orphans`, `verify --deep`, `backfill-hash --apply` and `import --apply` were off when an input built in code (`ArrayInput`) passed `1` or `'1'`: a run meant to write stayed a dry run, and `verify --deep` did not compare contents. Truthy values switch the flags on again. Runs from a shell were not affected.

## 0.8.1 — 2026-11-27

- Requires `symfony/mime` `^6.4.49 || ^7.4.22 || ^8.1.10`: versions with security advisories are no longer allowed.

## 0.8.0 — 2026-11-23

- Enh #35: Add PHP 8.6 support. The `php` constraint is widened to `8.3 - 8.6`; CI runs PHP 8.6 as a regular matrix entry, and the backward-compatibility job moves to PHP 8.5.
- Dev dependencies: `vimeo/psalm` raised from `^6.16` to `^7.0`; the `-W` resolve also moved `rector/rector` (^2.4 allows 2.8.5), whose new rules rewrote literal arguments to named ones and dropped redundant recasts in `src/` and tests.
- Mutation testing now counts uncovered code (`infection --with-uncovered`): overall MSI 88.3% against `minMsi` 87 (before: 79.7% on the first honest run; 28 signature-default mutants ignored via the template regex).

## 0.7.0 — 2026-10-29

- `GroupFiles`: the files of one group (optionally with a metadata key) over
  the maintenance walk — the query "all files of the avatar group" had no
  expression before. #32

## 0.6.0 — 2026-10-28

- `Upload::fromDataUrl()`: RFC 2397 data URLs — strict base64 or the
  percent-encoded default — with the media-type claim returned by reference
  as the client hint. #28

## 0.5.0 — 2026-10-28

- `--format=json` on the four report commands (`check`, `stat`, `verify`,
  `gc`): stdout becomes exactly one machine-consumable object; the text the
  command would have printed travels inside it under `log`. #24
- README: the unscoped maintenance entry-point skeleton (`gc --orphans`
  under tenancy) — a documented recipe instead of a phrase. #24

## 0.4.0 — 2026-10-28

- `Test\UrlCapableInMemoryStore`: the URL-capable double, by composition over
  `Test\InMemoryStore` — `StoreUrlProviderInterface` with
  `publicUrls`/`temporaryUrls` toggles for exercising the `urlFor()` fallback
  ladder. #20

## 0.3.0 — 2026-10-28

- `Exception\UploadSizeException` and `Exception\UnsupportedMediaTypeException`
  extend `PolicyViolationException` and are thrown by the corresponding
  `UploadPolicy::assertAcceptable()` branches — an HTTP layer can answer 413
  and 415 without sniffing messages. `PolicyViolationException` is no longer
  final for exactly this reason. #17

## 0.2.0 — 2026-10-28

- `Store\DerivativeResolverInterface`: resolves a named preset to a generated,
  streamable rendition (`stream()` + `descriptor()`). Contract home is core so
  `-web` can serve variant tokens without depending on `-images`, whose
  `Derivatives` facade is the reference implementation. #15
- `Exception\UnresolvableVariantException`: the named preset is not configured —
  typed apart from a generation failure, so an HTTP caller can map one to 404
  and let the other surface. #15
- `Url\ProxyUrlGeneratorInterface::variantUrl()`: the signed proxy URL for a
  named derivative rendition; the variant is inside the HMAC, as with
  presigned store URLs. #15
- `Policy\DeliveryOptions::forVariant()`: the delivery shape of a rendition —
  the group's disposition, the rendition's authoritative media type and
  extension (a WebP thumbnail of `photo.jpg` downloads as `photo.webp`). #15

## 0.1.1 — 2026-08-08

- `config/di.php` now binds the facade under `Storage::class` and makes
  `StorageInterface::class` an alias to it. Every decorator this package
  documents — quotas, metrics, tracing, and `-db`'s deduplicating storage —
  is bound to the interface and needs the *undecorated* facade injected. With
  one id that dependency resolved to the decorator itself, so the recipe in
  "Extending it" answered `CircularReferenceException` with nothing naming the
  cause. Take the inner facade as `Storage`, not `StorageInterface`; the
  decorator's own constructor stays typed against the interface. Additive: an
  application that binds nothing extra resolves exactly what it did before.

## 0.1.0 — 2026-08-08

First release. The `0.x` line is where the API settles while the database,
Flysystem and web packages are built against it; the path layout and the signed
token format are already frozen.

- Added four maintenance console commands: `filestorage:gc` (collects
  unreferenced shared blobs, and with `--orphans` sweeps objects no row points
  at), `filestorage:verify` (rows whose object is missing; `--deep` re-reads and
  compares the hash), `filestorage:backfill-hash` (fills `contentHash` on rows
  written before hashing was on) and `filestorage:stat` (counts and sizes by
  group, plus what sharing saved). All page by id and resume from `--after`;
  `gc` and `backfill-hash` report unless given `--apply`, and `verify` has no
  `--apply` at all. `gc` collects shared blobs only when a `BlobLedgerInterface`
  is bound; without one it still sweeps orphans.
- `filestorage:gc --orphans` now refuses to run when a
  `FileScopeProviderInterface` is bound. The referenced-set it builds comes from
  a tenant-filtered repository while the object listing it compares against is
  physical, so under tenancy every other tenant's object looked like an orphan
  and `--apply` would have deleted it. Blob collection is unaffected.
- Added `Command\ImportCommand` → `filestorage:import <dir>`: ingests a
  directory tree through `StorageInterface::add()` — same policy, MIME
  detection, store write and metadata row as a live upload, never a direct row
  insert. Resolving the facade rather than a store and a repository is what lets
  an application that enabled deduplication get deduplicated imports. Dry-run by
  default, `--limit` per run, one `--group` per run, `--store` selectable.
  Re-running is safe because completed imports are appended to a JSON Lines
  manifest (`--manifest`, default `build/filestorage-import.jsonl`), flushed per
  file so a killed run resumes. Entries match on the absolute source path, so
  one manifest covers several roots — importing `/srv/legacy/invoices` and then
  `/srv/legacy/avatars` is the documented shape, and a `notes.txt` in each must
  not make the second run skip a file it never imported; a policy-rejected file is reported and
  deliberately *not* recorded, so widening the policy and re-running retries
  exactly those. Symlinks are not followed, dot-entries are skipped at every
  depth, and the source path lands in `metadata['importSource']`.
- `filestorage:check` gained a Tenancy section and now **fails** when a
  `FileScopeProviderInterface` is bound with nothing binding
  `ScopedFileResolverInterface`. A signed download resolves only through the
  resolver, so that combination left `-web` throwing a container error on the
  first request naming an interface; the check names the missing package before
  anything is deployed. It also warns that `gc --orphans` refuses and `stat`
  withholds its physical figures under tenancy. A resolver with no provider is
  the ordinary single-scope case and stays sound.
- `filestorage:stat` now takes the optional `FileScopeProviderInterface` too,
  and withholds its two physical figures — "Distinct objects" and the sharing
  savings — when one is bound. Those describe how many objects exist and how
  many rows point at each, and a tenant-filtered walk cannot see the rows in
  other scopes pointing at the same objects: it reported more distinct objects
  than exist and less sharing than there is. The logical counts and byte totals
  are correct per scope and are still printed, under a `Group (current scope)`
  header. `gc --orphans` refuses over the same asymmetry because there it
  decides what gets deleted.
- Fixed `Test\MemoryBlobLedger::expireReservations()` rescheduling every blob
  rather than only those that lost a reservation, which pushed each already
  scheduled blob's grace period forward on every collection pass — so nothing
  ever became collectable. `DbBlobLedger` was already correct; both now have a
  test pinning it.
- Lowered `minMsi` from 89 to 88. The four console commands add three new
  survivor groups — page-size constants, `break`/`continue` inside a
  cursor-paged walk, and console option casts — documented in `AGENTS.md`. No
  mutator is ignored.

- Added `Store\BlobLedgerInterface` and its value objects — `BlobState`,
  `BlobToken`, `BlobReservation`, `BlobLease`, `BlobRecord` — declaring how
  deduplicated bytes are owned, reserved, released and collected. The contract
  lives here rather than in `rasuvaeff/yii3-filestorage-db` so the shipped
  double can implement it; the transactional implementation is that package's.
- Added `Repository\FileScopeProviderInterface` (bound by the application) and
  `Repository\ScopedFileResolverInterface` (bound by `-db`), so a signed
  download resolves an authenticated scope instead of disabling the tenant
  filter.
- Added `Exception\LedgerException` and `Exception\BlobBusyException`.
- Added the `Test\MemoryBlobLedger` double: the full ledger state machine in an
  array, minus the concurrency a real database provides.
