<?php

namespace App\Core\Support;

use App\Core\Services\OpenPeriod;
use Carbon\Carbon;

/**
 * তালিকার "অ্যাকশন ▾" মেনুর ভাগ করা জিনিস — মালিক, ৮ নভেম্বর ২০২৬: "প্রতিটি লিস্টে শেষের দিকে একটা অ্যাকশন বাটন
 * থাকবে … ড্রপ ডাউন"; নমুনা "লিস্টের অ্যাকশন বোতাম" ([[x-ui.row-actions]])।
 *
 * ⓘ প্রতিটা কাগজের নিজের partial মেনু সাজায় (কোন কাজ, কোন চাবি); এখানে কেবল যা সব কাগজে একই — মাথা, তিন ভাগের
 * শিরোনাম, আর "মাসটা বন্ধ কি না"।
 */
final class RowMenu
{
    private const PERIOD = 'abos.row-menu.period';

    /** মেনুর মাথা — কাগজের নম্বর আর অবস্থার লেখা (প্রতিটা কাগজ নিজের অবস্থার নাম দেয়) */
    public static function head(?string $no, string $stateLabel): array
    {
        return ['head' => (string) $no, 'state' => $stateLabel];
    }

    /** ভাগের শিরোনাম: look | paper | work */
    public static function group(string $which): array
    {
        return ['group' => __('core.row_menu.group_'.$which)];
    }

    /**
     * "দেখা" ভাগ — দেখুন (পুরো পাতা), প্রিভিউ (পাশে), আর অডিটের চাবি থাকলে বদলের ইতিহাস। মালিক, ৯ নভেম্বর ২০২৬
     * ("Abos er sokol list sob skin dekhe deke update koro"): গ্রাহকের তালিকার মেনুটাই সব খাতার তালিকায়।
     *
     * @return list<array<string, mixed>>
     */
    public static function look(?string $url, ?string $historyQ = null, ?string $module = null): array
    {
        $items = [self::group('look')];

        if ($url) {
            $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $url, 'peek' => false, 'key' => 'Enter', 'opens' => true];
            $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $url];
        }

        if ((string) $historyQ !== '' && auth()->user()?->can('governance.audit.view') && \Illuminate\Support\Facades\Route::has('governance.audit.index')) {
            $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock', 'peek' => false,
                'url' => route('governance.audit.index', array_filter(['q' => $historyQ, 'module' => $module]))];
        }

        return count($items) > 1 ? $items : [];
    }

    /** খাতার সারির মাথার লেখা: কোড/নম্বর · নাম (নিজের name() থাকলে সেটা, নইলে পর্দার ভাষার নাম) */
    public static function title(object $row): string
    {
        $no = (string) (data_get($row, 'document_no') ?? data_get($row, 'policy_no') ?? data_get($row, 'code') ?? '');
        $name = method_exists($row, 'name') ? (string) $row->name()
            : (string) (data_get($row, 'name_'.app()->getLocale()) ?: data_get($row, 'name_en') ?: data_get($row, 'name') ?: '');

        return trim(implode(' · ', array_filter([$no, $name], fn ($v) => $v !== '')));
    }

    /** সক্রিয় / নিষ্ক্রিয় — খাতার সারির অবস্থা */
    public static function activeState(object $row): string
    {
        return __(data_get($row, 'is_active', true) ? 'customer::filter.active' : 'customer::filter.inactive');
    }

    /**
     * খাতার (কাগজ নয়) সারির পুরো মেনু: মাথা · দেখা · কাজ — আগের কাজগুলো যেমন ছিল তেমনই, কেবল সাজানো আর চিহ্ন বসানো।
     * ⓘ চিহ্ন না দেওয়া কাজে: সম্পাদনা ✎, পাকা মোছা 🗑, নিষ্ক্রিয় ⏸, সক্রিয়/ডিফল্ট ✓, বাকিটা ➜।
     *
     * @param  list<array<string, mixed>>  $work
     * @return list<array<string, mixed>>
     */
    public static function dress(array $work, string $head, string $state, ?string $viewUrl = null, ?string $historyQ = null, ?string $module = null): array
    {
        $look = self::look($viewUrl, $historyQ, $module);
        $work = array_values(array_filter($work, fn ($i) => ! $viewUrl || ($i['url'] ?? null) !== $viewUrl || isset($i['method'])));

        // ⛔ কিছুই করার নেই (চাবি নেই) — খালি মেনু, বোতামও নয়; আগের মতো ঘরটা ফাঁকা
        if ($look === [] && $work === []) {
            return [];
        }

        $items = array_merge([self::head($head, $state)], $look);

        if ($work !== []) {
            $items[] = self::group('work');

            foreach ($work as $item) {
                $url = (string) ($item['url'] ?? '');
                $item['icon'] ??= match (true) {
                    str_ends_with($url, '/edit') => 'edit',
                    ($item['method'] ?? null) === 'delete' && ($item['tone'] ?? null) === 'danger'
                        && (str_contains($url, 'purge') || ($item['label'] ?? '') === __('core.action.delete')) => 'trash',
                    ($item['tone'] ?? null) === 'danger' => 'pause',
                    isset($item['method']) => 'check-circle',
                    default => 'play',
                };
                $item['peek'] ??= false;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * কাগজের তারিখের মাসটা খোলা কি না।
     *
     * ⚠️ সারিপ্রতি নতুন [[OpenPeriod]] হলে পঁচিশ সারির পাতায় পঁচিশবার একই প্রশ্ন — তাই এক অনুরোধে একটাই, আর সে নিজেই
     * মাসের উত্তর মনে রাখে। ⓘ এটা কেবল মেনুর ধূসর-না-সাদা; কাজটা করার মুখে সার্ভার আবার নিজে যাচাই করে।
     */
    public static function monthOpen(Carbon|string|null $date): bool
    {
        if ($date === null) {
            return true;
        }

        $app = app();

        if (! $app->bound(self::PERIOD)) {
            $app->instance(self::PERIOD, $app->make(OpenPeriod::class));
        }

        return $app->make(self::PERIOD)->isOpen($date);
    }
}
