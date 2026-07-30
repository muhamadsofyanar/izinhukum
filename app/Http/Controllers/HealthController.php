<?php

namespace App\Http\Controllers;

use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HealthController extends Controller
{
    public function __invoke(FeatureFlagService $features, StarSenderClient $starSender): JsonResponse
    {
        $checks = ['database' => 'ok', 'storage' => 'ok', 'queue' => 'ok'];
        $status = 200;

        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $checks['database'] = 'error';
            $status = 503;
        }

        try {
            $path = 'health/'.Str::uuid().'.txt';
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);
        } catch (\Throwable) {
            $checks['storage'] = 'error';
            $status = 503;
        }

        try {
            if (! Schema::hasTable('jobs')) {
                $checks['queue'] = 'missing_table';
                $status = 503;
            }
        } catch (\Throwable) {
            $checks['queue'] = 'error';
            $status = 503;
        }

        try {
            $whatsapp = match (true) {
                ! config('starsender.enabled') => 'disabled',
                ! $features->enabled('whatsapp') => 'feature_disabled',
                ! $starSender->hasDeviceKey('transaction') => 'missing_device_key',
                default => 'configured',
            };
        } catch (\Throwable) {
            $whatsapp = 'check_error';
            $status = 503;
        }

        return response()->json([
            'status' => $status === 200 ? 'healthy' : 'unhealthy',
            'version' => '11.3.0',
            'checks' => $checks,
            'integrations' => ['starsender' => $whatsapp],
            'time' => now()->toIso8601String(),
        ], $status);
    }
}
