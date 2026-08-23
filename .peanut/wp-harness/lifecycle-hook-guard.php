<?php
/**
 * Fail-closed guard for WordPress lifecycle callbacks registered too late.
 *
 * Installed during `muplugins_loaded`, before the plugin under test is
 * required. WordPress declares WP_Hook final, so this test-only decorator
 * forwards its complete public surface and intercepts only add_filter().
 */

if (! class_exists('WP_Hook')) {
    throw new RuntimeException(
        'Peanut lifecycle guard requires WordPress WP_Hook before installation.'
    );
}

final class Peanut_Lifecycle_Hook_Guard
{
    /** @var array<string, array{hook: string, priority: int, current_priority: int|null, callback: string, reason: string}> */
    private static array $violations = [];

    /**
     * Decorate selected hook registries while preserving their exact objects.
     *
     * @param list<string> $hooks
     */
    public static function instrument(array $hooks = ['plugins_loaded', 'init']): void
    {
        global $wp_filter;

        foreach ($hooks as $hook_name) {
            if (! is_string($hook_name) || $hook_name === '') {
                throw new InvalidArgumentException('Lifecycle hook names must be non-empty strings.');
            }

            $existing = $wp_filter[$hook_name] ?? null;
            if ($existing instanceof Peanut_Lifecycle_Tracking_Hook) {
                continue;
            }
            if ($existing !== null && ! $existing instanceof WP_Hook) {
                throw new RuntimeException("Unexpected {$hook_name} hook registry type.");
            }

            $wp_filter[$hook_name] = new Peanut_Lifecycle_Tracking_Hook(
                $hook_name,
                $existing ?? new WP_Hook()
            );
        }
    }

    /**
     * Called by the tracking decorator before WordPress stores a callback.
     *
     * @param callable|array|string|object $callback
     */
    public static function observe_registration(
        string $hook_name,
        $callback,
        int $priority,
        ?int $current_priority
    ): void {
        $reason = null;

        if (did_action($hook_name) > 0 && ! doing_action($hook_name)) {
            $reason = 'target hook already finished';
        } elseif (doing_action($hook_name)
            && $current_priority !== null
            && $priority < $current_priority
        ) {
            $reason = "target priority {$priority} already passed current priority {$current_priority}";
        }

        if ($reason === null) {
            return;
        }

        $callback_name = self::describe_callback($callback);
        $key = implode('|', [
            $hook_name,
            (string) $priority,
            (string) ($current_priority ?? 'none'),
            $callback_name,
            $reason,
        ]);

        self::$violations[$key] = [
            'hook' => $hook_name,
            'priority' => $priority,
            'current_priority' => $current_priority,
            'callback' => $callback_name,
            'reason' => $reason,
        ];
    }

    /**
     * @return list<array{hook: string, priority: int, current_priority: int|null, callback: string, reason: string}>
     */
    public static function violations(): array
    {
        return array_values(self::$violations);
    }

    public static function assert_clean(): void
    {
        if (self::$violations === []) {
            return;
        }

        $lines = ['WordPress lifecycle boot contract failed:'];
        foreach (self::$violations as $violation) {
            $lines[] = sprintf(
                '- %s at %s@%d: %s',
                $violation['callback'],
                $violation['hook'],
                $violation['priority'],
                $violation['reason']
            );
        }
        $lines[] = 'Run the callback immediately when its hook/priority has passed, or register it before boot.';

        throw new RuntimeException(implode("\n", $lines));
    }

    /** Test-only state reset; production bootstraps install the guard once. */
    public static function reset(): void
    {
        self::$violations = [];
    }

    /** @param callable|array|string|object $callback */
    private static function describe_callback($callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback) && count($callback) === 2) {
            $owner = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $owner . '::' . (string) $callback[1];
        }
        if ($callback instanceof Closure) {
            return 'Closure';
        }
        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }

        return get_debug_type($callback);
    }
}

/**
 * Test-only transparent decorator for WordPress's final WP_Hook class.
 *
 * @implements Iterator<int, array<string, array{function: callable, accepted_args: int}>>
 * @implements ArrayAccess<int, array<string, array{function: callable, accepted_args: int}>>
 */
final class Peanut_Lifecycle_Tracking_Hook implements Iterator, ArrayAccess
{
    /** @var array<int, array<string, array{function: callable, accepted_args: int}>> */
    public $callbacks;

    private string $peanut_hook_name;
    private WP_Hook $inner;

    public function __construct(string $hook_name, WP_Hook $inner)
    {
        $this->peanut_hook_name = $hook_name;
        $this->inner = $inner;
        $this->callbacks =& $this->inner->callbacks;
    }

    /** @param callable|array|string|object $callback */
    public function add_filter($hook_name, $callback, $priority, $accepted_args)
    {
        $current = $this->inner->current_priority();
        Peanut_Lifecycle_Hook_Guard::observe_registration(
            $this->peanut_hook_name,
            $callback,
            (int) ($priority ?? 0),
            is_int($current) ? $current : null
        );
        $this->inner->add_filter($hook_name, $callback, $priority, $accepted_args);
    }

    public function remove_filter($hook_name, $callback, $priority)
    {
        return $this->inner->remove_filter($hook_name, $callback, $priority);
    }

    public function has_filter($hook_name = '', $callback = false, $priority = false)
    {
        return $this->inner->has_filter($hook_name, $callback, $priority);
    }

    public function has_filters()
    {
        return $this->inner->has_filters();
    }

    public function remove_all_filters($priority = false)
    {
        return $this->inner->remove_all_filters($priority);
    }

    public function apply_filters($value, $args)
    {
        return $this->inner->apply_filters($value, $args);
    }

    public function do_action($args)
    {
        return $this->inner->do_action($args);
    }

    public function do_all_hook(&$args)
    {
        return $this->inner->do_all_hook($args);
    }

    public function current_priority()
    {
        return $this->inner->current_priority();
    }

    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->inner->offsetExists($offset);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->inner->offsetGet($offset);
    }

    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        return $this->inner->offsetSet($offset, $value);
    }

    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        return $this->inner->offsetUnset($offset);
    }

    #[ReturnTypeWillChange]
    public function current()
    {
        return $this->inner->current();
    }

    #[ReturnTypeWillChange]
    public function next()
    {
        return $this->inner->next();
    }

    #[ReturnTypeWillChange]
    public function key()
    {
        return $this->inner->key();
    }

    #[ReturnTypeWillChange]
    public function valid()
    {
        return $this->inner->valid();
    }

    #[ReturnTypeWillChange]
    public function rewind()
    {
        return $this->inner->rewind();
    }
}
