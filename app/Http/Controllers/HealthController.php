<?php
namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = []; $status = 200;
        try {
            DB::select('select 1'); $checks['database']='ok';
            foreach (['services','service_packages','inquiries','service_orders','invoices','payments','expenses','users'] as $table) {
                if (! Schema::hasTable($table)) { throw new \RuntimeException('missing_schema'); }
            }
            $checks['schema']='ok';
        } catch (\Throwable) { $checks['database_or_schema']='error'; $status=503; }
        try {
            $file='health/'.Str::uuid().'.txt';
            if (! Storage::disk('local')->put($file,'ok')) { throw new \RuntimeException('storage_write_failed'); }
            Storage::disk('local')->delete($file); $checks['storage']='ok';
        } catch (\Throwable) { $checks['storage']='error'; $status=503; }
        return response()->json(['status'=>$status===200?'healthy':'unhealthy','version'=>'23.0.0-simple','checks'=>$checks],$status);
    }
}
