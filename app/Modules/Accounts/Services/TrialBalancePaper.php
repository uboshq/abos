<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\ExportJournal;
use App\Core\Services\SettingsService;
use App\Core\Support\PaperLanguage;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ রেওয়ামিলের কাগজ — A4 খাড়া, লেজারের প্যাডে: দল ধরে উপমোট, কোড নিজের কলামে, উল্টো জেরে ⚠, নিচে "মিলেছে" সনদ আর তিন সই
 * (প্রস্তুতকারী, যাচাইকারী, অনুমোদনকারী) — মালিক, ১৭ নভেম্বর ২০২৬ "ok" (নমুনা `rs-tb-print`)।
 *
 * ⓘ অঙ্ক পর্দার একই [[TrialBalanceStatement]] থেকে, কাগজের ভাষায় (`?lang=en` হলে ইংরেজি; [[PaperLanguage]])।
 * ⓘ সইয়ের ঘর হিসাবের ছাপার সাধারণ সুইচ মানে (`accounts.print_signature_lines`) — বন্ধ হলে "কম্পিউটারে তৈরি"।
 */
final class TrialBalancePaper
{
    public function __construct(
        private readonly TrialBalanceStatement $statement,
        private readonly ReportEngine $reports,
    ) {}

    public function response(Request $request): Response
    {
        $how = (string) $request->query('export');
        $locale = PaperLanguage::for();
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $tb = $this->statement->build($request->only($this->reports->get('accounts.trial_balance')->requestKeys()));
            $branchId = $tb['branch_id'];

            $pdf = app(PrintEngine::class)->renderDocument('accounts::print.trial-balance', [
                'title' => (string) __('accounts::menu.trial_balance'),
                'tb' => $tb,
                'branch' => $branchId !== null
                    ? (string) Branch::query()->whereKey($branchId)->first()?->name()
                    : (string) __('core.listing.all_branches'),
                'printedBy' => (string) $request->user()?->name,
                'printedAt' => now()->format('d-m-Y, h:i A'),
                'signatures' => app(SettingsService::class)->enabled('accounts.print_signature_lines'),
            ], PaperSize::A4, $locale)['pdf'];
        } finally {
            app()->setLocale($previous);
        }

        app(ExportJournal::class)->wrote(1);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($how === 'print' ? 'inline' : 'attachment').'; filename="trial-balance-'.$tb['to'].'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
