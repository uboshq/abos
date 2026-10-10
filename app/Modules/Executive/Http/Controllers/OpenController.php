<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\CompanyLens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * সংখ্যা থেকে তার উৎসে — ঐ কোম্পানিতে, ঐ শাখায় বসে, ঐ পাতাটা খোলা।
 *
 * ── ⭐ কেন একটা দরজা, সরাসরি লিংক নয় ──────────────────────────────────
 * অন্য কোম্পানির ড্যাশবোর্ড বা রিপোর্ট চলতি কোম্পানির প্রসঙ্গে খুললে **চলতি**
 * কোম্পানির সংখ্যা দেখাত — মালিক ছকে ফ্যামিলি মার্টের ঘরে চাপলেন, অথচ খুলল ট্রেড
 * ডিপোর বিক্রি। ⓘ তাই আগে কোম্পানি আর শাখা বদলানো হয় — হেডারের সুইচারের
 * হুবহু পথে ([[User::switchCompany()]]), যেটা নিজেই অধিকার যাচাই করে — তারপর পাতা।
 *
 * ⛔ কোম্পানিটা মালিকের কেন্দ্রের নিজের তালিকা থেকেই হতে হয় ([[CompanyLens::companies()]]):
 * সদস্যপদ থাকলেও ওখানে `executive.view` না থাকলে এই দরজা দিয়ে ঢোকা যায় না।
 */
final class OpenController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CompanyLens $lens) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function open(Request $request): RedirectResponse
    {
        /*
         * ⓘ পাতার প্রতিটা ঘর একই ফর্মের একটা বোতাম, আর বোতামের একটাই মান — তাই কোথায় যাবে সেটা
         * একটা কোয়েরি-লেখা হয়ে আসে (`go`), এখানে খুলে বাকি যাচাইয়ের হাতে দেওয়া হয়।
         */
        if (is_string($request->input('go'))) {
            parse_str($request->input('go'), $wanted);
            $request->merge($wanted);
        }

        $data = $request->validate([
            'company' => ['required', 'integer'],
            'branch' => ['nullable', 'integer'],
            'route' => ['required', 'string', 'max:120'],
            'params' => ['nullable', 'array'],
        ]);

        // ⓘ ঠিকানার অংশ কেবল সরল মান — ভিতরে আরেকটা তালিকা নয়
        foreach ($data['params'] ?? [] as $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages(['params' => __('executive::today.not_yours')]);
            }
        }

        $user = $request->user();
        $company = (int) $data['company'];
        $branch = isset($data['branch']) ? (int) $data['branch'] : null;

        if (! in_array($company, array_column($this->lens->companies($user), 'id'), true)) {
            throw ValidationException::withMessages(['company' => __('executive::today.not_yours')]);
        }

        if ($branch !== null && ! in_array($branch, array_column($this->lens->branches($user, $company), 'id'), true)) {
            throw ValidationException::withMessages(['branch' => __('executive::today.not_yours')]);
        }

        // ⓘ কেবল এই অ্যাপের পড়ার পাতা — নাম দিয়ে, ঠিকানা দিয়ে নয়; বাইরের ঠিকানায় পাঠানো যায় না
        $route = Route::getRoutes()->getByName((string) $data['route']);

        if ($route === null || ! in_array('GET', $route->methods(), true) || str_starts_with((string) $data['route'], 'api.')) {
            throw ValidationException::withMessages(['route' => __('executive::today.not_yours')]);
        }

        $user->switchCompany($company, $branch);

        // ⭐ শাখা বাছা মানে দেখাও সেই শাখায়; না বাছলে সব শাখা — হেডারের "সব শাখা"-র একই কাজ
        $user->forceFill(['view_all_branches' => $branch === null])->save();

        return redirect()->route((string) $data['route'], $data['params'] ?? []);
    }
}
