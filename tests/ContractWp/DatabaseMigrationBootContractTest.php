<?php
/**
 * Real-WordPress contract test: the schema migration must actually RUN when
 * the plugin boots, not merely be registered.
 *
 * The bug this pins: the plugin boots via peanut_connect_init() on the `init`
 * hook (chosen for translation loading), which fires AFTER plugins_loaded.
 * From v3.7.24 until the fix, Peanut_Connect_Database::init() did only
 *
 *     add_action('plugins_loaded', [__CLASS__, 'check_db_version']);
 *
 * -- registering on a hook that had already finished firing. So
 * check_db_version(), the drift self-heal, and the dominionenergyptr repair
 * NEVER executed on any web request. Sites whose schema drifted (missing
 * `event_name`) failed every event INSERT forever while a fully working
 * self-heal sat unreachable beside them: the canary logged 7,600 of those
 * failures over two days, and only a direct manual call healed it.
 *
 * The test reproduces the real boot ordering: plugins_loaded has already
 * fired by the time the WordPress test bootstrap hands control to us, exactly
 * as it has when the plugin's `init`-hook boot calls Database::init().
 */

namespace Peanut\Connect\Tests\ContractWp;

use Peanut_Connect_Database;
use WP_UnitTestCase;

class DatabaseMigrationBootContractTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Simulate a never-migrated / drifted install: no recorded version,
        // no cached "schema ok" verdict.
        delete_option(Peanut_Connect_Database::DB_VERSION_OPTION);
        delete_transient(Peanut_Connect_Database::SCHEMA_OK_TRANSIENT);
    }

    public function test_init_runs_the_migration_even_though_plugins_loaded_already_fired(): void
    {
        // Precondition of the bug: by the time the plugin boots on `init`,
        // plugins_loaded is history. If this ever stops holding, the guard
        // in Database::init() changes meaning and this test must be rethought.
        $this->assertGreaterThan(
            0,
            did_action('plugins_loaded'),
            'test premise: plugins_loaded must already have fired, as it has during the real init-hook boot'
        );

        Peanut_Connect_Database::init();

        // The broken version registered a callback that could never fire and
        // returned; the option stayed absent until someone called the
        // migration by hand. The fixed version must have migrated ALREADY.
        $this->assertSame(
            Peanut_Connect_Database::DB_VERSION,
            get_option(Peanut_Connect_Database::DB_VERSION_OPTION),
            'Database::init() must run check_db_version() immediately when plugins_loaded has already fired -- an add_action on a finished hook is a silent no-op'
        );
    }

    public function test_migrated_schema_actually_has_the_event_name_column(): void
    {
        global $wpdb;

        Peanut_Connect_Database::init();

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $wpdb->dbname,
            $wpdb->prefix . 'peanut_connect_events',
            'event_name'
        ));

        $this->assertSame(
            1,
            $exists,
            'the events table must carry event_name after boot -- its absence is the exact drift that failed every INSERT on the canary and dominionenergyptr.com'
        );
    }
}
