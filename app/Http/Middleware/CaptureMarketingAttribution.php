<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CaptureMarketingAttribution
{
    public function __construct(private readonly FeatureFlagService $features)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('GET')
            && $this->features->enabled('campaign_tracking')
            && ! $request->is('admin', 'admin/*', 'mitra', 'mitra/*', 'tagihan/*', 'penawaran/*', 'kwitansi/*', 'pelanggan/*', 'healthz', 'crm-document/*')
        ) {
            $current = (array) $request->session()->get('marketing_attribution', []);
            $mapping = [
                'utm_source' => 120,
                'utm_medium' => 120,
                'utm_campaign' => 160,
                'utm_term' => 160,
                'utm_content' => 160,
            ];

            foreach ($mapping as $key => $limit) {
                $value = trim((string) $request->query($key));
                if ($value !== '') {
                    $current[$key] = Str::limit($value, $limit, '');
                }
            }

            if (empty($current['landing_path'])) {
                $current['landing_path'] = Str::limit('/'.$request->path(), 1024, '');
            }

            $request->session()->put('marketing_attribution', $current);
        }

        return $next($request);
    }
}
