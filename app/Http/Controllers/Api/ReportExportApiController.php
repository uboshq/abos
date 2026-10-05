<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportExport;
use App\Core\Services\ExportJournal;
use App\Core\Services\ListExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * রিপোর্টটা ফাইল হয়ে — `GET /reports/{slug}/export?format=`। চুক্তি §১০।
 *
 * ── ⭐ `{slug}` আর §৯-এর `{key}` একই জিনিস ───────────────────────────
 * (`sales.daily`)। ⛔ দেখা আর নামানো একই রিপোর্টকে দুই নামে চিনলে একদিন
 * একটা কাজ করত আর অন্যটা ৪০৪ দিত।
 *
 * ── ⭐ দরজাটা §৯-এর দরজাই — [[ReportApiController::show()]] ─────────────
 * অচেনা কী ৪০৪, বন্ধ মডিউল ৪০৪, চাবি নেই ([[ReportDefinition::allows()]])
 * ৪০৩, ভাঙা তারিখ ৪২২ — এই চারটা প্রশ্ন ওখানে একবারই লেখা। ⓘ এখানে ওটাকেই
 * আগে ডাকা হয়। ⛔ মডিউল-সুইচের যুক্তি নকল করলে তৃতীয় একটা তালিকা হত,
 * আর একদিন বিক্রয় বন্ধ করা কোম্পানির রিপোর্ট দেখা যেত না অথচ নামানো যেত।
 * ⚠️ দাম: রিপোর্টের প্রথম পাতা একবার বাড়তি চলে। জানা, আর ইচ্ছাকৃত।
 *
 * ── ⭐ ফাইলটা বানায় ওয়েবের রপ্তানি — [[ReportExport]] + [[ListExport]] ──
 * ওয়েবের রিপোর্ট-পর্দা আর নির্ধারিত রিপোর্ট ([[ScheduledReportRunner]])
 * যেভাবে বানায়, হুবহু: কলাম `columnsFor($user)` থেকে, ঘরের লেখা পর্দার
 * মতো, সূত্র-ইনজেকশন আটকানো। ⛔ তাই ঢাকা কলাম ফাইলেও নেই — শিরোনামেও না।
 * `csv` · `xlsx` · `json`, যা [[ListExport::FORMATS]] বানায়; `docx` নেই
 * (মালিকের সিদ্ধান্ত, ১৩ সেপ্টেম্বর)।
 *
 * ── ⚠️ পুরো রিপোর্ট, এক পাতা নয় ────────────────────────────────────────
 * ওয়েবের বোতাম পর্দার পাতাটাই নামায় (১০০ সারি); ফোনের ফাইল পুরোটা —
 * একজন মানুষ পাতা উল্টে যা দেখতে পারেন, তার বেশি নয়। ⛔ তবু সীমা আছে
 * ([[MAX_ROWS]]), আর সীমা পেরোলে ৪২২, কেটে দেওয়া নয়: কাটা ফাইলকে মানুষ
 * পুরো ভাবতেন।
 *
 * ── ⓘ খাতায় ওঠে ─────────────────────────────────────────────────────
 * ওয়েবের প্রতিটা রপ্তানি [[ExportJournal]]-এ বসে; ফোনেরটাও, একই খাতায় —
 * রুটের নাম `api.reports.export`, শিরোনাম রিপোর্টের কী।
 */
final class ReportExportApiController extends Controller
{
    /**
     * একটা ফাইলে সর্বোচ্চ কয়টা সারি — নির্ধারিত রিপোর্টের সমান সীমা।
     *
     * ⚠️ [[ListExport]] ফাইলটা মেমরিতে বানায় (চুক্তির নিয়ম চ বলে না-বানানোই
     * ভালো); স্ট্রিম করা রপ্তানি মানে দ্বিতীয় একটা রপ্তানি-ইঞ্জিন, তাই সীমা।
     */
    public const MAX_ROWS = 100000;

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly ReportApiController $gate,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        /** @var User $user */
        $user = $request->user();

        /* ⭐ §৯-এর দরজা: ৪০৪ · ৪০৩ · ৪২২ — ঐ ক্রমে, ঐ জায়গায় */
        $page = $this->gate->show($request, $slug)->getData(true);

