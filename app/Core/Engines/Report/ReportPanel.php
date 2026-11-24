<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\ListFilter\Facet;
use App\Core\ListFilter\Facets;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ⭐ ইঞ্জিনের রিপোর্টের ডান পাশের ছাঁকনি — বিক্রয়, হিসাব, মজুদ, ক্রয়, গ্রাহক, অনুমোদন, বাকির সব রিপোর্ট একই পাতায়
 *   (`accounts::report.show`), তাই প্যানেলও এক জায়গায় (মালিক, ২৩ নভেম্বর ২০২৬: *"রিপোর্টে ছাঁকা যায় না"*)।
 *
 * ── কোন ঘর ──────────────────────────────────────────────────────────────
 *   রিপোর্ট যা ঘোষণা করে, আর পাতা আগে যা আঁকত — ঠিক সেগুলোই, একই চাবিতে:
 *     তারিখ (from/to, "শুরু থেকে") বা "যে তারিখ পর্যন্ত" (to) · হিসাব (account_id) · প্রকল্প (cost_center_id) ·
 *     রিপোর্টের সাধারণ ছাঁকনি (গুদাম, পণ্য, দোকান… — [[ReportFilters]]) · শাখা (branch_id) · পক্ষ (পাতার `partyFilter`) ·
 *     পক্ষের ধরন (party_type_id) · তুলনা (compare) · উপরের কয়টা (top)।
 *   ⛔ নতুন কিছু নয়, কিছু বাদও নয়। ইঞ্জিন আগের মতোই ঠিকানা পড়ে ছাঁকে ([[ReportEngine]]); প্যানেল কেবল আঁকে।
 *
 * ⓘ তারিখের ঘরে ইঞ্জিনের **মানা** পরিসর (ডিফল্ট "এই মাস" সহ) — বারের "📅 এই মাস" আর সংখ্যাগুলো একই কথা বলে।
 * ⓘ রিপোর্ট চলে কেবল "দেখান" বা Enter-এ (মালিক, ২৩ নভেম্বর ২০২৬) — প্যানেলের নিচের বোতামটাই "দেখান" ([[filter-layout]] `report`)।
 */
final class ReportPanel
{
    /** ⓘ এর বেশি নাম হলে নিচে নামের তালিকার বদলে লিখে খোঁজার ঘর ([[Facets::search()]]) */
    private const FIND_UP_TO = 30;

    /**
     * @param  array<string, mixed>  $filters  ইঞ্জিনের মানা ছাঁকনি ([[ReportResult::$filters]])
     * @param  list<string>  $own  পাতা নিজে যে ঘরগুলো আঁকে (`$ownFilters`) — এখানে দ্বিতীয়বার নয়
     * @param  list<Facet>  $extra  রিপোর্টের নিজের ঘর — কন্ট্রোলার পাঠায় (`$extraFacets`: চেকের দিক ও অবস্থা, ফেরতের দোকান ও পণ্য)
     * @return array<string, mixed>
     */
    public static function build(
        ReportDefinition $report,
        array $filters,
        Collection $accounts,
        Collection $centres,
        Collection $branches,
        ?string $partyFilter,
        iterable $parties,
        Collection $partyTypes,
        array $own,
        Request $request,
        array $extra = [],
    ): array {
        $shown = self::shownRequest($report, $filters, $request);

        return Facets::panel([
            self::dates($report, $filters),
            $accounts->isNotEmpty()
                ? Facets::pick('account_id', __('core.print.account'), $accounts->mapWithKeys(fn ($a) => [(string) $a->id => $a->label()])->all())->look('violet', '▤')
                : null,
            $centres->isNotEmpty()
                ? Facets::pick('cost_center_id', __('accounts::field.cost_center'),
                    $centres->mapWithKeys(fn ($c) => [(string) $c->id => $c->code.' — '.$c->name()])->all(), __('accounts::field.pick_a_project'))->look('pink', '◎')
                : null,
            ...self::shared($report, $own, $partyFilter),
            ...$extra,
            $branches->isNotEmpty()
                ? Facets::pick('branch_id', __('core.company.branch'), $branches->mapWithKeys(fn ($b) => [(string) $b->id => $b->name()])->all())->look('blue', '🏬')
                : null,
            // ⭐ পক্ষ লিখে খোঁজা — নাম, কোড, মোবাইল, ঠিকানা, পয়েন্ট; তালিকা আগের মতোই পাতার নিজের (মালিক, ২৩ নভেম্বর ২০২৬)
            ! empty($partyFilter)
                ? Facets::search($partyFilter, __('accounts::party_ledger.pick_party'), Facets::partyRows(
                    self::partyType($partyFilter),
                    collect($parties)->mapWithKeys(fn ($p) => [(string) $p->id => (string) $p->name])->all(),
                ))->look('green', '👤')
                : null,
            $partyTypes->isNotEmpty()
                ? Facets::pick('party_type_id', __('master_data::menu.party_types'), $partyTypes->mapWithKeys(fn ($t) => [(string) $t->id => $t->name()])->all())->look('slate', '◇')
                : null,
            ...self::ranking($report),
        ], $shown);
    }

