# Drupal 12: done

**Status: FIXED and verified 2026-09-06** against Drupal `12.0-dev` on
`d12a.local`. The module installs, and all three requirement rows render with
the correct severities. The `^12` in `acquia_purge_varnish.info.yml` is now a
claim that has been measured rather than declared.

This file previously said **NOT DONE** and described the work needed. Two of its
claims were wrong by the time the work was done, and both are recorded below,
because each would have led to shipping a broken module.

---

## What actually broke, measured

```
Error: Undefined constant "REQUIREMENT_ERROR" in
acquia_purge_varnish_requirements() (line 62 of acquia_purge_varnish.install)
```

**Drupal 12 removed the `REQUIREMENT_*` constants.** Verified by grepping
12.0-dev: there is no `define('REQUIREMENT_ERROR', ...)` anywhere in core. The
replacement is the enum
`\Drupal\Core\Extension\Requirement\RequirementSeverity`, added in Drupal 11.2.

## Two corrections to this file's earlier draft

**1. It said the constants were fine.** The exact words were *"`REQUIREMENT_*`
constants still exist in Drupal 12, so severities are unchanged."* They do not,
and that single sentence was the actual blocker. The rest of the plan was
aimed at a different problem. Following the old file would have produced a
careful hook split that still fataled on the first install.

**2. It described a failure mode that no longer occurs.** It reported
`Class "acquia_purge_varnish_requirements" does not exist` and concluded
*"Drupal 12 removed procedural `hook_requirements()`"*. On 12.0-dev today the
procedural hook **is** still called. The stack trace runs through
`ModuleHandler.php(337)` into the function by name. `12.0-dev` moves daily, and
that analysis was written 2026-08-05; it was probably true then. **A dated
measurement against a dev branch is evidence with a shelf life, and this file
did not carry that caveat.**

## The fix, in two parts

**Part 1: severities.** `_acquia_purge_varnish_severity()` returns the enum
case on 11.2+ and the legacy constant below that, using core's own
`DeprecationHelper::backwardsCompatibleCall()` at the `11.2.0` boundary.
Needed because `core_version_requirement` is `^10 || ^11 || ^12` and no single
expression spans it.

**This is the mechanism the Project Update Bot's own rector patch uses** on
this file: [#3594580](https://www.drupal.org/project/acquia_purge_varnish/issues/3594580),
`acquia_purge_varnish.3.0.x-dev.rector.patch`. It differs from that patch in
one respect: the bot inlines the call at all seven severities, and they are
gathered into one helper here, because seven copies of a version boundary is
seven places to update when it moves. The behavior is identical.

*An integer literal is not a shortcut around this.* The constants and the enum
share their values (-1/0/1/2), so `2` looks portable. It is not:
`RequirementSeverity::maxSeverityFromRequirements()` reads
`$requirement['severity']->value`, so an int fatals on a property read instead —
the same bug one step later and harder to place.

**Part 2: the install blocker, which had silently stopped working.** The old
file was right that this was the part that mattered, and it was already broken:
with `league/oauth2-client` genuinely absent, **Drupal 12 installed the module
anyway**. Install-phase requirements no longer come from the procedural hook —
`ModuleInstaller` performs no such check; and core reads them from a class in
`src/Install/Requirements/` implementing `InstallRequirementsInterface`.

`AcquiaPurgeVarnishRequirements` restores the guard. The procedural hook keeps
its `install` branch, because Drupal 10 and 11.0–11.1 do not look for that
class and dropping it would move the same silent failure onto those sites.

## Verified

| Check | Result |
|---|---|
| Install on D12 with the library absent | **refused**, with *"Install requirements failed"*, naming the library. Before the fix it installed silently with no message at all. |
| Requirement rows on D12 | all three present: `..._oauth` -> `RequirementSeverity::Error`, `..._credentials` -> `Warning`, `..._environment` -> `Info` |
| `Url::fromRoute('acquia_purge_varnish.form')` | resolves; an earlier "route does not exist" was a stale router cache in the test, not a defect |
| PHPStan 2.2.13 against D12 core, changed files | **0 errors**. The four legacy constants carry per-line `@phpstan-ignore constant.notFound`, because they genuinely do not exist on D12. That is what the guard is for. |
| PHPCS Drupal + DrupalPractice | 1 error, `Missing file doc comment`, **pre-existing**, identical on the untouched file. Left alone; it is a style issue, not D12. |

## Still open, and deliberately not done here

- **`hook_requirements()` is deprecated** in 11.3 and removed in **13.0** in
  favor of `hook_runtime_requirements()` plus a `#[LegacyRequirementsHook]`
  attribute. It works on 12 with a deprecation notice. That is a Drupal 13 job,
  not a Drupal 12 one.
- **Acquia Cloud detection in the runtime block cannot be exercised locally.**
  It needs live credentials and a real Acquia environment. Untested here, and
  unchanged by this work.
- **No Drupal 12 job runs in CI.** This module's `.gitlab-ci.yml` has no
  `next major` jobs at all, so nothing will catch a regression of this fix.
- **The rest of the bot patch is not applied yet.** #3594580 also moves the
  procedural hooks out of `.module` into `src/Hook/AcquiaPurgeVarnishHooks.php`
  behind `#[LegacyHook]`, and adds `@file` docblocks. Neither is a Drupal 12
  blocker. The module installs and runs without them; and the `@file` hunk is
  **wrong as generated**: it inserts a second `/** @file */` above
  `declare(strict_types=1)` while leaving the existing one below it. Apply that
  part by hand, not with `git apply`.

## Related

`cloudflare_purge` has the same `REQUIREMENT_*` usage and **its own
`d12-work-needed.md` carries the same false claim**, corrected there. It does
not yet declare `^12`, so it is not currently making a promise it cannot keep.
