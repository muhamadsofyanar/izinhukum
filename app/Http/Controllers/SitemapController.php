<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Service;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $services = Service::where('is_active', true)->orderBy('sort_order')->get();
        $articles = Article::where('status', 'published')
            ->where('published_at', '<=', now())
            ->latest('published_at')
            ->get();

        return response()
            ->view('sitemap', compact('services', 'articles'))
            ->header('Content-Type', 'application/xml');
    }
}
