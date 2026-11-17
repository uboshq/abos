<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * ⭐ যেকোনো তালিকা বা রিপোর্টের কাগজ — লেজারের ছাঁচে, সব পাতা (মালিক, ১৬ নভেম্বর ২০২৬: *"লেজার যেভাবে বানাইছে এরকম প্রিন্টেড ডকুমেন্ট
 *   লাগবে আমার সব রিপোর্টারেই … প্রিন্ট ভিউ এ দেখতে পারবো, আবার প্রিন্টও করতে পারবো"*)।
 *
 * ⓘ সংখ্যা এখানে কষা হয় না: পর্দার একই ধরা টেবিল ([[ListExport]]) — তাই পর্দা, Excel আর কাগজে হুবহু একই অঙ্ক, আর চাবিহীন কলাম আগেই বাদ।
 * ⓘ ৮টার বেশি কলাম হলে A4 আড়াআড়ি — বড় আন্তর্জাতিক সফটওয়্যারের মতো চওড়া রিপোর্ট পাশ ফিরিয়ে।
 * ⚠️ সীমা [[self::MAX_ROWS]] সারি — তার বেশি হলে কাগজেই লেখা থাকে কতটা এসেছে; ছাঁকনি দিয়ে ছোট করতে হয়।
 */
final class ListPaper
{
    public const MAX_ROWS = 5000;

    /** ⓘ এর বেশি কলাম হলে পাতা আড়াআড়ি */
    public const PORTRAIT_UP_TO = 8;

    public function __construct(private readonly PrintEngine $print, private readonly SettingsService $settings) {}

    public function render(ListExport $export, Request $request): string
    {
        $table = $export->captured() ?? ['columns' => [], 'values' => []];
        $values = $table['values'];
        $kinds = $export->kinds();
        $truncated = count($values) > self::MAX_ROWS;

        if ($truncated) {
            $values = array_slice($values, 0, self::MAX_ROWS);
            $kinds = array_slice($kinds, 0, self::MAX_ROWS);
        }

        $numeric = $export->numericKeys();
        $columns = [];

        foreach ($table['columns'] as $c => $column) {
            $columns[] = [
                'label' => $column['label'],
                'numeric' => in_array($column['key'], $numeric, true) || self::looksNumeric(array_column($values, $c)),
            ];
        }

        $meta = $export->meta();
        $user = $request->user();

        // ⓘ সময়কাল ডান বাক্সে থাকলে "থেকে: …" / "পর্যন্ত: …" চিপ বাঁ বাক্সে আবার নয়
        if (($meta['period'] ?? '') !== '') {
            $dates = array_filter([(string) __('core.toolbar.filter_names.from'), (string) __('core.toolbar.filter_names.to')]);
            $meta['facts'] = array_values(array_filter($meta['facts'] ?? [],
                fn (string $fact): bool => ! collect($dates)->contains(fn (string $d) => str_starts_with($fact, $d.':'))));
        }
        $title = (string) ($meta['title'] ?? __('core.listing.untitled'));

        return $this->print->renderDocument('print.listing', [
            'title' => $title,
            'titleEn' => (string) ($meta['title_en'] ?? ''),
            'period' => (string) ($meta['period'] ?? ''),
            'facts' => array_values($meta['facts'] ?? []),
            'columns' => $columns,
            'rows' => $values,
            'kinds' => $kinds,
            'footer' => $export->footerRow(),
            'truncated' => $truncated,
            'branch' => ($branchId = $user === null ? null : ViewedBranch::one($user)) !== null
                ? (string) Branch::query()->whereKey($branchId)->first()?->name()
                : (string) __('core.listing.all_branches'),
            'printedBy' => (string) ($user?->name ?? ''),
            'printedAt' => now()->format('d-m-Y, h:i A'),
            'signatures' => $this->settings->enabled('accounts.print_signature_lines'),
        ], count($columns) > self::PORTRAIT_UP_TO ? PaperSize::A4_LANDSCAPE : PaperSize::A4)['pdf'];
    }

    /**
     * কলামটা সংখ্যার কিনা — রিপোর্ট না বললে লেখা দেখে: খালি নয় এমন প্রতিটা ঘর টাকা/পরিমাণ/শতাংশের মতো।
     *
     * @param  list<string>  $cells
     */
    public static function looksNumeric(array $cells): bool
    {
        $seen = false;

        foreach ($cells as $cell) {
            $cell = trim((string) $cell);

            if ($cell === '' || $cell === '—' || $cell === '-') {
                continue;
            }

            // ⓘ মোবাইল, হিসাব নম্বর — লম্বা খালি অঙ্ক টাকা নয়, লেখা (বাঁয়ে)
            if (preg_match('/^\+?\d{10,}$/', $cell) === 1) {
                return false;
            }

            if (preg_match('/^(\((Dr|Cr)\)\s*)?[৳(+\-]?\s?[\d,]+(\.\d+)?\)?%?$/u', $cell) !== 1) {
                return false;
            }

            $seen = true;
        }

        return $seen;
    }
}
