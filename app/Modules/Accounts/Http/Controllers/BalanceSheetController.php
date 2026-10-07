<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\BalanceSheetService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * স্থিতিপত্র — একটা দিনে ব্যবসা কোথায় দাঁড়িয়ে।
 *
 * ── কেন এটার নিজের নিয়ন্ত্রক, রিপোর্টের সাধারণ পথে নয় ────────────────
 * `/accounts/reports/balance-sheet` ছিল, আর ওটা রিপোর্ট-ইঞ্জিনের একটা
 * সাধারণ টেবিল আঁকত — ডেবিট/ক্রেডিট কলামসহ, সমতল, উপমোট ছাড়া, দায়ের
 * সারি ছাড়া, আর মোট শূন্য না হয়ে।
 *
 * স্থিতিপত্র টেবিল নয়, **বিবৃতি**। দুইটা পক্ষ, ভেতরে ভাগ, প্রতিটার
 * উপমোট, আর শেষে একটা দাবি: সম্পদ = দায় + মূলধন। ইঞ্জিনটাকে সেটা
 * শেখাতে গেলে ওখানে এমন ধারণা ঢুকত যা আর কোনো রিপোর্টের লাগে না।
 *
 * ── পুরনো ঠিকানাটা ভাঙে না ──────────────────────────────────────────
 * `/accounts/reports/balance-sheet` এখন এখানে পাঠায়। কেউ বুকমার্ক করে
 * রাখলে সে নতুন পাতাতেই পৌঁছায়।
 */
class BalanceSheetController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BalanceSheetService $sheet,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report.final')];
    }

    public function show(Request $request): View
    {
        $asOf = $request->query('as_of');

        /*
         * ⭐ পাতার নিজের ছাঁকনি আগে, না থাকলে হেডারে বাছা শাখা (৩০ সেপ্টেম্বর ২০২৬)।
         * ⛔ আগে হেডারটা এই পাতা শুনতই না: মালিক "ময়মনসিংহ" বেছে গোটা কোম্পানির
         * স্থিতিপত্র দেখতেন।
         *
         * ⚠️ আর নাগালের বাইরের শাখা হাতে লিখে চাইলে ৪০৪ — [[ReportEngine]]-এর মতোই;
         * আগে `?branch_id=` দিয়ে অন্য শাখার স্থিতিপত্র খোলা যেত।
         */
        $asked = $request->integer('branch_id') ?: null;
        $scope = app(DataScope::class);

        abort_if($asked !== null && ! $scope->allows($request->user(), UserDataScope::BRANCH, $asked), 404);

        $reach = $scope->idsFor($request->user(), UserDataScope::BRANCH); // ⓘ ড্রপডাউনে নাগাল, বাছাই নয়

        return view('accounts::report.balance-sheet', [
            'menu' => $this->menu->forUser($request->user()),
            // ⓘ না চাইলে শাখাটা সার্ভিস নিজেই হেডার থেকে নেয় — নিয়ম এক জায়গায় ([[BalanceSheetService::build()]])
            'sheet' => $this->sheet->build(is_string($asOf) ? $asOf : null, $asked),
            'branchId' => $asked ?? ViewedBranch::one($request->user()),

            /*
             * শাখার ছাঁকনি — কিন্তু সতর্কবার্তাসহ (পর্দায় লেখা)।
             *
             * এক শাখার স্থিতিপত্র সচরাচর মেলে না: মূলধন, ঋণ ও ব্যাংক
             * কোম্পানির, কোনো এক শাখার নয়। ছাঁকনিটা তবু আছে, কারণ
             * "নেত্রকোনায় কত মজুদ আর কত বকেয়া" প্রশ্নটা সত্যিকারের —
             * কেবল ওটাকে স্থিতিপত্র বলা যায় না।
             */
            'branches' => Branch::query()
                ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
                ->orderBy('name_en')
                ->get(),
        ]);
    }
}