    /** তারিখ — পরিসর (বারে "📅"), বা জেরের রিপোর্টে কেবল "যে তারিখ পর্যন্ত" */
    private static function dates(ReportDefinition $report, array $filters): ?Facet
    {
        if (! $report->hasFilter('date_range')) {
            return null;
        }

        if ($report->isAsOfDate()) {
            return Facets::day('to', __('accounts::field.as_on'), isset($filters['to']) ? (string) $filters['to'] : null);
        }

        return Facets::dates(__('core.list_filter.date'))
            ->withPreset('all_time', __('core.report.all_time'),
                ['from' => ReportEngine::ALL_TIME, 'to' => now()->toDateString()], ! empty($filters['all_time']));
    }

    /**
     * রিপোর্টের সাধারণ ছাঁকনি — লিখে-খোঁজা, নিচে নামের তালিকা (আগের ঘরের মতোই "কোড · নাম")।
     *
     * @param  list<string>  $own
     * @return list<Facet>
     */
    private static function shared(ReportDefinition $report, array $own, ?string $partyFilter): array
    {
        $sources = app(ReportFilters::class)->sources();
        $facets = [];

        foreach ($report->filters as $key) {
            if (! isset($sources[$key]) || in_array($key, [...$own, $partyFilter], true)) {
                continue;
            }

            $name = __($sources[$key]->label());
            $options = $sources[$key]->options();

            /*
             * ⭐ লম্বা তালিকা (দোকান, সরবরাহকারী, পণ্য) — লিখে খোঁজার ঘর, মান নম্বর (ইঞ্জিন নম্বর নেয়, [[ModelFilterSource::resolve()]]);
             *   পক্ষ হলে মোবাইল, ঠিকানা, পয়েন্ট দিয়েও (মালিক, ২৩ নভেম্বর ২০২৬)। ছোট তালিকা আগের মতো "কোড · নাম" লেখার ঘর।
             */
            $facets[] = count($options) > self::FIND_UP_TO
                ? Facets::search($key, $name, self::partyType($key) !== ''
                    ? Facets::partyRows(self::partyType($key), $options)
                    : array_map(fn ($id, $label) => ['id' => (int) $id, 'label' => (string) $label], array_keys($options), $options))
                : Facets::find($key, $name, $options, $name.' — '.__('core.report.filters.any'));
        }

        return $facets;
    }

    /** তুলনা ও "উপরের কয়টা" — কেবল যে রিপোর্টে "সবচেয়ে বড়" বলে কিছু আছে (`rankBy`) */
    private static function ranking(ReportDefinition $report): array
    {
        if ($report->rankBy === null) {
            return [];
        }

        $compare = [];
        foreach ([ReportEngine::COMPARE_PREVIOUS, ReportEngine::COMPARE_LAST_YEAR] as $option) {
            $compare[$option] = __('core.report.compare_'.$option);
        }

        $top = [];
        foreach ([5, 10, 20, 50] as $n) {
            $top[(string) $n] = __('core.report.top_n', ['count' => $n]);
        }

        return [
            Facets::pick('compare', __('core.report.compare'), $compare, __('core.report.compare_none'))->look('amber', '⇄'),
            Facets::pick('top', __('core.report.top'), $top, __('core.report.top_all'))->look('red', '▲'),
        ];
    }

    /**
     * প্যানেল যে ঠিকানা দেখে — আসল ঠিকানা, আর তাতে ইঞ্জিনের মানা তারিখ (ঠিকানায় না থাকলে ডিফল্টটা)।
     *
     * ⓘ "যে তারিখ পর্যন্ত" কেবল ঠিকানায় থাকলে — আজকের তারিখ চিপ হয়ে বসে থাকলে প্রতিটা জেরের রিপোর্ট "ছাঁকা" দেখাত।
     */
    private static function shownRequest(ReportDefinition $report, array $filters, Request $request): Request
    {
        $shown = $request->duplicate();

        if ($report->hasFilter('date_range') && ! $report->isAsOfDate()) {
            if (! empty($filters['all_time'])) {
                $shown->query->set('from', ReportEngine::ALL_TIME);
            } elseif (! empty($filters['from'])) {
                $shown->query->set('from', (string) $filters['from']);
            }

            if (! empty($filters['to'])) {
                $shown->query->set('to', (string) $filters['to']);
            }
        }

        return $shown;
    }

    /** ⓘ রিপোর্টের পক্ষের চাবি (`…_id`) → মডিউলের ঘোষিত পক্ষের ধরন ([[PartyRegistry]]); পক্ষ না হলে খালি */
    private static function partyType(string $key): string
    {
        $type = str_ends_with($key, '_id') ? substr($key, 0, -3) : '';

        return $type !== '' && app(\App\Core\Services\PartyRegistry::class)->knows($type) ? $type : '';
    }
}
