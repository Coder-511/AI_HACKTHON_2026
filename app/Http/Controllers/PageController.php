<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\Report;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return view('home', ['news' => News::whereNotNull('published_at')->where('published_at', '<=', now())->latest('published_at')->limit(6)->get(),
            'metrics' => ['public' => Report::whereNotNull('published_at')->count(), 'resolved' => Report::whereNotNull('published_at')->where('status', 'resolved')->count()]]);
    }

    public function privacy(): View
    {
        return view('privacy');
    }
}
