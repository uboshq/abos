<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Integrity\IntegrityRegistry;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * খাতা নিজেই মেলে — চালিয়ে দেখার পর্দা।
 *
 * ── কেন সতর্কবার্তা নয়, একটা পর্দা ───────────────────────────────────
 * স্পেক চেয়েছিল রিপোর্টের মাথায় "Data Quality Warning"। কিন্তু ভাঙা
 * খাতা নিয়ে সতর্ক করা ভুল সমাধান: সতর্কবার্তা দুই সপ্তাহে অদৃশ্য হয়ে
 * যায় — মানুষ ওটা পড়া বন্ধ করে দেয়, আর তারপর ওটা থাকা না-থাকা সমান।
 * আর যেটা প্রতিটা রিপোর্টের মাথায় বসে, সেটা সবচেয়ে দ্রুত অদৃশ্য হয়।
 *
 * ── কেন কোড ঠিক থাকলেও এটা লাগে ─────────────────────────────────────
 * ১,২৮২টা পরীক্ষা বলে কোডটা ঠিক। কিন্তু চালু খাতায় গরমিল ঢোকার পথ
 * আরও আছে: হাতে চালানো SQL, অসম্পূর্ণ মাইগ্রেশন, আধেক লেখা একটা
 * ট্রানজেকশন, বা এমন একটা বাগ যেটা সারানোর আগেই কিছু সারি লিখে
 * ফেলেছে। কোড সারালে পুরনো সারিগুলো নিজে থেকে ঠিক হয় না — এই রিপোতেই
 * সেটা ঘটেছে, যখন কাউন্টারের নগদ একটা গ্রুপ-খাতে বসছিল।
 *
 * ── কেন প্রতিটা অমিলের নাম-ধাম দেখানো হয় ────────────────────────────
 * "৩টা বিলে গরমিল" বললে কাজ এগোয় না — কোন তিনটা, সেটাই তো প্রশ্ন।
 * নিয়ম ১ (প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়) এখানেই সবচেয়ে জরুরি,
 * কারণ এখানে সংখ্যাটা দেখার জন্য নয়, সারানোর জন্য।
 */
class BooksIntegrityController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly IntegrityRegistry $registry,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * হিসাব দেখার চাবি — কিন্তু প্রতিটা যাচাইয়ের নিজেরও একটা আছে।
         *
         * পর্দাটা খুলতে `accounts.report` লাগে, আর ভেতরে কেবল সেই
         * যাচাইগুলোই চলে যেগুলোর চাবি ব্যবহারকারীর আছে। দুই স্তরেই
         * দরকার: শুধু পর্দার চাবি দেখলে হিসাবরক্ষক বিক্রয় বিলের ভাঙা
         * তালিকাও দেখে ফেলতেন, আর শুধু যাচাইয়ের চাবি দেখলে যাঁর
         * কোনোটাই নেই তিনিও পর্দাটা খুলে ফেলতেন।
         */
        return [new Middleware('can:accounts.report')];
    }

    public function __invoke(Request $request): View
    {
        $checks = $this->registry->forUser($request->user());

        $results = [];

        foreach ($checks as $check) {
            $findings = $check->run();

            $results[] = [
                'check' => $check,
                'findings' => $findings,
                'ok' => $findings === [],
            ];
        }

        /*
         * ভাঙাগুলো আগে।
         *
         * সবুজ সারিগুলোও থাকে — "কী কী দেখা হয়েছে" জানা না থাকলে
         * খালি পর্দা দেখে বোঝা যেত না যাচাই চলেছে নাকি চলেইনি, আর
         * ওই দুইটা দেখতে হুবহু এক।
         */
        usort($results, fn (array $a, array $b) => [$a['ok'], $a['check']->key] <=> [$b['ok'], $b['check']->key]);

        return view('accounts::integrity.index', [
            'menu' => $this->menu->forUser($request->user()),
            'results' => $results,
            'broken' => count(array_filter($results, fn (array $r) => ! $r['ok'])),
        ]);
    }
}
