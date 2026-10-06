<?php

declare(strict_types=1);

namespace App\Modules\Documents\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * নথির ড্যাশবোর্ড — মালিকের ড্যাশবোর্ড নকশা §১৩ ("Documentation Dashboard"), ৬ অক্টোবর ২০২৬।
 *
 * ⓘ নথি মানে ERP-তে জোড়া প্রতিটা ফাইল (`attachments`, কোরের [[Attachment]]) — বিল, চালান, কর্মীর কাগজ, যেখানেই জোড়া।
 * ⓘ ভাগ করা লিংক আর পাঠানো কাগজ কোরের দুই খাতা থেকে (`doc_shares`, `doc_deliveries`)।
 * ⚠️ নথির টেবিলে শাখা নেই — তাই নথির সংখ্যা গোটা কোম্পানির; লিংক আর পাঠানো কাগজ হেডারে বাছা শাখা মানে।
 * ⛔ নকশার মেয়াদ, স্বাক্ষর, OCR, AI — ERP এই তথ্য রাখে না; ওগুলো এখানে নেই, বানানো সংখ্যাও নেই।
 */
final class DocumentsDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = Carbon::today();
        $live = Attachment::query();

        $bytes = (string) ((clone $live)->sum('size_bytes') ?: '0');

        return new DashboardDefinition(
            title: __('documents::dashboard.title'),
            subtitle: __('documents::dashboard.subtitle'),

            stats: [
                new Stat(
                    label: __('documents::dashboard.total'),
                    value: (string) (clone $live)->count(),
                    hint: __('documents::dashboard.total_hint'),
                ),
                new Stat(
                    label: __('documents::dashboard.new_today'),
                    value: (string) (clone $live)->whereDate('created_at', $today->toDateString())->count(),
                    hint: __('documents::dashboard.new_today_hint'),
                ),
                new Stat(
                    label: __('documents::dashboard.storage'),
                    value: self::size($bytes),
                    hint: __('documents::dashboard.storage_hint'),
                ),
                new Stat(
                    label: __('documents::dashboard.shared'),
                    // ⓘ চালু লিংক = বাতিল হয়নি আর মেয়াদ আজকের পরেও আছে
                    value: (string) self::shares()->whereNull('revoked_at')
                        ->where('expires_at', '>', $today->copy()->endOfDay()->toDateTimeString())->count(),
                    hint: __('documents::dashboard.shared_hint'),
                ),
                new Stat(
                    label: __('documents::dashboard.recycled'),
                    value: (string) Attachment::onlyTrashed()->count(),
                    hint: __('documents::dashboard.recycled_hint'),
                ),
            ],

            panels: [
                self::monthly($today),
                self::byKind(),
                self::byModule(),
            ],

            listings: [self::recent()],
        );
    }

    /** ⭐ ছয় মাসে কত নথি জোড়া হলো আর কত কাগজ বেরোল (ছাপা, নামানো, পাঠানো) — পাশাপাশি স্তম্ভ */
    private static function monthly(Carbon $today): Series
    {
        $start = $today->copy()->startOfMonth()->subMonths(5);
        $from = $start->toDateString();
        $to = $today->toDateString();

        $uploaded = Attachment::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as n")
            ->groupBy('ym')
            ->pluck('n', 'ym');

        $sent = app(DataScope::class)->inView(DB::table('doc_deliveries'), 'doc_deliveries.branch_id')
            ->where('company_id', CompanyContext::id())
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as n")
            ->groupBy('ym')
            ->pluck('n', 'ym');

        $points = [];
        for ($i = 0, $month = $start->copy(); $i < 6; $i++, $month->addMonth()) {
            $key = $month->format('Y-m');
            $points[] = [
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
                'first' => (string) ($uploaded[$key] ?? 0),
                'second' => (string) ($sent[$key] ?? 0),
            ];
        }

        return new Series(
            label: __('documents::dashboard.monthly'),
            points: $points,
            firstLabel: __('documents::dashboard.uploaded'),
            secondLabel: __('documents::dashboard.sent'),
            chart: 'bars',
            range: DateRange::label($start, $today),
        );
    }

    /** নথির ধরন — PDF, ছবি, হিসাবের খাতা (এক্সেল/CSV), লেখা (ওয়ার্ড), অন্যান্য; ডোনাট */
    private static function byKind(): Breakdown
    {
        $kinds = ['pdf' => 0, 'image' => 0, 'sheet' => 0, 'word' => 0, 'other' => 0];

        foreach (Attachment::query()->selectRaw('mime_type, COUNT(*) as n')->groupBy('mime_type')->pluck('n', 'mime_type') as $mime => $n) {
            $kinds[self::kindOf((string) $mime)] += (int) $n;
        }

        return new Breakdown(
            label: __('documents::dashboard.by_kind'),
            parts: array_map(fn (string $kind) => ['label' => __('documents::dashboard.kind_'.$kind), 'value' => (string) $kinds[$kind]], array_keys($kinds)),
            hint: __('documents::dashboard.by_kind_hint'),
            chart: 'donut',
        );
    }

    /** কোন কাজে কত নথি জোড়া — মডিউল ধরে, বড় ছয়টা; আড়াআড়ি দণ্ড */
    private static function byModule(): Breakdown
    {
        $rows = Attachment::query()
            ->selectRaw('source_module, COUNT(*) as n')
            ->groupBy('source_module')
            ->orderByDesc('n')
            ->limit(6)
            ->pluck('n', 'source_module');

        $parts = [];
        foreach ($rows as $module => $n) {
            $parts[] = ['label' => self::moduleName((string) $module), 'value' => (string) $n];
        }

        return new Breakdown(
            label: __('documents::dashboard.by_module'),
            parts: $parts === [] ? [['label' => __('documents::dashboard.none_yet'), 'value' => '0']] : $parts,
            hint: __('documents::dashboard.by_module_hint'),
            chart: 'hbars',
        );
    }

    /** সদ্য জোড়া আটটা নথি */
    private static function recent(): Listing
    {
        return new Listing(
            label: __('documents::dashboard.recent'),
            columns: [
                ['key' => 'name', 'label' => __('documents::dashboard.col_name'),
                    'render' => fn (Attachment $a) => $a->original_name],
                ['key' => 'module', 'label' => __('documents::dashboard.col_module'), 'width' => '9rem',
                    'render' => fn (Attachment $a) => self::moduleName((string) $a->source_module)],
                ['key' => 'size', 'label' => __('documents::dashboard.col_size'), 'width' => '7rem',
                    'render' => fn (Attachment $a) => self::size((string) $a->size_bytes)],
                ['key' => 'when', 'label' => __('documents::dashboard.col_when'), 'width' => '9rem',
                    'render' => fn (Attachment $a) => $a->created_at?->locale(app()->getLocale())->translatedFormat('j M, H:i') ?? ''],
            ],
            rows: Attachment::query()->latest('id')->limit(8)->get(),
            empty: __('documents::dashboard.none_yet'),
        );
    }

    /** @return \Illuminate\Database\Query\Builder ভাগ করা লিংক — কোম্পানির, হেডারে বাছা শাখার */
    private static function shares()
    {
        return app(DataScope::class)->inView(DB::table('doc_shares'), 'doc_shares.branch_id')
            ->where('company_id', CompanyContext::id());
    }

    private static function kindOf(string $mime): string
    {
        return match (true) {
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'image/') => 'image',
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || $mime === 'text/csv' => 'sheet',
            str_contains($mime, 'word') || str_contains($mime, 'opendocument.text') => 'word',
            default => 'other',
        };
    }

    /** মডিউলের নাম, পর্দার ভাষায় — না থাকলে কোডটাই */
    private static function moduleName(string $code): string
    {
        $name = app(\App\Core\Module\ModuleRegistry::class)->get($code)?->name ?? [];

        return (string) ($name[app()->getLocale()] ?? $name['en'] ?? $code);
    }

    /** বাইট থেকে KB/MB/GB — bcmath-এ, float নয় */
    private static function size(string $bytes): string
    {
        foreach ([['1073741824', 'GB'], ['1048576', 'MB'], ['1024', 'KB']] as [$unit, $word]) {
            if (bccomp($bytes, $unit, 0) >= 0) {
                $n = bcdiv($bytes, $unit, 1);

                return (str_ends_with($n, '.0') ? substr($n, 0, -2) : $n).' '.$word;
            }
        }

        return $bytes.' B';
    }
}
