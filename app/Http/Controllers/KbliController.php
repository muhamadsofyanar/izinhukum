<?php

namespace App\Http\Controllers;

use App\Models\KbliCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KbliController extends Controller
{
    public function index(Request $request): View
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $results = null;

        if (mb_strlen($term) >= 2) {
            $likeTerm = str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $term,
            );

            $results = KbliCode::query()
                ->select([
                    'id',
                    'code',
                    'version',
                    'category_code',
                    'category_title',
                    'title',
                    'description',
                    'risk_levels',
                    'licenses',
                    'source_updated_at',
                ])
                ->where('version', '2025')
                ->where(function ($query) use ($likeTerm): void {
                    $query->where('code', 'like', "%{$likeTerm}%")
                        ->orWhere('title', 'like', "%{$likeTerm}%")
                        ->orWhere('description', 'like', "%{$likeTerm}%");
                })
                ->withCount('scopes')
                ->orderBy('code')
                ->paginate(18)
                ->withQueryString();
        }

        return view('kbli', compact('term', 'results'));
    }

    public function show(string $code): View
    {
        abort_unless(preg_match('/^\d{5}$/', $code) === 1, 404);

        $kbli = KbliCode::query()
            ->where('version', '2025')
            ->where('code', $code)
            ->with(['scopes.profiles'])
            ->firstOrFail();

        return view('kbli-detail', compact('kbli'));
    }
}
