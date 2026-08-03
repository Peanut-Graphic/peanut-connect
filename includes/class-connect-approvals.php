<?php
/**
 * Mark It Up approval process — per-page approver chips (YES/NO with
 * timestamped history), stored site-locally in WP options. Approvers are
 * admin-defined name+initials rows (honor system — no WP accounts); the
 * acting browser's author_key is recorded on every action for traceability.
 * Payload shapes mirror a future Hub endpoint so a later sync is a relay
 * swap, not a rewrite.
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Approvals {

    /** Option: ordered approver rows [{id, name, initials}]. */
    const APPROVERS_OPTION = 'peanut_connect_approvers';

    /** Option: map of normalized page path => {votes, history}. */
    const APPROVALS_OPTION = 'peanut_connect_approvals';

    /** Append-only history entries kept per page (newest win). */
    const HISTORY_CAP = 200;

    /** Query params that never distinguish a page (mirror pageKey() in feedback.js). */
    const STRIP_PARAMS = ['pp_review', 'pp_note', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    /**
     * Coerce an approver list (option value or admin form rows) to clean
     * rows. Empty names drop the row; initials are derived from the name
     * when missing, alnum-only, uppercased, max 3 chars; ids are stable
     * slugs, kept when present and deduped when generated.
     */
    public static function sanitize_approvers($raw): array {
        $out  = [];
        $seen = [];
        foreach ((is_array($raw) ? $raw : []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $initials = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['initials'] ?? '')));
            if ($initials === '') {
                $initials = self::derive_initials($name);
            }
            $initials = substr($initials, 0, 3);

            $id = strtolower((string) ($row['id'] ?? ''));
            if ($id === '' || preg_match('/[^a-z0-9\-]/', $id)) {
                $id = self::slugify($name);
            }
            $base = $id;
            for ($n = 2; isset($seen[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $seen[$id] = true;

            $out[] = ['id' => $id, 'name' => $name, 'initials' => $initials];
        }
        return $out;
    }

    private static function derive_initials(string $name): string {
        $letters = '';
        foreach (preg_split('/\s+/', $name) as $word) {
            $first = preg_replace('/[^A-Za-z0-9]/', '', $word);
            if ($first !== '') {
                $letters .= strtoupper($first[0]);
            }
        }
        return $letters !== '' ? $letters : 'X';
    }

    private static function slugify(string $name): string {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'approver';
    }

    /** The configured approver list, always sanitized. */
    public static function approvers(): array {
        return self::sanitize_approvers(get_option(self::APPROVERS_OPTION, []));
    }

    /**
     * Normalize a client-supplied page path to the same key feedback.js
     * pageKey() produces: same-site path only (no scheme, no '//'), with
     * review/tracking params stripped and any remaining query kept.
     */
    public static function normalize_path($raw): string {
        if (! is_string($raw) || $raw === '' || $raw[0] !== '/' || (isset($raw[1]) && $raw[1] === '/')) {
            return '/';
        }
        $raw   = substr($raw, 0, 2000);
        $parts = explode('?', $raw, 2);
        $path  = $parts[0];
        if (! isset($parts[1]) || $parts[1] === '') {
            return $path;
        }
        parse_str($parts[1], $query);
        foreach (self::STRIP_PARAMS as $param) {
            unset($query[$param]);
        }
        $qs = http_build_query($query);
        return $qs === '' ? $path : $path . '?' . $qs;
    }

    /** Missing/corrupt state always normalizes to empty votes + history. */
    public static function normalize_page_state($raw): array {
        $raw = is_array($raw) ? $raw : [];
        return [
            'votes'   => (isset($raw['votes']) && is_array($raw['votes'])) ? $raw['votes'] : [],
            'history' => (isset($raw['history']) && is_array($raw['history'])) ? array_values($raw['history']) : [],
        ];
    }

    /**
     * Pure: apply one vote to a page state. The latest vote per approver is
     * what the chips render; EVERY action (first vote, re-vote, flip) also
     * appends a timestamped history entry, so re-approval date/time is
     * always logged. History is capped at HISTORY_CAP.
     */
    public static function record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at, ?int $note_id = null): array {
        $state  = self::normalize_page_state($state);
        $vote   = $vote === 'no' ? 'no' : 'yes';
        $reason = $vote === 'no' ? trim($reason) : '';

        $state['votes'][$approver_id] = [
            'vote'       => $vote,
            'at'         => $at,
            'author_key' => $author_key,
            'reason'     => $reason,
            'note_id'    => $note_id,
        ];

        $entry = ['approver_id' => $approver_id, 'action' => $vote, 'at' => $at, 'author_key' => $author_key];
        if ($reason !== '') {
            $entry['reason'] = $reason;
        }
        $state['history'][] = $entry;
        $state['history']   = array_slice($state['history'], -self::HISTORY_CAP);

        return $state;
    }

    /**
     * Pure: clear votes (all, or one approver's) and log the reset in
     * history. approver_id null means the whole page.
     */
    public static function apply_reset(array $state, ?string $approver_id, string $author_key, string $at): array {
        $state = self::normalize_page_state($state);
        if ($approver_id === null) {
            $state['votes'] = [];
        } else {
            unset($state['votes'][$approver_id]);
        }
        $state['history'][] = ['approver_id' => $approver_id, 'action' => 'reset', 'at' => $at, 'author_key' => $author_key];
        $state['history']   = array_slice($state['history'], -self::HISTORY_CAP);
        return $state;
    }

    /** Client-facing projection of votes: internal author_key stays server-side. */
    public static function public_votes(array $votes): array {
        $out = [];
        foreach ($votes as $id => $vote) {
            if (is_array($vote)) {
                unset($vote['author_key']);
            }
            $out[$id] = $vote;
        }
        return $out;
    }

    private static function all_state(): array {
        $raw = get_option(self::APPROVALS_OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    /** All recorded pages, each normalized. */
    public static function all_pages_state(): array {
        $out = [];
        foreach (self::all_state() as $path => $state) {
            $out[(string) $path] = self::normalize_page_state($state);
        }
        return $out;
    }

    public static function page_state(string $path): array {
        $all = self::all_state();
        return self::normalize_page_state($all[$path] ?? []);
    }

    public static function save_page_state(string $path, array $state): void {
        $all          = self::all_state();
        $all[$path]   = $state;
        update_option(self::APPROVALS_OPTION, $all, false);
    }
}
