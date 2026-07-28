<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $services = Service::where('is_active', true)->orderBy('sort_order')->get();

        return response()
            ->view('sitemap', compact('services'))
            ->header('Content-Type', 'application/xml');
    }
}
