<?php
/**
 * Regression guard: the custom cron recurrence must be registered before
 * anything schedules against it.
 *
 * `wp_schedule_event()` validates its recurrence against `wp_get_schedules()`
 * at call time and **silently returns false** for one it does not recognise.
 * `fifteen_minutes` comes from our own `cron_schedules` filter, so registering
 * that filter at the END of init_hooks() -- after the scheduling block -- meant
 * `peanut_connect_hub_sync` was never scheduled on a single site. Its siblings
 * masked the bug: `hourly`, `daily` and `weekly` are core recurrences and need
 * no filter, so heartbeat/cleanup/ml_train all scheduled normally.
 *
 * The damage was downstream and quiet. Nothing syncing meant nothing was ever
 * marked `synced = 1`, and `cleanup_old_records()` only deletes rows WHERE
 * synced = 1 -- so the tracker tables grew without bound. Measured on the
 * fleet: `peanut_connect_hub_sync` scheduled on 0 of 17 installs, and one site
 * carrying 1.42M events / 1.37M visitors, every row synced = 0, oldest
 * 2026-03-13, 416MB of a 711MB database.
 *
 * A source-order assertion is the honest test here: the defect is purely one
 * of registration order inside a single method, and no amount of mocking
 * reproduces it without reimplementing WP's scheduler.
 *
 * @package Peanut_Connect
 */

class Test_Cron_Schedule_Registration_Order extends PHPUnit\Framework\TestCase {

    private function source(): string {
        $path = dirname(__DIR__) . '/peanut-connect.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'could not read peanut-connect.php');
        return $src;
    }

    /**
     * Every custom recurrence we schedule against must be one our filter
     * actually defines -- otherwise the schedule silently never happens.
     */
    public function test_custom_recurrences_used_are_defined_by_the_filter(): void {
        $src = $this->source();

        preg_match_all("/wp_schedule_event\(\s*[^,]+,\s*'([a-z_]+)'/", $src, $used);
        $this->assertNotEmpty($used[1], 'found no wp_schedule_event() calls to check');

        // Recurrences WordPress ships; anything else must come from our filter.
        $core = ['hourly', 'twicedaily', 'daily', 'weekly'];

        preg_match_all("/\\\$schedules\['([a-z_]+)'\]/", $src, $defined);
        $ours = $defined[1];

        foreach (array_unique($used[1]) as $recurrence) {
            if (in_array($recurrence, $core, true)) {
                continue;
            }
            $this->assertContains(
                $recurrence,
                $ours,
                "wp_schedule_event() uses custom recurrence '$recurrence', which add_cron_schedules() never defines — the schedule will silently never be created."
            );
        }
    }

    /**
     * The filter registration must come before the first schedule call that
     * relies on a custom recurrence.
     */
    public function test_cron_schedules_filter_is_registered_before_it_is_used(): void {
        $src = $this->source();

        $filter_pos = strpos($src, "add_filter('cron_schedules'");
        $this->assertNotFalse($filter_pos, "add_filter('cron_schedules', ...) not found");

        $core = ['hourly', 'twicedaily', 'daily', 'weekly'];

        preg_match_all(
            "/wp_schedule_event\(\s*[^,]+,\s*'([a-z_]+)'/",
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        );

        foreach ($m[1] as $i => $hit) {
            $recurrence = $hit[0];
            if (in_array($recurrence, $core, true)) {
                continue;
            }
            $call_pos = $m[0][$i][1];
            $this->assertLessThan(
                $call_pos,
                $filter_pos,
                "add_filter('cron_schedules', ...) is registered AFTER wp_schedule_event(..., '$recurrence', ...). "
                . 'wp_schedule_event() resolves the recurrence at call time and silently returns false for an '
                . 'unknown one, so that event will never be scheduled.'
            );
        }
    }
}