        /* ⛔ ফরম্যাট চাবির **পরে** — চাবিহীন মানুষ ৪২২ দেখে জানবেন না রিপোর্টটা আছে */
        $format = (string) $request->validate([
            // ⭐ pdf — খতিয়ান আর রিপোর্ট ফোন থেকে দেখা, ছাপা, পাঠানো (মালিক, ৪ অক্টোবর ২০২৬); কেবল এই দরজায়, তালিকার
            // সাধারণ রপ্তানিতে নয় — নইলে ওয়েবের প্রতিটা তালিকা pdf মেনে নিত অথচ বানাতে পারত না
            'format' => ['required', 'string', Rule::in([...ListExport::FORMATS, 'pdf'])],
        ])['format'];

        if ((int) ($page['totalRows'] ?? 0) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'from' => __('validation.max.numeric', ['attribute' => 'rows', 'max' => self::MAX_ROWS]),
            ]);
        }

        $definition = $this->reports->get($slug);

        $result = $this->reports->run(
            $slug,
            // ⭐ §৯-এর ছাঁকনি, হুবহু একই নামে (চুক্তি §১০)
            $request->only($definition->requestKeys()),
            page: 1,
            perPage: self::MAX_ROWS,
        );

        $columns = $result->columnsFor($user);

        /* ⓘ একটা কলামও দেখা যায় না — ফাঁকা ফাইল দিলে মানুষ ভাবতেন রিপোর্টটা খালি */
        abort_if($columns === [], 403);

        $export = new ListExport;
        ReportExport::into($export, $result, $columns);

        if ($format === 'pdf') {
            return $this->pdf($export, $columns, __($definition->title), $result->filters, $slug);
        }

        $body = match ($format) {
            'xlsx' => $export->xlsx(),
            'json' => $export->json(),
            default => $export->csv(),
        };

        abort_if($body === null, 500);

        app(ExportJournal::class)->wrote($export->rowCount());

        $filename = 'abos-'.str_replace('.', '-', $slug).'-'.now()->format('Y-m-d').'.'.$format;

        return response($body, 200, [
            'Content-Type' => match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'json' => 'application/json; charset=UTF-8',
                default => 'text/csv; charset=UTF-8',
            },
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // ⛔ রপ্তানি ক্যাশ হয় না — কাল একই ঠিকানা অন্য সংখ্যা দেবে (নিয়ম ঙ)
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * ⭐ রিপোর্টের PDF — রপ্তানির একই ধরা টেবিল ([[ReportExport::into()]]) কাগজে, ছাপার একই যন্ত্রে ([[PrintEngine]],
     * [[print.report]]): কোম্পানির নাম, শিরোনাম, তারিখের সীমা, সারি আর সর্বমোট। ⓘ সংখ্যা এখানে কষা হয় না।
     *
     * @param  list<\App\Core\Engines\Report\ReportColumn>  $columns
     * @param  array<string, mixed>  $filters
     */
    private function pdf(ListExport $export, array $columns, string $title, array $filters, string $slug): Response
    {
        $table = $export->captured() ?? ['columns' => [], 'values' => []];
        $numeric = [];
        foreach ($columns as $column) {
            $numeric[$column->key] = in_array($column->type, ['money', 'quantity', 'percent', 'dr_cr'], true);
        }

        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        $pdf = app(\App\Core\Engines\Print\PrintEngine::class)->render('print.report', [
            'title' => $title,
            'range' => $from !== '' || $to !== ''
                ? trim(\App\Core\Support\DateFormat::format($from ?: null).' — '.\App\Core\Support\DateFormat::format($to ?: null), ' —')
                : null,
            'columns' => array_map(fn (array $c) => ['label' => $c['label'], 'numeric' => $numeric[$c['key']] ?? false], $table['columns']),
            'rows' => $table['values'],
            'footer' => $export->footerRow(),
        ]);

        app(ExportJournal::class)->wrote($export->rowCount());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="abos-'.str_replace('.', '-', $slug).'-'.now()->format('Y-m-d').'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
