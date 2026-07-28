<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(): View
    {
        $articles = Article::query()
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->latest('published_at')
            ->paginate(9);

        return view('articles.index', compact('articles'));
    }

    public function show(Article $article): View
    {
        abort_unless(
            $article->status === 'published'
            && $article->published_at
            && $article->published_at->lte(now()),
            404,
        );

        $related = Article::query()
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->where('id', '!=', $article->id)
            ->latest('published_at')
            ->limit(3)
            ->get();

        return view('articles.show', compact('article', 'related'));
    }
}
