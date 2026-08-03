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

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = PEANUT_CONNECT_API_NAMESPACE;

        register_rest_route($ns, '/approvals', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_state'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review'],
        ]);
        register_rest_route($ns, '/approvals/vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'vote'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review'],
        ]);
        // Reset wipes other people's recorded sign-offs: agency only.
        register_rest_route($ns, '/approvals/reset', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review_agency'],
        ]);
    }

    /**
     * ?path=… -> that page's approvers + votes; no path -> every recorded
     * page (the All-pages rollup). author_key never leaves the server.
     */
    public static function get_state(\WP_REST_Request $request) {
        $approvers = self::approvers();
        $path_raw  = $request->get_param('path');

        if (is_string($path_raw) && $path_raw !== '') {
            $state = self::page_state(self::normalize_path($path_raw));
            return new \WP_REST_Response([
                'approvers' => $approvers,
                'votes'     => self::public_votes($state['votes']),
            ], 200);
        }

        $pages = [];
        foreach (self::all_pages_state() as $path => $state) {
            if ($state['votes'] !== []) {
                $pages[$path] = self::public_votes($state['votes']);
            }
        }
        return new \WP_REST_Response(['approvers' => $approvers, 'pages' => $pages], 200);
    }

    /** Whitelist + coerce a vote request body. Pure — unit-tested. */
    public static function build_vote_input(array $req): array {
        return [
            'path'        => self::normalize_path($req['path'] ?? null),
            'page_title'  => isset($req['page_title']) ? (string) $req['page_title'] : '',
            'approver_id' => strtolower((string) ($req['approver_id'] ?? '')),
            'vote'        => (($req['vote'] ?? '') === 'no') ? 'no' : 'yes',
            'reason'      => trim((string) ($req['reason'] ?? '')),
            'author_key'  => substr((string) ($req['author_key'] ?? ''), 0, 64),
        ];
    }

    public static function vote(\WP_REST_Request $request) {
        $in        = self::build_vote_input($request->get_json_params() ?: []);
        $approvers = self::approvers();

        $approver = null;
        foreach ($approvers as $row) {
            if ($row['id'] === $in['approver_id']) {
                $approver = $row;
                break;
            }
        }
        if ($approver === null) {
            return new \WP_Error('pca_bad_approver', __('Unknown approver.', 'peanut-connect'), ['status' => 400]);
        }

        // A NO with a reason becomes a real Mark It Up note so it flows to
        // Hub with everything else. Failure to post the note never blocks
        // the vote — the reason is stored on the approval record too.
        $note_id = null;
        if ($in['vote'] === 'no' && $in['reason'] !== '') {
            $note_id = Peanut_Connect_Feedback::store_note([
                'page_url'    => $in['path'],
                'page_title'  => $in['page_title'],
                'author_name' => $approver['name'],
                'author_key'  => $in['author_key'],
                'body'        => $approver['name'] . ' — needs changes: ' . $in['reason'],
            ]);
        }

        $state = self::record_vote(
            self::page_state($in['path']),
            $approver['id'],
            $in['vote'],
            $in['reason'],
            $in['author_key'],
            gmdate('Y-m-d H:i:s'),
            $note_id
        );
        self::save_page_state($in['path'], $state);

        return new \WP_REST_Response([
            'success' => true,
            'votes'   => self::public_votes($state['votes']),
            'note_id' => $note_id,
        ], 200);
    }

    /** Agency-only (route gate). path omitted/empty = whole site. */
    public static function reset(\WP_REST_Request $request) {
        $in          = $request->get_json_params() ?: [];
        $approver_id = (isset($in['approver_id']) && is_string($in['approver_id']) && $in['approver_id'] !== '') ? strtolower($in['approver_id']) : null;
        $author_key  = substr((string) ($in['author_key'] ?? ''), 0, 64);
        $at          = gmdate('Y-m-d H:i:s');

        if (empty($in['path'])) {
            $all = [];
            foreach (self::all_pages_state() as $path => $state) {
                $all[$path] = self::apply_reset($state, $approver_id, $author_key, $at);
            }
            update_option(self::APPROVALS_OPTION, $all, false);
            return new \WP_REST_Response(['success' => true], 200);
        }

        $path  = self::normalize_path((string) $in['path']);
        $state = self::apply_reset(self::page_state($path), $approver_id, $author_key, $at);
        self::save_page_state($path, $state);
        return new \WP_REST_Response(['success' => true, 'votes' => self::public_votes($state['votes'])], 200);
    }
}
