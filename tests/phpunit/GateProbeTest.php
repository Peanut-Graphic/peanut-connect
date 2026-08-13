<?php

/**
 * TIER 2 AUDIT PROBE — deliberately failing. NOT FOR MERGE.
 *
 * peanut-connect ran a full test suite that did NOT gate merges: only
 * `gitleaks` and `require-regression-test` were required, so a red suite could
 * merge and ship to ~29 client sites. That was fixed on 2026-08-12 by adding
 * `php-tests` (and the other jobs) to main's required contexts.
 *
 * Adding a check to the required list proves the NAME matches. It does not
 * prove the check can fail, or that a failure actually blocks the merge
 * button. A required check that cannot go red is worse than no check, because
 * it manufactures confidence — which is the exact failure class this audit
 * exists to find.
 *
 * So: this test fails on purpose. The probe passes if the PR carrying it goes
 * RED on `php-tests` and reports mergeStateStatus BLOCKED. Then it is deleted.
 */
class GateProbeTest extends \PHPUnit\Framework\TestCase
{
    public function test_the_required_php_tests_check_can_actually_fail(): void
    {
        $this->assertTrue(
            false,
            'Deliberate failure: proving the newly-required php-tests check gates merges.'
        );
    }
}
