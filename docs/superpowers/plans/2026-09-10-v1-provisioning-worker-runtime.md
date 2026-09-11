# V1 Provisioning Worker Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make R8D automatic authorizer provisioning operational in a deployed V1 by adding due-job discovery and a supervised ThinkPHP CLI worker.

**Architecture:** Keep `AuthorizerProvisioningWorker::runOne()` as the only owner of claim/CAS business execution. Add a non-locking `ProvisioningJobSource` that discovers due `ready` jobs and expired `claimed` jobs, a small batch runner that isolates per-job exceptions, and a thin ThinkPHP console command that loops batches. Candidate discovery may race across processes; `tryClaim()` remains the single concurrency authority.

**Tech Stack:** PHP 8.2+, ThinkPHP 8.1.3, MySQL/InnoDB, custom offline test runner, systemd/Supervisor deployment.

**Spec:** `docs/superpowers/specs/2026-09-09-openplatform-authorizer-provisioning-r8d-design.md`

## Global Constraints

- Work only on `refactor/openplatform-authorizer-provisioning-r8d`; never force push.
- Do not change R8D provisioning state semantics, quota semantics, claim TTL, retry schedule, or ownership invariants.
- Candidate discovery is non-locking and bounded to 1..1000 IDs; `ProvisioningJobRepository::tryClaim()` owns mutual exclusion.
- Discover both `ready` jobs with `next_attempt_at <= now` and `claimed` jobs with expired non-null `claim_expires_at <= now`.
- A single job exception must not terminate a batch/daemon and exception plaintext must not be logged or emitted; `handled` means `runOne()` returned normally, not that the durable provisioning status is success.
- CLI name is `openplatform:provisioning-worker`; options are `--once`, `--limit`, and `--sleep`.
- GitHub Actions remain deferred for this V1 acceptance pass.

---

### Task 1: Due-job source and batch runner

**Files:**
- Create: `app/openplatform/contract/ProvisioningJobSource.php`
- Create: `app/openplatform/infrastructure/ThinkPhpProvisioningJobSource.php`
- Create: `app/openplatform/application/ProvisioningBatchResult.php`
- Create: `app/openplatform/application/ProvisioningBatchRunner.php`
- Test: `tests/Component/OpenPlatform/ProvisioningBatchRunnerTest.php`

**Interfaces:**
- `ProvisioningJobSource::dueProvisioningIds(DateTimeImmutable $now, int $limit): array` returns a list of candidate provisioning IDs.
- `ProvisioningBatchRunner` accepts `ProvisioningJobSource` plus a `Closure(string, DateTimeImmutable): void` delegating to the existing worker.
- `ProvisioningBatchRunner::runBatch(DateTimeImmutable $now, int $limit): ProvisioningBatchResult`.

- [x] Write behavior test proving p1/p2/p3 continue after p2 throws and invalid limits fail before source access.
- [x] Run RED and verify missing interfaces/classes are the only failure.
- [x] Implement the source port, result object, non-locking SQL source, and batch runner without modifying worker semantics.
- [x] Run behavior test under `E_ALL`; require exit 0 and empty stderr.

### Task 2: ThinkPHP CLI and deployment registration

**Files:**
- Create: `app/command/OpenPlatformProvisioningWorkerCommand.php`
- Create: `config/console.php`
- Test: `tests/Contract/R8DProvisioningWorkerRuntimeContractTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Command resolves the existing production `AuthorizerProvisioningWorker`, composes it with `ThinkPhpProvisioningJobSource` and `ProvisioningBatchRunner`, then executes one batch or loops.
- `--limit` range 1..1000; `--sleep` range 1..60 seconds; `--once` exits after one batch.

- [x] Write contract test for command registration, due SQL, non-locking discovery, safe output, and runner registration.
- [x] Run RED and verify missing runtime files are the failure.
- [x] Implement command and `config/console.php`; register both new tests in `tests/run.php`.
- [x] Run contract test and PHP lint under `E_ALL`; require no warnings.

### Task 3: V1 deployment documentation and release candidate gate

**Files:**
- Modify: `README.md`
- Create/Modify: `docs/superpowers/plans/2026-09-10-v1-provisioning-worker-runtime.md`

- [x] Update README current implementation from R8C to R8D and add worker startup plus systemd supervision example.
- [x] Run both new tests, lint every touched PHP file, and verify the exact branch head has not drifted.
- [x] Commit/push only after AI-side targeted gates are green; keep PR Draft and Actions/main deferred.
