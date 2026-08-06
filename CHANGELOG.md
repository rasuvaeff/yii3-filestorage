# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
