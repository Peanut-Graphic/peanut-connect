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

    /** Option: normalized paths currently flagged ready for review. */
    const READY_OPTION = 'peanut_connect_approvals_ready';

    /** Option: notification settings ['email' => string, 'digest' => bool]. */
    const NOTIFY_OPTION = 'peanut_connect_approvals_notify';

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

    /**
     * Coerce a raw input (scalar or array) to a sanitized string array. Used
     * for admin form fields that may arrive as scalars if the browser/proxy
     * garbles the POST data. Returns empty array if input is not array-like.
     */
    public static function coerce_string_list($raw): array {
        return array_map('sanitize_text_field', is_array($raw) ? array_values($raw) : []);
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
    public static function record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at, ?int $note_id = null, array $snapshot = []): array {
        $state  = self::normalize_page_state($state);
        $vote   = $vote === 'no' ? 'no' : 'yes';
        $reason = $vote === 'no' ? trim($reason) : '';

        $state['votes'][$approver_id] = [
            'vote'          => $vote,
            'at'            => $at,
            'author_key'    => $author_key,
            'reason'        => $reason,
            'note_id'       => $note_id,
            'post_id'       => (int) ($snapshot['post_id'] ?? 0),
            'post_modified' => (string) ($snapshot['post_modified'] ?? ''),
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

    /**
     * A vote is stale when the page's post was edited after the vote. Votes
     * without a snapshot (3.33.0-era, or URLs that don't resolve to a post)
     * are never stale, and a page that no longer resolves can't invalidate
     * old votes.
     */
    public static function compute_stale(array $vote, string $current_modified): bool {
        $snapshot = isset($vote['post_modified']) ? (string) $vote['post_modified'] : '';
        return $snapshot !== '' && $current_modified !== '' && $snapshot !== $current_modified;
    }

    /** Annotate a public votes map with stale flags (and the new modified time when stale). */
    public static function apply_stale(array $votes, string $current_modified): array {
        foreach ($votes as $id => $vote) {
            if (! is_array($vote)) {
                continue;
            }
            $votes[$id]['stale'] = self::compute_stale($vote, $current_modified);
            if ($votes[$id]['stale']) {
                $votes[$id]['modified_at'] = $current_modified;
            }
        }
        return $votes;
    }

    /**
     * Fully approved = every configured approver holds a fresh YES.
     * $votes is the public projection with 'stale' already applied.
     * No approvers configured means never all-green.
     */
    public static function compute_all_green(array $approvers, array $votes): bool {
        if ($approvers === []) {
            return false;
        }
        foreach ($approvers as $row) {
            $vote = $votes[$row['id']] ?? null;
            if (! is_array($vote) || ($vote['vote'] ?? '') !== 'yes' || ! empty($vote['stale'])) {
                return false;
            }
        }
        return true;
    }

    /** Coerce notification settings to their exact shape. */
    public static function sanitize_notify_settings($raw): array {
        $raw = is_array($raw) ? $raw : [];
        return [
            'email'  => sanitize_email((string) ($raw['email'] ?? '')),
            'digest' => ! empty($raw['digest']),
        ];
    }

    /** Normalized, deduped ready-for-review path list. */
    public static function sanitize_ready_list($raw): array {
        $out = [];
        foreach ((is_array($raw) ? $raw : []) as $path) {
            $out[] = self::normalize_path($path);
        }
        return array_values(array_unique($out));
    }

    /**
     * Digest body lines: one per ready page still awaiting sign-off,
     * naming the approvers (by initials) whose fresh YES is missing.
     * Pages already fully approved produce no line.
     */
    public static function build_digest_lines(array $ready_paths, array $pages_votes, array $approvers): array {
        $lines = [];
        foreach ($ready_paths as $path) {
            $votes = $pages_votes[$path] ?? [];
            if (self::compute_all_green($approvers, $votes)) {
                continue;
            }
            $awaiting = [];
            foreach ($approvers as $row) {
                $vote = $votes[$row['id']] ?? null;
                if (! is_array($vote) || ($vote['vote'] ?? '') !== 'yes' || ! empty($vote['stale'])) {
                    $awaiting[] = $row['initials'];
                }
            }
            $lines[] = $path . ' — awaiting: ' . implode(', ', $awaiting);
        }
        return $lines;
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

    /**
     * Resolve a page path to its post + modified time (GMT). URLs that
     * don't map to a post return the empty snapshot — such pages simply
     * never go stale. Query strings never distinguish posts, so they're
     * dropped before resolving.
     */
    public static function page_snapshot(string $path): array {
        $bare = strtok($path, '?');
        $post_id = function_exists('url_to_postid') ? (int) url_to_postid(home_url($bare)) : 0;
        $modified = '';
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post && ! empty($post->post_modified_gmt)) {
                $modified = (string) $post->post_modified_gmt;
            }
        }
        return ['post_id' => $post_id, 'post_modified' => $modified];
    }

    /** The page's CURRENT modified time, for staleness comparison on reads. */
    public static function current_modified(string $path): string {
        return self::page_snapshot($path)['post_modified'];
    }

    public static function ready_list(): array {
        return self::sanitize_ready_list(get_option(self::READY_OPTION, []));
    }

    /** Flag/unflag a page ready-for-review; returns the new list. */
    public static function set_ready(string $path, bool $ready): array {
        $path = self::normalize_path($path);
        $list = self::ready_list();
        $list = array_values(array_diff($list, [$path]));
        if ($ready) {
            $list[] = $path;
        }
        update_option(self::READY_OPTION, $list, false);
        return $list;
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
            $path  = self::normalize_path($path_raw);
            $state = self::page_state($path);
            return new \WP_REST_Response([
                'approvers' => $approvers,
                'votes'     => self::apply_stale(self::public_votes($state['votes']), self::current_modified($path)),
                'ready'     => self::ready_list(),
            ], 200);
        }

        $pages = [];
        foreach (self::all_pages_state() as $path => $state) {
            if ($state['votes'] !== []) {
                $pages[$path] = self::apply_stale(self::public_votes($state['votes']), self::current_modified($path));
            }
        }
        return new \WP_REST_Response(['approvers' => $approvers, 'pages' => $pages, 'ready' => self::ready_list()], 200);
    }

    /** Whitelist + coerce a vote request body. Pure — unit-tested. */
    public static function build_vote_input(array $req): array {
        return [
            'path'        => self::normalize_path($req['path'] ?? null),
            'page_title'  => isset($req['page_title']) ? (string) $req['page_title'] : '',
            'approver_id' => strtolower((string) ($req['approver_id'] ?? '')),
            'vote'        => (($req['vote'] ?? '') === 'no') ? 'no' : 'yes',
            'reason'      => substr(trim((string) ($req['reason'] ?? '')), 0, 2000),
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

        $current_modified = self::current_modified($in['path']);
        $before = self::apply_stale(self::public_votes(self::page_state($in['path'])['votes']), $current_modified);
        $was_green = self::compute_all_green($approvers, $before);

        $state = self::record_vote(
            self::page_state($in['path']),
            $approver['id'],
            $in['vote'],
            $in['reason'],
            $in['author_key'],
            gmdate('Y-m-d H:i:s'),
            $note_id,
            self::page_snapshot($in['path'])
        );
        self::save_page_state($in['path'], $state);

        $votes = self::apply_stale(self::public_votes($state['votes']), $current_modified);
        $is_green = self::compute_all_green($approvers, $votes);
        $became_green = $is_green && ! $was_green;
        if ($is_green) {
            self::set_ready($in['path'], false); // sign-off complete: leave the queue
        }
        do_action('peanut_connect_approvals_vote', $in['path'], $approver, $in['vote'], $in['reason'], $votes, $became_green);

        return new \WP_REST_Response([
            'success' => true,
            'votes'   => $votes,
            'note_id' => $note_id,
            'ready'   => self::ready_list(),
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

    /**
     * "Approvers" section on the Mark It Up admin page: manage the
     * name+initials rows the widget renders as chips, and reset recorded
     * approvals (per page or site-wide). Admin-only, nonce-gated, own form.
     */
    public static function render_admin_section(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = self::handle_admin_post();

        $approvers = self::approvers();
        $pages     = array_keys(self::all_pages_state());
        sort($pages);
        ?>
        <hr />
        <h2><?php esc_html_e('Approvers', 'peanut-connect'); ?></h2>
        <?php if ($notice !== '') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <p style="max-width:640px">
            <?php esc_html_e('Approvers appear as initials chips in the Mark It Up panel ("Click your initials to approve"). Anyone with review access can click a chip — this is a lightweight sign-off, not an authenticated signature.', 'peanut-connect'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('pca_approvers'); ?>
            <table class="widefat striped" style="max-width:640px">
                <thead><tr>
                    <th><?php esc_html_e('Name', 'peanut-connect'); ?></th>
                    <th style="width:90px"><?php esc_html_e('Initials', 'peanut-connect'); ?></th>
                    <th style="width:150px"><?php esc_html_e('Order / remove', 'peanut-connect'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (empty($approvers)) : ?>
                    <tr><td colspan="3"><?php esc_html_e('No approvers yet — add one below.', 'peanut-connect'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($approvers as $i => $row) : ?>
                    <tr>
                        <td>
                            <input type="hidden" name="pca_id[]" value="<?php echo esc_attr($row['id']); ?>" />
                            <input type="text" name="pca_name[]" value="<?php echo esc_attr($row['name']); ?>" class="regular-text" />
                        </td>
                        <td><input type="text" name="pca_initials[]" value="<?php echo esc_attr($row['initials']); ?>" size="4" maxlength="3" /></td>
                        <td>
                            <button type="submit" name="pca_action" value="up-<?php echo esc_attr((string) $i); ?>" class="button" <?php disabled($i === 0); ?> aria-label="<?php esc_attr_e('Move up', 'peanut-connect'); ?>">&uarr;</button>
                            <button type="submit" name="pca_action" value="down-<?php echo esc_attr((string) $i); ?>" class="button" <?php disabled($i === count($approvers) - 1); ?> aria-label="<?php esc_attr_e('Move down', 'peanut-connect'); ?>">&darr;</button>
                            <button type="submit" name="pca_action" value="remove-<?php echo esc_attr((string) $i); ?>" class="button"><?php esc_html_e('Remove', 'peanut-connect'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><input type="text" name="pca_new_name" value="" class="regular-text" placeholder="<?php esc_attr_e('New approver name', 'peanut-connect'); ?>" /></td>
                    <td><input type="text" name="pca_new_initials" value="" size="4" maxlength="3" placeholder="<?php esc_attr_e('NH', 'peanut-connect'); ?>" /></td>
                    <td><button type="submit" name="pca_action" value="add" class="button"><?php esc_html_e('Add', 'peanut-connect'); ?></button></td>
                </tr>
                </tbody>
            </table>
            <p><button type="submit" name="pca_action" value="save" class="button button-primary"><?php esc_html_e('Save approvers', 'peanut-connect'); ?></button></p>

            <h3><?php esc_html_e('Reset approvals', 'peanut-connect'); ?></h3>
            <p style="max-width:640px" class="description">
                <?php esc_html_e('Start a fresh review round after making changes. Resets clear the current chips; the full history (including the reset) stays recorded.', 'peanut-connect'); ?>
            </p>
            <p>
                <select name="pca_reset_path">
                    <option value=""><?php esc_html_e('Whole site (every page)', 'peanut-connect'); ?></option>
                    <?php foreach ($pages as $page_path) : ?>
                        <option value="<?php echo esc_attr($page_path); ?>"><?php echo esc_html($page_path); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin-left:8px">
                    <input type="checkbox" name="pca_reset_confirm" value="1" />
                    <?php esc_html_e('Yes, clear these approvals', 'peanut-connect'); ?>
                </label>
                <button type="submit" name="pca_action" value="reset" class="button"><?php esc_html_e('Reset', 'peanut-connect'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Handle the Approvers form. Returns a success notice string ('' = no
     * post handled). Every branch re-sanitizes through sanitize_approvers.
     */
    private static function handle_admin_post(): string {
        if (empty($_POST['pca_action']) || ! check_admin_referer('pca_approvers')) {
            return '';
        }
        $action = sanitize_text_field(wp_unslash($_POST['pca_action']));

        // Rebuild rows from the posted table (keeps in-flight edits on every action).
        $ids      = self::coerce_string_list(wp_unslash($_POST['pca_id'] ?? []));
        $names    = self::coerce_string_list(wp_unslash($_POST['pca_name'] ?? []));
        $initials = self::coerce_string_list(wp_unslash($_POST['pca_initials'] ?? []));
        $rows     = [];
        foreach ($names as $i => $name) {
            $rows[] = ['id' => $ids[$i] ?? '', 'name' => $name, 'initials' => $initials[$i] ?? ''];
        }

        if ($action === 'add') {
            $rows[] = [
                'name'     => sanitize_text_field(wp_unslash($_POST['pca_new_name'] ?? '')),
                'initials' => sanitize_text_field(wp_unslash($_POST['pca_new_initials'] ?? '')),
            ];
        } elseif (preg_match('/^remove-(\d+)$/', $action, $m)) {
            array_splice($rows, (int) $m[1], 1);
        } elseif (preg_match('/^(up|down)-(\d+)$/', $action, $m)) {
            $i = (int) $m[2];
            $j = $m[1] === 'up' ? $i - 1 : $i + 1;
            if (isset($rows[$i], $rows[$j])) {
                [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
            }
        } elseif ($action === 'reset') {
            if (empty($_POST['pca_reset_confirm'])) {
                return __('Reset skipped — tick the confirmation box first.', 'peanut-connect');
            }
            $path = sanitize_text_field(wp_unslash($_POST['pca_reset_path'] ?? ''));
            $at   = gmdate('Y-m-d H:i:s');
            $all  = [];
            foreach (self::all_pages_state() as $p => $state) {
                $all[$p] = ($path === '' || $p === $path) ? self::apply_reset($state, null, 'admin', $at) : $state;
            }
            update_option(self::APPROVALS_OPTION, $all, false);
            return $path === ''
                ? __('All approvals reset.', 'peanut-connect')
                : sprintf(__('Approvals reset for %s.', 'peanut-connect'), $path);
        }

        update_option(self::APPROVERS_OPTION, self::sanitize_approvers($rows), false);
        return __('Approvers saved.', 'peanut-connect');
    }
}
