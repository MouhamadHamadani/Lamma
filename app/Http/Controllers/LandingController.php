<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            'categories' => Category::active()->orderBy('sort_order')->limit(6)->get(),
        ]);
    }
}
