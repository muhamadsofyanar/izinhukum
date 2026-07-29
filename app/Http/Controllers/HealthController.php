<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => 'ok', 'storage' => 'ok'];
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

        return response()->json([
            'status' => $status === 200 ? 'healthy' : 'unhealthy',
            'version' => '10.0.0',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $status);
    }
}
