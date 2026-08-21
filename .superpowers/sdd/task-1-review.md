# Task 1 Review

Spec compliance: PASS

Code quality: CHANGES REQUIRED

## Critical

None.

## Important

1. `phpstan.neon.dist:10-11` configures PHPStan to analyse `templates` and `uninstall.php`, but Task 1 creates neither path. PHPStan treats a configured, nonexistent analysis path as an error, so `composer analyse` cannot satisfy the required clean foundation gate even after Docker and dependencies are available. This is an implementation defect visible by static inspection, not part of the local Docker verification gap. Remove absent paths from the Task 1 configuration and add each path when its corresponding runtime file or directory is introduced. The approved Task 1 plan currently prescribes these entries, so that plan snippet must be corrected at the same time to keep the executable specification consistent.

2. `phpcs.xml.dist:7` includes the tests in the WordPress ruleset, but the prescribed test files do not follow that ruleset: `tests/Integration/PluginBootstrapTest.php:7-9` uses Allman braces and space indentation, and `tests/bootstrap.php:2-3` uses camelCase local variables. Production/build files also introduce camelCase variables covered by the same standard, including `includes/autoload.php:16` and `tools/build.php:8-11`. Consequently, `composer lint` is expected to report violations when the unavailable dynamic gate is eventually run. Format all PHPCS-scanned PHP to WPCS conventions, including tab indentation and snake_case variables, or narrowly document and configure intentional sniff exclusions. Update the plan's literal snippets in parallel because several violations were copied directly from the approved plan.

3. `tests/Integration/PluginBootstrapTest.php:9-13` claims to verify one-time booting but only verifies singleton identity and constants; it never calls `boot()` or observes idempotence. `tests/Integration/PluginBootstrapTest.php:16-20` checks only that the runtime entry point lacks one Composer path string and does not exercise `tools/build.php` or inspect the ZIP. Therefore the required idempotent boot and distribution behavior can regress without a failing test. Add a behavioral idempotence assertion once `boot()` has an observable registration boundary, and add a build test that executes the builder and asserts the `cinebot-wp/` root, required runtime files, and absence of `vendor/` and development paths.

## Minor

1. `tools/build.php:34` and `tools/build.php:54` ignore the boolean result of `ZipArchive::addFile()`. An unreadable or otherwise rejected runtime file can produce an incomplete archive while the script exits successfully. Check each return value, close or discard the archive on failure, and throw an actionable `RuntimeException` naming the rejected source file.

## Environmental Verification Gap

The report documents that Docker, PHP, and Composer are unavailable locally, so PHPUnit, PHPCS, PHPStan, dependency installation, and the ZIP build could not run. Per the user's decision, this is an accepted environmental verification gap and not itself an implementation defect. Docker artifacts correctly remain committed for CI. JSON/XML parsing and the recorded TDD attempt provide only static/ordering evidence; CI still needs to execute all dynamic gates after the findings above are corrected.

## Compliance Notes

