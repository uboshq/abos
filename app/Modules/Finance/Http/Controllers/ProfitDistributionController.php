<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
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
            new Middleware('can:finance.capital.post', only: ['declare', 'capitalise']),
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
            /*
             * ⛔ আগামী তারিখে ঘোষণা নয় — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ আগে কেবল `['required', 'date']` ছিল, আর ঘোষণা
             * মানেই খাতায় বসা — খসড়া বলে কিছু নেই। ফলে আগামী
             * মাসের তারিখে একটা ভাউচার ঢুকিয়ে দেওয়া যেত।
             *
             * ⓘ তালা পেছনের দিকটা পাহারা দেয়, সামনের দিকটা কেউ নয়।
             * আর ভবিষ্যতের একটা সারি বসলে **আজকের সংখ্যাই** ভুল
             * হয় — সারিটা খাতায় আছে, অথচ ঘটনাটা এখনো ঘটেনি।
             *
             * ⭐ ধরা পড়েছে `NoDocumentIsDatedInTheFuture`-এ।
             */
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
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
     * ⭐ বছর শেষে যা তোলা হয়নি, তা মূলধনে।
     *
     * ── ⭐ মালিকের কথা, ২২ সেপ্টেম্বর ২০২৬ ───────────────
     * *"র থাকলে বছর শেষে capital-এ যোগ হবে বা invest-এ"*।
     *
     * ⛔ ঘোষণার মতোই এটাও `capital.post`-এর কাজ — খসড়া বলে
     * কিছু নেই, আর সারিটা মালিকানার অংশ বদলায়।
     */
    public function capitalise(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'entry_type' => ['required', Rule::in(CapitalEntry::KINDS)],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $entries = $this->distribution->capitalise([
            'trx_date' => (string) $data['trx_date'],
            'entry_type' => (string) $data['entry_type'],
            'narration' => $data['narration'] ?? null,
        ]);

        return redirect()
            ->route('finance.profit.index')
            ->with('saved', __('finance::message.profit_capitalised', [
                'no' => $entries[0]->voucher?->document_no ?? $entries[0]->document_no,
                'count' => count($entries),
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

            /*
             * ⓘ কার কত এখনো পড়ে আছে — বছর-শেষের বাক্সটা এটা
             * দিয়েই ঠিক করে নিজে দেখা যাবে কি না।
             */
            'outstanding' => $this->distribution->outstanding(),
            'history' => ProfitShare::query()
                ->posted()
                ->with('person')
                ->orderByDesc('trx_date')
                ->orderByDesc('id')
                /*
                 * ⭐ পাতা করা, `limit(50)` নয় — ২২ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ `limit` পাতাটাকে হালকা রাখত, কিন্তু বছর ঘুরলে
                 * পানচাশতম ঘোষণার পরেরগুলো দেখার কোনো পথ থাকত না —
                 * সারিগুলো খাতায় আছে, পর্দায় নেই, আর কিছুই ভাঙে না।
                 *
                 * ⓘ ধরা পড়েছে `EveryListScreenPaginates`-এ।
                 */
                ->paginate(50)
                ->withQueryString(),
            'profit' => null,
            'preview' => null,
        ];
    }
}
