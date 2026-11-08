<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            // The examples are the top-level categories: a subcategory (Football, Lebanon & Levant) has a sort order of its own inside its parent and would crowd them out.
            'categories' => Category::active()->topLevel()->orderBy('sort_order')->orderBy('id')->limit(6)->get(),
            'howVideo' => $this->howVideo(),
        ]);
    }

    /**
     * The "See how it works" clip, if one has been put in public/media: how-it-works.mp4, an optional poster (how-it-works.jpg, .png or .webp) and optional
     * captions per language (how-it-works.en.vtt, how-it-works.ar.vtt). Without the mp4 the link just scrolls to the three steps.
     *
     * @return array{src: string, poster: string|null, captions: list<array{src: string, lang: string, label: string}>}|null
     */
    private function howVideo(): ?array
    {
        if (! is_file(public_path('media/how-it-works.mp4'))) {
            return null;
        }

        $poster = null;
        foreach (['jpg', 'png', 'webp'] as $extension) {
            if (is_file(public_path("media/how-it-works.{$extension}"))) {
                $poster = $extension;
                break;
            }
        }

        $captions = [];
        foreach (array_keys((array) config('locales.supported')) as $locale) {
            if (is_file(public_path("media/how-it-works.{$locale}.vtt"))) {
                $captions[] = ['src' => asset("media/how-it-works.{$locale}.vtt"), 'lang' => (string) $locale, 'label' => (string) config("locales.supported.{$locale}.name", $locale)];
            }
        }

        return ['src' => asset('media/how-it-works.mp4'), 'poster' => $poster ? asset("media/how-it-works.{$poster}") : null, 'captions' => $captions];
    }
}
