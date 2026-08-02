<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Pest / PHPUnit bootstrap. Run the suite from Audit Kit's OWN root — its own
 * `vendor/bin/pest` (or `ddev composer test`, which resolves to the same
 * binary) — never via the playground's shared
 * `ddev craft pest -- --configuration=vendor/craftpulse/craft-audit-kit/phpunit.xml.dist`.
 * That shared invocation leaves the process's working directory at the
 * playground's Craft root, not here, so craft-pest-core's InstallsCraft
 * plugin never finds this plugin's `phpunit.xml.dist` and its `<env>` DB
 * overrides in `phpunit.xml.dist` (see the comment there) never apply —
 * Craft boots against the playground's live `db` instead of `db_test` and
 * every fixture this suite writes commits permanently.
 *
 * With the correct invocation the working directory is this plugin's own
 * root, so its own autoloader (already mapping both Craft and the kit's
 * own `src`/`tests`) is authoritative and craft-pest's TestCase boots the
 * application itself against `db_test` — this file only wires autoloading,
 * registers the module under test, and registers the Pest `uses()` bindings.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

$craftBase = getcwd();

// Audit Kit vendors its own `craftcms/cms` (see composer.json) precisely so
// its suite can boot a fully isolated Craft install rather than depend on
// whatever plugins happen to be co-installed in a shared app. Checking for
// `vendor/craftcms/cms` (rather than a `craft` executable, which only exists
// at a consuming project's root, never a plugin's own) confirms this really
// is Audit Kit's own root before autoloading from it.
if ($craftBase === false || !is_dir($craftBase . '/vendor/craftcms/cms')) {
    fwrite(STDERR, "Audit Kit test bootstrap could not locate a standalone Craft install at cwd={$craftBase}.\n");
    fwrite(STDERR, "Run tests from Audit Kit's own root, e.g.:\n");
    fwrite(STDERR, "  ddev exec -d /var/www/html/cms/vendor/craftpulse/craft-audit-kit vendor/bin/pest\n");
    fwrite(STDERR, "  ddev exec -d /var/www/html/cms/vendor/craftpulse/craft-audit-kit composer test\n");
    exit(1);
}

$composerLoader = require $craftBase . '/vendor/autoload.php';

// The plugin's autoload-dev mapping never lands in the consuming project's
// vendor dir, so the test namespace is registered here.
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\auditkit\\tests\\', __DIR__ . '/');
}

// Pest auto-discovers tests/Pest.php from the test root once getcwd() is this
// plugin's own root (true for the standalone invocation this file requires
// above), so it is NOT required here as well: doing so registers the same
// `uses()->in(__DIR__)` binding twice and Pest refuses the second, identical
// registration with "Test case can not be used ... already uses the test
// case".

// =============================================================================
// Module registration — Audit Kit itself. craft-pest-core's InstallsCraft
// plugin (which already booted Craft by this point in the Kernel sequence,
// see the class docblock above) installs Craft core and applies any pending
// project config; since 1.1.0 Audit Kit is a library-shipped module, so the
// harness registers it exactly the way a consuming plugin does in production:
// one idempotent `AuditKit::register()` call. Every test in this suite
// reaches through `AuditKit::$plugin` (its Bus, its bridge wiring via
// `AuditKit::init()`), which is unset until registered. Auth Kit is a
// `suggest`-only dependency exercised purely through its own
// composer-autoloaded classes (see `AuthKitBridge`'s class docblock: the
// listener is wired with a compile-time class-string, never an instantiated
// plugin) — it is never installed as a Craft plugin here.
//
// PluginAdoption::adopt() then clears any plugin-era registration left in the
// test database by a pre-1.1.0 suite run (plugins row, plugin-track migration
// history, project config entry). On an already-adopted database it is a
// no-op — which doubles as a standing smoke test of the adoption helper's
// idempotency on every suite run.
// =============================================================================

if (Craft::$app->getIsInstalled(true)) {
    craftpulse\auditkit\AuditKit::register();
    craftpulse\auditkit\helpers\PluginAdoption::adopt();
}
