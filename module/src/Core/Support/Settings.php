<?php

namespace HytaleHosting\Core\Support;

use HytaleHosting\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Module settings, stored in the module's own table so v1 and v2 behave the same.
 */
class Settings
{
    private const CACHE_KEY = 'hytale-hosting:settings';

    /** @var array<string, array{default: mixed, rules: array, label: string, help: string}> */
    public const DEFINITIONS = [
        'broker_url' => [
            'default' => '',
            'rules' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'label' => 'Broker URL',
            'help' => 'URL the game servers call for session tokens. Leave empty to use APP_URL/hytale-broker/v1/session. Set this when nodes reach the Panel on an internal address.',
        ],
        'rate_limit_per_10min' => [
            'default' => 6,
            'rules' => ['required', 'integer', 'min:1', 'max:60'],
            'label' => 'Sessions per server per 10 minutes',
            'help' => 'Requests above this are refused with HTTP 429.',
        ],
        'max_sessions_per_day' => [
            'default' => 48,
            'rules' => ['required', 'integer', 'min:1', 'max:1000'],
            'label' => 'Sessions per server per 24 hours',
            'help' => 'A server that exceeds this is locked until an administrator unlocks it. Protects your license against token harvesting.',
        ],
        'allowed_cidrs' => [
            'default' => '',
            'rules' => ['nullable', 'string', 'max:2000'],
            'label' => 'Allowed source networks',
            'help' => 'Comma separated IPs or CIDR ranges (e.g. your node network). Empty allows any source.',
        ],
        'bind_node_ip' => [
            'default' => false,
            'rules' => ['boolean'],
            'label' => 'Require the server\'s node IP',
            'help' => 'Only accept requests whose source IP is an address of the server\'s node (resolved from its FQDN) or in the allowed networks.',
        ],
        'terminate_previous_session' => [
            'default' => true,
            'rules' => ['boolean'],
            'label' => 'End the previous session on restart',
            'help' => 'Ends a server\'s previous game session when it requests a new one, so restarts do not use up the session limit.',
        ],
        'audit_retention_days' => [
            'default' => 30,
            'rules' => ['required', 'integer', 'min:1', 'max:365'],
            'label' => 'Audit log retention (days)',
            'help' => '',
        ],
    ];

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, self::DEFINITIONS)) {
            throw new \InvalidArgumentException("Unknown setting {$key}.");
        }

        $stored = $this->all();
        $default = self::DEFINITIONS[$key]['default'];

        if (!array_key_exists($key, $stored) || $stored[$key] === null) {
            return $default;
        }

        return match (true) {
            is_bool($default) => filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $stored[$key],
            default => (string) $stored[$key],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @return array<string, array>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $rules[$key] = $definition['rules'];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $values validated values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }

            if (is_bool(self::DEFINITIONS[$key]['default'])) {
                $value = $value ? '1' : '0';
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, ?string>
     */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => Setting::query()->pluck('value', 'key')->all());
    }
}
