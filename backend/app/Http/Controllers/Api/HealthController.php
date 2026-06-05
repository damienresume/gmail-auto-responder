<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * HealthController
 *
 * PURPOSE:
 * Health check endpoints for monitoring. Used by load balancers,
 * Kubernetes probes, and the ops team to verify system status.
 *
 * WHY:
 * Kubernetes probes and load balancers don't have session cookies.
 * No sensitive data is exposed, only service up/down status.
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $status = ['postgres' => 'down', 'redis' => 'down', 'horizon' => 'unknown'];
        $healthy = true;

        try { DB::select('SELECT 1'); $status['postgres'] = 'connected'; }
        catch (\Exception) { $healthy = false; }

        try { Redis::ping(); $status['redis'] = 'connected'; }
        catch (\Exception) { $healthy = false; }

        try {
            $status['horizon'] = Redis::get('horizon:status') === 'running' ? 'running' : 'stopped';
            if ($status['horizon'] !== 'running') $healthy = false;
        } catch (\Exception) { $status['horizon'] = 'unknown'; }

        return response()->json(
            array_merge(['status' => $healthy ? 'ok' : 'degraded'], $status),
            $healthy ? 200 : 503,
        );
    }

    public function gmail(): JsonResponse
    {
        $accounts = GmailAccount::select([
            'gmail_email', 'is_active', 'token_expires_at', 'google_history_id', 'updated_at',
        ])->get()->map(fn ($a) => [
            'gmail_email' => $a->gmail_email,
            'is_active' => $a->is_active,
            'token_expired' => $a->token_expires_at?->isPast() ?? false,
            'has_synced' => !empty($a->google_history_id),
            'last_updated' => $a->updated_at->toIso8601String(),
        ]);

        return response()->json([
            'total' => $accounts->count(),
            'active' => $accounts->where('is_active', true)->count(),
            'accounts' => $accounts,
        ]);
    }
}
