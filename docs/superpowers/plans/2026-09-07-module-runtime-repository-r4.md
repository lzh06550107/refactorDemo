# Module Runtime Repository R4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. All production behavior is implemented test-first.

**Goal:** Build the first read-only end-to-end module runtime path from WeEngine R20 tables into the R3 runtime, routing, plugin dependency, and authorization services.

**Spec:** `docs/superpowers/specs/2026-09-07-module-runtime-repository-r4-design.md`

## Global constraints

- New application/domain code must not access `$_W`, `$_GPC`, or `pdo_*`.
- R20 tables are read-only in R4.
- `modules_plugin.name` is the plugin; `main_module` is required main.
- Runtime availability is checked before user permission.
- No permission rows means role-default mode; any row switches to explicit mode.
- R20 serialized values must never instantiate PHP objects.
- Preserve all R1-R3 test semantics.

## Task 1 — Plugin/Main dependency domain

Create `ModulePluginRelation` and `RuntimeModuleContext`. RED verifies direction, empty/self dependency rejection, and context invariants. GREEN implements immutable validated value objects.

## Task 2 — Safe serialization and read-only DB port

Create `LegacyDatabase`, `ThinkPhpLegacyDatabase`, `LegacySerializedValueDecoder`, and `config/legacy.php`. RED covers normal arrays, damaged-length repair, object rejection, and a contract asserting only `fetchOne/fetchAll` exist. GREEN exposes no write API.

## Task 3 — R20 runtime repository and binding compatibility

Create `ModuleRuntimeRepository` and `R20ModuleRuntimeRepository`; extend `ModuleBindingType` with PAGE/WEBAPP/PHONEAPP and allow empty `do` only for PAGE. RED covers `modules`, `modules_recycle`, `uni_account_modules`, `modules_plugin`, and `modules_bindings` mapping. GREEN maps rows into R3 domain objects.

## Task 4 — R20 permission repository

Create `ModulePermissionRepository` and `R20ModulePermissionRepository`. RED covers role-default mode, explicit empty, `modules=all`, and pipe-delimited module permissions. GREEN follows confirmed R20 decision order.

## Task 5 — Runtime and authorization composition

Create `RuntimeModuleService` and `ModuleAuthorizationService`. RED proves a plugin is denied when its main runtime is unavailable and permissions cannot resurrect an unavailable module. GREEN composes R3 `RuntimeModuleResolver`, `BindingRuntimeRouter`, and `LegacyPermissionPolicy`.

## Task 6 — CI and regression gate

Expand `tests/run.php` from 25 to 33 entries. Add `.github/workflows/ci.yml` running Composer validation/install, offline tests, PHPUnit, PHP lint, and route listing. Publish as non-force fast-forward and inspect workflow result before claiming networked/ThinkPHP verification.

## Verification requirements

Local sandbox: 7 R1 + 8 R4 tests, PHP lint, JSON/YAML parsing, legacy-coupling scan, runner-count contract, and `git diff --check`.

GitHub runner: all 33 offline tests plus PHPUnit bridge and ThinkPHP route-list boot check.