The committed implementation follows the approved Task 1 text: PHP 7.4 and WordPress 6.0 metadata, slug/text domain `cinebot-wp`, namespace `CinebotWp\`, committed runtime autoloader, no runtime Composer dependency, no production framework or JavaScript build step, no activation/deactivation behavior, parameterized Docker artifacts, required constants, singleton shape, and allowlisted distribution layout are present. No Task 1 runtime user-facing string requiring an internationalization function was introduced. The report records test-first ordering and the failed red-test attempt before implementation.

The quality findings above primarily expose inconsistencies in literal configuration/test snippets supplied by the approved plan. They do not change the current spec-compliance verdict, but they must be resolved before the quality gates can credibly pass.

## Re-review

Spec compliance: FAIL

Code quality: CHANGES REQUIRED

### Resolved Findings

- Prior Important 1 is resolved. `phpstan.neon.dist:7-9` now lists only the existing `cinebot-wp.php` and `includes/` paths. The plan defers `uninstall.php` at `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md:647` and `templates/` at `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md:1364` until those paths are created.
- Prior Important 3 is resolved. `includes/Plugin.php:39-46` exposes one-time booting through the guarded `cinebot_wp_booted` action; `tests/Integration/PluginBootstrapTest.php:19-39` calls `boot()` twice on a fresh instance and observes one action. `tests/Integration/PluginBootstrapTest.php:51-85` executes the real builder, opens the ZIP, verifies the root and foundation runtime entries, and rejects development/runtime-Composer paths.
- Prior Minor 1 is resolved. `tools/build.php:37-53` checks the result of the single centralized `ZipArchive::addFile()` call, closes and removes a rejected archive, names the rejected source, and reports cleanup failure.

### Remaining Important Finding

1. Prior Important 2 is only partially resolved. Spacing, braces, indentation, snake_case locals, and the narrow local-fixture exclusion were corrected in both code and plan snippets, but the scanned code still cannot pass the configured `WordPress` standard. `phpcs.xml.dist:5` scans `includes/Plugin.php`, whose PSR-4 filename violates `WordPress.Files.FileName` (the sniff expects `class-plugin.php`), while the binding task contract requires `includes/Plugin.php`. In addition, the three scanned test methods at `tests/Integration/PluginBootstrapTest.php:19`, `tests/Integration/PluginBootstrapTest.php:41`, and `tests/Integration/PluginBootstrapTest.php:51` have no function docblocks even though the configured `WordPress` standard includes `WordPress-Docs`/`Squiz.Commenting.FunctionComment`. Add a narrow `WordPress.Files.FileName` exclusion for the PSR-4 `includes/` tree and add concise docblocks to each test method; mirror both changes in the Task 1 plan snippets. Until then, the plan's required clean WPCS gate is not statically credible, so both spec compliance and code quality remain failing.

### Environmental Verification Gap

No Docker, PHPUnit, PHPCS, PHPStan, or build command was re-run, as directed. The unavailable local Docker environment remains an accepted verification gap rather than an implementation defect. Static evidence is sufficient to close the three findings listed above, but CI or another equipped environment must eventually execute the dynamic gates.

Remaining findings: 0 Critical, 1 Important, 0 Minor.

## Final Re-review

Reviewed through commit `1aaf9f510b52a641ba51f049203e425dea299ac0`.

Spec compliance: PASS

Code quality: APPROVED

### Final Finding Resolution

- The sole remaining Important finding is resolved. `phpcs.xml.dist:13-18` excludes `WordPress.Files.FileName` from the aggregate `WordPress` rule, restores that sniff independently, and applies its only relative exclusion to `^includes/`. The filename standard therefore remains active outside the required PSR-4 `includes/` tree.
- `tests/Integration/PluginBootstrapTest.php:19-22`, `tests/Integration/PluginBootstrapTest.php:44-47`, and `tests/Integration/PluginBootstrapTest.php:57-60` provide concise docblocks for all three test methods.
- The Task 1 plan mirrors the scoped PHPCS rules at `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md:401-406` and the three method docblocks at lines 448-451, 473-476, and 486-489.

### Regression Check

- Prior Important 1 remains resolved: `phpstan.neon.dist:7-9` contains only existing analysis paths, with later paths still deferred in their creating tasks.
- Prior Important 3 remains resolved: `includes/Plugin.php:39-46` retains observable guarded boot behavior, and `tests/Integration/PluginBootstrapTest.php:22-42` plus lines 60-94 retain the idempotence and real ZIP-content coverage.
- Prior Minor 1 remains resolved: `tools/build.php:37-53` still checks every centralized `ZipArchive::addFile()` result and performs failure cleanup with an actionable source path.

### Verification Gap

Dynamic Docker, PHPUnit, WPCS, PHPStan, and build gates were not rerun, as directed. Their absence remains an accepted environmental verification gap for local review and should be covered by CI; no implementation defect remains from the reviewed findings.

Remaining findings: 0 Critical, 0 Important, 0 Minor.
