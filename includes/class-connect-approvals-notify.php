<?php
/**
 * Approval notifications — plain-text email on client rejections and on a
 * page reaching full (fresh) approval, plus an optional daily digest of
 * pages still awaiting sign-off. Driven entirely by the
 * peanut_connect_approvals_vote action; mail failures never block a vote.
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Approvals_Notify {

    /** Daily digest cron hook. Scheduled only while the digest is enabled. */
    const CRON_HOOK = 'peanut_connect_approvals_digest';

    public static function init(): void {
        add_action('peanut_connect_approvals_vote', [self::class, 'on_vote'], 10, 6);
        add_action(self::CRON_HOOK, [self::class, 'send_digest']);
    }

    public static function settings(): array {
        return Peanut_Connect_Approvals::sanitize_notify_settings(
            get_option(Peanut_Connect_Approvals::NOTIFY_OPTION, [])
        );
    }

    /** Configured address, falling back to the site admin email. */
    public static function recipient(): string {
        $settings = self::settings();
        return $settings['email'] !== '' ? $settings['email'] : (string) get_option('admin_email', '');
    }

    public static function schedule(bool $enable): void {
        if ($enable) {
            if (! wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        } else {
            self::unschedule();
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // ---- pure mail builders (unit-tested) ----

    public static function build_no_mail(string $site, string $path, string $name, string $reason, string $link): array {
        $body = $name . " requested changes on " . $path . ".\n\n"
            . ($reason !== '' ? "What needs to change:\n" . $reason . "\n\n" : "No reason was given.\n\n")
            . "Review the page:\n" . $link . "\n\n"
            . "The reason was also posted as a Mark It Up note on the page.";
        return [
            'subject' => '[' . $site . '] Changes requested on ' . $path . ' by ' . $name,
            'body'    => $body,
        ];
    }

    public static function build_green_mail(string $site, string $path, string $link): array {
        return [
            'subject' => '[' . $site . '] ' . $path . ' fully approved',
            'body'    => "Every approver has signed off on " . $path . " (all approvals current).\n\n"
                . "View the page:\n" . $link,
        ];
    }

    public static function build_digest_mail(string $site, array $lines): array {
        return [
            'subject' => '[' . $site . '] Pages awaiting approval: ' . count($lines),
            'body'    => "These pages are flagged ready for review and still awaiting sign-off:\n\n"
                . implode("\n", $lines)
                . "\n\nThis digest is sent daily while enabled on the Mark It Up admin page.",
        ];
    }

    // ---- runtime (staging-verified, not unit-tested) ----

    /**
     * Hook callback for peanut_connect_approvals_vote.
     *
     * @param string $path The page path.
     * @param array $approver The approver data.
     * @param string $vote The vote ('yes' or 'no').
     * @param string $reason The rejection reason (empty if approved).
     * @param array $votes The current votes map.
     * @param bool $became_all_green True only when this vote completed the set.
     */
    public static function on_vote($path, $approver, $vote, $reason, $votes, $became_all_green): void {
        $site = (string) get_bloginfo('name');
        $link = home_url((string) $path);
        if ($vote === 'no') {
            $mail = self::build_no_mail($site, (string) $path, (string) $approver['name'], (string) $reason, $link);
            self::send($mail);
        }
        if ($became_all_green) {
            self::send(self::build_green_mail($site, (string) $path, $link));
        }
    }

    public static function send_digest(): void {
        if (! self::settings()['digest']) {
            return;
        }
        $approvers = Peanut_Connect_Approvals::approvers();
        $pages = [];
        foreach (Peanut_Connect_Approvals::all_pages_state() as $path => $state) {
            $pages[$path] = Peanut_Connect_Approvals::apply_stale_live(
                Peanut_Connect_Approvals::public_votes($state['votes'])
            );
        }
        $lines = Peanut_Connect_Approvals::build_digest_lines(
            Peanut_Connect_Approvals::ready_list(),
            $pages,
            $approvers
        );
        if ($lines === []) {
            return;
        }
        self::send(self::build_digest_mail((string) get_bloginfo('name'), $lines));
    }

    private static function send(array $mail): void {
        $to = self::recipient();
        if ($to === '') {
            return;
        }
        if (! wp_mail($to, $mail['subject'], $mail['body'])) {
            error_log('peanut-connect approvals: notification mail failed (' . $mail['subject'] . ')');
        }
    }
}
