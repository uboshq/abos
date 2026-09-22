<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * লাভ বণ্টনের পর্দা।
 *
 * ── ⭐ মালিকের নকশা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"টাকাটা তুলে নেবেন"*, আর *"র থাকলে বছর শেষে capital-এ যোগ হবে"*।
 * ⓘ তাই ঘোষণার দিন অঙ্কটা দায়ে বসে, মূলধনে নয় —
 * [[ProfitDistribution]]-এর ব্যাখ্যাটা পড়ার মতো।
 *
 * ── ⛔ কেন অঙ্কটা হাতে বসাতে হয় ────────────────────────────────────
 * বছরের মুনাফা সিস্টেম নিজে গুনতে পারে, কিন্তু **কতটা ভাগ হবে** সেটা
 * একটা সিদ্ধান্ত — পুরোটা নয়, কিছু ব্যবসায় রাখা হয়। ⚠️ নিজে থেকে
 * পুরো মুনাফা বসিয়ে দিলে পর্দাটা সিদ্ধান্তটা নিয়ে নিত, আর মালিক
 * কেবল "হ্যাঁ" চাপতেন।
 *
 * ⓘ পাশে চলতি মুনাফাটা দেখানো হয় যাতে সংখ্যাটা কোথা থেকে আসছে তা
 * জানা থাকে — কিন্তু ঘরটা খালিই থাকে।
 */
final class ProfitDistributionController implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CapitalService $capital,
        private readonly ProfitDistribution $distribution,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.capital.view', only: ['index', 'preview']),

            /*
             * ⛔ ঘোষণা করা `post`-এর চাবিতে, `create`-এর নয়।
             *
             * ⚠️ এখানে খসড়া বলে কিছু নেই — ঘোষণা মানেই খাতায় বসা।
             * ⓘ যিনি মূলধনের খসড়া বসাতে পারেন তিনি লাভ বণ্টনের
             * সিদ্ধান্ত নিতে পারেন না; ওটা মালিকের কাজ, আর
             * `capital.post` চাবিটা ঠিক ওই মানুষটাকেই চেনে।
             */
            new Middleware('can:finance.capital.post', only: ['declare']),
        ];
    }

    public function index(Request $request): View
    {
        return view('finance::profit.index', $this->page($request));
    }

    /**
     * ভাগটা আগে দেখা — কিছুই লেখা হয় না।
     *
     * ⓘ একই পাতাই আবার আঁকা হয়, ভাগের তালিকাসহ। ⚠️ আলাদা পাতা হলে
     * মালিক সংখ্যা বদলে আবার দেখতে গিয়ে দুইবার পিছাতেন।
     */
    public function preview(Request $request): View
    {
        $data = $request->validate([
            'profit' => ['required', 'numeric', 'gt:0'],
        ]);

        return view('finance::profit.index', [
            ...$this->page($request),
            'profit' => (string) $data['profit'],
            'preview' => $this->distribution->preview((string) $data['profit']),
        ]);
    }

    public function declare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trx_date' => ['required', 'date'],
            'profit' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $shares = $this->distribution->declare([
            'trx_date' => (string) $data['trx_date'],
            'profit' => (string) $data['profit'],
            'narration' => $data['narration'] ?? null,
        ]);

        return redirect()
            ->route('finance.profit.index')
            ->with('saved', __('finance::message.profit_declared', [
                'no' => $shares[0]->document_no,
                'count' => count($shares),
            ]));
    }

    /**
     * পাতার স্থির অংশ — মেনু, চলতি মুনাফা, আর আগের ঘোষণাগুলো।
     *
     * @return array<string, mixed>
     */
    private function page(Request $request): array
    {
        /*
         * ⓘ চলতি মুনাফা জানা না থাকলে `positions()`-কে `null` দেওয়া
         * হয়, আর তখন সে কেবল অনুপাত ফেরায় — টাকা নয়। ⚠️ শূন্য দিলে
         * "সবার ভাগ শূন্য" দেখাত, যা আলাদা কথা।
         */
        return [
            'menu' => $this->menu->forUser($request->user()),
            'positions' => $this->capital->positions(null),
            'history' => ProfitShare::query()
                ->posted()
                ->with('person')
                ->orderByDesc('trx_date')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'profit' => null,
            'preview' => null,
        ];
    }
}
