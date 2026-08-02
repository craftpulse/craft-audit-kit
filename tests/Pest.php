<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Pest configuration — binds craft-pest's TestCase AND its `RefreshesDatabase`
 * trait to every test in this suite. `TestCase` alone boots Craft but does
 * NOT wrap tests in a transaction; only `RefreshesDatabase` opens a
 * transaction in `setUp()` and rolls it back in `tearDown()` (see
 * `markhuot\craftpest\test\RefreshesDatabase`). Without it every factory
 * write in this suite committed permanently.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\AuditKit;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        Craft::$app->set('cache', new ArrayCache());

        // Idempotent: a no-op while the Bootstrap-registered module instance
        // is alive, and a re-registration if the harness ever rebuilds the
        // Craft application between tests.
        AuditKit::register();
    })
    ->in(__DIR__);
