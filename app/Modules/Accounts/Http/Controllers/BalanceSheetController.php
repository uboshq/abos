<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\DataScope;
use App\Core\Services\ExportJournal;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\PaperLanguage;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\FinancialStatements;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
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

    public function show(Request $request): View|Response
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

        /*
         * ⭐ প্রিন্ট ভিউ আর PDF — IAS 1 ছকের কাগজ, লেজারের প্যাডে ([[print.balance-sheet]]; মালিক, ১৬ নভেম্বর ২০২৬)।
         * ⓘ `?export=` তাই "তালিকা নামানো" চাবি আর নিরীক্ষার খাতা একই দরজায় ([[ExportListing]]) — স্থিতিপত্র আলাদা নিয়মে নয়।
         */
        $paper = $request->query('export');
        if ($paper === 'print' || $paper === 'pdf') {
            return $this->paper($request, is_string($asOf) && $asOf !== '' ? $asOf : now()->toDateString(), $asked, $paper);
        }

        return view('accounts::report.balance-sheet', [
            'menu' => $this->menu->forUser($request->user()),
            // ⓘ না চাইলে শাখাটা সার্ভিস নিজেই হেডার থেকে নেয় — নিয়ম এক জায়গায় ([[BalanceSheetService::build()]])
            /*
             * ⭐ পাশে আগের অর্থবছরের শেষ দিনের অঙ্ক, একই শাখায় — IAS 1.38 (ERP ১০০%-এর হিসাবের ফাঁক #৭, ২০ অক্টোবর ২০২৬;
             * [[TheBalanceSheetHadNoLastYearTest]])। আগে স্থিতিপত্রে কেবল এক দিনের অঙ্ক ছিল।
             */
            'sheet' => $this->comparedSheet(is_string($asOf) && $asOf !== '' ? $asOf : now()->toDateString(), $asked),
            // ⭐ অনুপাতের সারি — কাগজের একই হিসাব ([[FinancialStatements::position()]]; ১৭ নভেম্বর ২০২৬)
            'fs' => app(FinancialStatements::class)->position(is_string($asOf) && $asOf !== '' ? $asOf : now()->toDateString(), $asked),
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

    private function paper(Request $request, string $asOf, ?int $asked, string $how): Response
    {
        return $this->formalPaper($request, 'accounts::print.balance-sheet', $asOf, $asked, $how, 'balance-sheet', full: false);
    }

    /**
     * ⭐ আর্থিক বিবরণীর পূর্ণ সেট — আর্থিক অবস্থা, লাভ-ক্ষতি (আগের বছরের একই সময়), মালিকানা স্বত্বের পরিবর্তন, নগদ প্রবাহ (পরোক্ষ), নোট;
     * বাংলা বা ইংরেজি (`?lang=en`) — মালিক, ১৭ নভেম্বর ২০২৬ "ok"। ⓘ কেবল কাগজ (`?export=print|pdf`), "তালিকা নামানো" চাবির একই দরজায়
     * ([[ExportListing]]); কাগজ না চাইলে স্থিতিপত্রের পাতায় ফেরে।
     */
    public function statements(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = is_string($request->query('as_of')) && $request->query('as_of') !== '' ? (string) $request->query('as_of') : now()->toDateString();
        $asked = $request->integer('branch_id') ?: null;
        abort_if($asked !== null && ! app(DataScope::class)->allows($request->user(), UserDataScope::BRANCH, $asked), 404);

        $how = $request->query('export');
        if ($how !== 'print' && $how !== 'pdf') {
            return redirect()->route('accounts.balance_sheet', $request->only(['as_of', 'branch_id']));
        }

        return $this->formalPaper($request, 'accounts::print.statements', $asOf, $asked, $how, 'financial-statements', full: true);
    }

    private function formalPaper(Request $request, string $view, string $asOf, ?int $asked, string $how, string $file, bool $full): Response
    {
        $user = $request->user();
        $branchId = $asked ?? ViewedBranch::one($user);
        $locale = PaperLanguage::for();
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $statements = app(FinancialStatements::class);
            $fs = $statements->position($asOf, $branchId);
            $from = $statements->periodStart($asOf);
            $settings = app(SettingsService::class);

            $data = [
                'title' => (string) __($full ? 'accounts::fs.set.title' : 'accounts::fs.bs.title'),
                'fs' => $fs,
                'soce' => $statements->equity(min($from, $asOf), $asOf, $branchId),
                'branch' => $branchId !== null ? (string) Branch::query()->whereKey($branchId)->first()?->name() : (string) __('core.listing.all_branches'),
                'branchNote' => $branchId !== null,
                'printedBy' => (string) $user->name,
                'printedAt' => now()->format('d-m-Y, h:i A'),
                'signatures' => $settings->enabled('accounts.print_signature_lines'),
                // ⭐ কোম্পানি আইনের চার সই — আনুষ্ঠানিক বিবরণীতে, সেটিংসে বন্ধ করা যায় (রোজকার কাগজে দুই সই, আগের মতো)
                'board' => $settings->enabled('accounts.statement_board_signatures'),
            ];

            if ($full) {
                $data['pl'] = $statements->profitOrLoss(min($from, $asOf), $asOf, $branchId);
                $data['cf'] = $statements->cashFlow(min($from, $asOf), $asOf, $branchId);
            }

            $pdf = app(PrintEngine::class)->renderDocument($view, $data, PaperSize::A4, $locale)['pdf'];
        } finally {
            app()->setLocale($previous);
        }

        app(ExportJournal::class)->wrote(1);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($how === 'print' ? 'inline' : 'attachment').'; filename="'.$file.'-'.$asOf.'-'.$locale.'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** @return array<string, mixed> */
    private function comparedSheet(string $asOf, ?int $branchId): array
    {
        $now = $this->sheet->build($asOf, $branchId);

        return $this->sheet->withComparative($now, $this->sheet->build($this->sheet->priorDate($asOf), $branchId));
    }

    /**
     * ⭐ মালিকানা-স্বত্বের পরিবর্তনের বিবরণী — IAS 1.106 (ERP ১০০%-এর হিসাবের ফাঁক #৬, ২০ অক্টোবর ২০২৬;
     * [[TheEquityStatementWasMissingTest]])। ⓘ "থেকে" না দিলে শেষ দিনের অর্থবছরের শুরু; শাখার নিয়ম স্থিতিপত্রের অবিকল।
     */
    public function equity(Request $request): View
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        $to = is_string($request->query('to')) && $request->query('to') !== '' ? (string) $request->query('to') : now()->toDateString();
        $from = is_string($request->query('from')) && $request->query('from') !== ''
            ? (string) $request->query('from')
            : Carbon::parse(FinancialYear::forDate($to)?->starts_on ?? Carbon::parse($to)->startOfYear())->toDateString();

        // ⓘ "থেকে" নিজে না দিলে বছরের শুরু; শেষের তারিখ তার আগে হলে খালি সময় — শুরুটা শেষের দিনে নামে
        $from = min($from, $to);

        $asked = $request->integer('branch_id') ?: null;
        $scope = app(DataScope::class);
        abort_if($asked !== null && ! $scope->allows($request->user(), UserDataScope::BRANCH, $asked), 404);
        $reach = $scope->idsFor($request->user(), UserDataScope::BRANCH);

        return view('accounts::report.equity-statement', [
            'menu' => $this->menu->forUser($request->user()),
            'statement' => $this->sheet->equityStatement($from, $to, $asked),
            'branchId' => $asked ?? ViewedBranch::one($request->user()),
            'branches' => Branch::query()
                ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
                ->orderBy('name_en')
                ->get(),
        ]);
    }
}
