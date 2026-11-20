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
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\YearEndTrialBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ বছর-শেষের রেওয়ামিল — সমন্বয়ের আগে → নম্বর দেওয়া সমন্বয় → সমন্বয়ের পরে → আগের বছর-শেষ (মালিক, ১৭ নভেম্বর ২০২৬ "ok":
 * রেওয়ামিল আর স্থিতিপত্র NEXUS-এর মানে; [[YearEndTrialBalance]])।
 *
 * ⓘ চাবি রেওয়ামিলের একই (`accounts.report.final`); ছাঁকনি — অর্থবছর আর শাখা (নাগালের বাইরের শাখা ৪০৪, স্থিতিপত্রের মতো)।
 * ⓘ `?export=print|pdf` — A4 আড়াআড়ি কাগজ, বাংলা বা ইংরেজি (`?lang=en`; [[PaperLanguage]]), "তালিকা নামানো" চাবির একই দরজায়।
 */
class YearEndTrialBalanceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly YearEndTrialBalance $statement,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report.final')];
    }

    public function show(Request $request): View|Response
    {
        $request->validate(['year_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer']]);

        $asked = $request->integer('branch_id') ?: null;
        $scope = app(DataScope::class);
        abort_if($asked !== null && ! $scope->allows($request->user(), UserDataScope::BRANCH, $asked), 404);
        $branchId = $asked ?? ViewedBranch::one($request->user());

        $year = YearEndTrialBalance::yearFor($request->integer('year_id') ?: null);
        abort_if($year === null, 404);

        $how = $request->query('export');
        if ($how === 'print' || $how === 'pdf') {
            return $this->paper($request, $year, $branchId, (string) $how);
        }

        $reach = $scope->idsFor($request->user(), UserDataScope::BRANCH);

        return view('accounts::report.year-end-trial-balance', [
            'menu' => $this->menu->forUser($request->user()),
            'ytb' => $this->statement->build($year, $branchId),
            'years' => YearEndTrialBalance::years(),
            'branchId' => $branchId,
            'branches' => Branch::query()
                ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
                ->orderBy('name_en')
                ->get(),
        ]);
    }

    private function paper(Request $request, \App\Models\FinancialYear $year, ?int $branchId, string $how): Response
    {
        $locale = PaperLanguage::for();
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $ytb = $this->statement->build($year, $branchId);
            $shownBranch = $ytb['branch_id'];

            $pdf = app(PrintEngine::class)->renderDocument('accounts::print.year-end-trial-balance', [
                'title' => (string) __('accounts::fs.ytb.title'),
                'ytb' => $ytb,
                'branch' => $shownBranch !== null
                    ? (string) Branch::query()->whereKey($shownBranch)->first()?->name()
                    : (string) __('core.listing.all_branches'),
                'printedBy' => (string) $request->user()?->name,
                'printedAt' => now()->format('d-m-Y, h:i A'),
                'signatures' => app(SettingsService::class)->enabled('accounts.print_signature_lines'),
            ], PaperSize::A4_LANDSCAPE, $locale)['pdf'];
        } finally {
            app()->setLocale($previous);
        }

        app(ExportJournal::class)->wrote(1);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($how === 'print' ? 'inline' : 'attachment').'; filename="year-end-trial-balance-'.$ytb['to'].'-'.$locale.'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
