<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Models\AuditEntry;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogger
{
    public function record(string $event, string $outcome, ?string $serverUuid = null, ?int $accountId = null, ?string $ip = null, ?string $message = null): void
    {
        try {
            AuditEntry::query()->create([
                'server_uuid' => $serverUuid,
                'account_id' => $accountId,
                'event' => $event,
                'outcome' => $outcome,
                'ip' => $ip,
                'message' => $message === null ? null : mb_substr($message, 0, 255),
            ]);
        } catch (Throwable $exception) {
            // Auditing must never break token issuance.
            Log::warning('[hytale-hosting] Could not write audit entry: ' . $exception->getMessage());
        }
    }

    public function prune(int $days): int
    {
        return AuditEntry::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
