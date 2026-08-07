# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
  file so a killed run resumes; a policy-rejected file is reported and
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
