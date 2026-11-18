<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\ExportJournal;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DayClose;
use App\Modules\Executive\Services\DayClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ দিন শেষের হিসাব — মালিকের অনুমোদিত নমুনা v7 (১৬ নভেম্বর ২০২৬ "ok"; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * পর্দা, দুই পাতার কাগজ (A4, কোনো টেবিল ভাঙে না), আর "দিন বন্ধ করুন"।
 * ⓘ শাখা: হেডারে বাছা শাখা — প্রতিদিনের সারাংশের মতোই ([[DayClosingService]])।
 * ⓘ দিন বন্ধ: নগদ গুনে পার্থক্য থাকলে কারণ লাগে; বন্ধের পর ঐ দিনের তারিখে লেখা আটকায় ([[OpenPeriod::assertOpen()]])।
 */
class DayClosingController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DayClosingService $closing,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.day_close.view', only: ['show']),
            new Middleware('can:accounts.day_close.close', only: ['close']),
            new Middleware('can:accounts.day_close.reopen', only: ['reopen']),
            new Middleware('can:accounts.day_close.view', only: ['snooze']),
        ];
    }

    /**
     * ⭐ মাঝখানের সতর্কতার ✕ — মালিক, ১৮ নভেম্বর ২০২৬: *"কেটে দিতে পারবে … এক ঘন্টা পর পর আবার আসবে"*।
     * ⓘ এই লগইনে নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত লুকায় (`accounts.day_close.popup_every`, মিনিট); কিছু বদলায় না, কিছু আটকায় না।
     */
    public function snooze(Request $request): RedirectResponse
    {
        $minutes = max(1, (int) (app(\App\Core\Services\SettingsService::class)->get('accounts.day_close.popup_every') ?? 60));
        $request->session()->put('daycheck.snooze_until', now()->addMinutes($minutes)->getTimestamp());

        return back();
    }

    public function show(Request $request): View|Response
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = $this->day($request);
        $report = $this->closing->build($day);

        $how = $request->query('export');
        if ($how === 'print' || $how === 'pdf') {
            return $this->paper($request, $report, (string) $how);
        }

        return view('accounts::report.day-closing', [
            'menu' => $this->menu->forUser($request->user()),
            'r' => $report,
            'branch' => $this->branchName(),
            'canClose' => $request->user()->can('accounts.day_close.close'),
            'canReopen' => $request->user()->can('accounts.day_close.reopen'),
            // ⭐ বন্ধের আগে চেকলিস্ট — যা বাকি, লিংকসহ; কেবল সতর্কতা (মালিক, ১৮ নভেম্বর ২০২৬)
            'checklist' => app(\App\Modules\Accounts\Services\DayChecklist::class)->for($day),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'gap_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $day = Carbon::parse($data['date']);
        abort_if($day->isAfter(Carbon::today()), 422, (string) __('accounts::dayclose.close.future'));

        $branchId = CompanyContext::branchId();

        if (DayClose::closedOn($day, $branchId) !== null) {
            return back()->withErrors(['date' => __('accounts::dayclose.close.already', ['date' => $day->format('d-m-Y')])]);
        }

        $report = $this->closing->build($day);
        $count = $report['count'];
        $reason = trim((string) ($data['gap_reason'] ?? '')) ?: ($count['reason'] ?? '');

        // ⛔ গুনে পার্থক্য থাকলে কারণ না লিখে বন্ধ নয় — কাগজের "দিন বন্ধ" লাইনে কারণটা যায়
        if ($count !== null && bccomp($count['gap'], '0', 2) !== 0 && $reason === '') {
            throw ValidationException::withMessages(['gap_reason' => __('accounts::dayclose.close.reason_needed')]);
        }

        DB::transaction(function () use ($day, $branchId, $count, $reason, $request): void {
            $values = [
                'cash_counted' => $count['counted'] ?? null,
                'cash_book' => $count['book'] ?? null,
                'cash_gap' => $count['gap'] ?? null,
                'gap_reason' => $reason !== '' ? $reason : null,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
            ];

            // ⓘ আগে খোলা হয়ে থাকলে একই সারি আবার বন্ধ — আগের অবস্থা নিরীক্ষার খাতায় থাকে
            $record = DayClose::recordOn($day, $branchId);

            if ($record !== null && (int) ($record->branch_id ?? 0) === (int) ($branchId ?? 0)) {
                $record->update($values);

                return;
            }

            DayClose::query()->create(['company_id' => CompanyContext::id(), 'branch_id' => $branchId, 'day' => $day->toDateString()] + $values);
        });

        return redirect()->route('executive.dayclose', ['date' => $day->toDateString()])
            ->with('status', __('accounts::dayclose.close.done', ['date' => $day->format('d-m-Y')]));
    }

    /**
     * ⭐ দিন আবার খোলা — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে, তাহলে তোমার প্ল্যান মতো এটা করো"): অনেক পেছনের এন্ট্রি একসাথে দিতে হলে কারণ লিখে
     * দিন খোলা; কাজ শেষে আবার গুনে-মিলিয়ে বন্ধ। চাবি `accounts.day_close.reopen` — কোনো ছাঁচে নেই, মালিক (সুপার অ্যাডমিন) আর যাঁকে তিনি দেন।
     */
    public function reopen(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reopen_reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reopen_reason.required' => __('accounts::dayclose.reopen.reason_needed')]);

        $day = Carbon::parse($data['date']);
        $closed = DayClose::closedOn($day, CompanyContext::branchId());

        if ($closed === null) {
            return back()->withErrors(['date' => __('accounts::dayclose.reopen.not_closed', ['date' => $day->format('d-m-Y')])]);
        }

        $closed->update(['reopened_by' => $request->user()->id, 'reopened_at' => now(), 'reopen_reason' => trim($data['reopen_reason'])]);

        return redirect()->route('executive.dayclose', ['date' => $day->toDateString()])
            ->with('status', __('accounts::dayclose.reopen.done', ['date' => $day->format('d-m-Y')]));
    }

    /** @param  array<string, mixed>  $report */
    private function paper(Request $request, array $report, string $how): Response
    {
        $pdf = app(PrintEngine::class)->renderDocument('accounts::print.day-closing', [
            'title' => (string) __('accounts::dayclose.title'),
            'r' => $report,
            'branch' => $this->branchName(),
            'printedBy' => (string) $request->user()->name,
            'printedAt' => now()->format('d-m-Y, h:i A'),
        ], PaperSize::A4)['pdf'];

        app(ExportJournal::class)->wrote(1);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($how === 'print' ? 'inline' : 'attachment').'; filename="day-closing-'.$report['date']->toDateString().'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function day(Request $request): Carbon
    {
        $asked = $request->query('date');

        return is_string($asked) && $asked !== '' ? Carbon::parse($asked)->startOfDay() : Carbon::today();
    }

    private function branchName(): string
    {
        $id = CompanyContext::branchId();

        return $id !== null ? (string) Branch::query()->whereKey($id)->first()?->name() : (string) __('core.listing.all_branches');
    }
}
