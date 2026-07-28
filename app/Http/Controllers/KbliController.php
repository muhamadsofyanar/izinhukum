<?php

namespace App\Http\Controllers;

use App\Models\KbliCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KbliController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $results = collect();

        if (mb_strlen($term) >= 2) {
            $results = KbliCode::query()
                ->where(function ($query) use ($term): void {
                    $query->where('code', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                })
                ->orderBy('code')
                ->limit(30)
                ->get();
        }

        return view('kbli', compact('term', 'results'));
    }
}
