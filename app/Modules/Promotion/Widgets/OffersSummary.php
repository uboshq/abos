<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Http\Controllers\PromotionApiController;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Support\ScopeKind;

/**
 * ⭐ ফোনের হোম-স্ক্রিনে চলতি অফার (৪×২; চাপলে ওয়েবের অফারের পাতা) — উইজেটের চুক্তি §১৮ (মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ নিজে কোনো "চলতি" নিয়ম লেখে না: সারিগুলো ফোনের নিজের অফারের দরজার হুবহু উত্তর ([[PromotionApiController::offers()]]) —
 * অবস্থা চালু আর আজ মেয়াদের ভিতরে ([[Promotion::scopeLiveOn()]], বিক্রির ইঞ্জিনের একই প্রশ্ন), কুপন বাদ, এই শাখায় বা
 * কোম্পানিতে প্রচার বন্ধ হলে কিছুই নয়, আর শাখা ও দোকানের দেয়াল (হেডারের শাখা, বিক্রয়কর্মীর নিজের দোকান)।
 *
 * ⛔ তার উপর কেবল সই হওয়া অফার: `approved_at` আর `approved_by` দুটোই আছে — [[PromotionLifecycle]]-এর তিন পথেই বসে
 * (`approve()`, ছকের শেষ সই `signedOff()`, আর অনুমোদন বন্ধ থাকলে `activate()`-এ চালুকারীর নাম)। মানচিত্রে চালু হওয়ার পথ
 * কেবল অনুমোদিত থেকে ([[PromotionStatus::nextAllowed()]]), তাই সাধারণ পথে সই ছাড়া চালু অফার হয় না; ⚠️ কিন্তু আমদানি বা
 * হাতে বসানো সারিতে অবস্থা চালু অথচ সই নেই হতে পারে — সেটা উইজেটে আসে না।
 *
 * ⓘ গ্রাহকের নাম "নাম · পয়েন্ট · শাখা" ([[Customer::nameWithPoint()]]), দোকান কেবল এই মানুষের চোখে পড়া (দরজার একই দেয়াল)।
 * ⓘ কেবল পড়া; টাকার অঙ্ক নেই।
 */
final class OffersSummary implements WidgetSummary
{
    /** বড় মাপে সর্বোচ্চ সারি — উইজেটের চুক্তি */
    public const ROWS = 8;

    public function key(): string
    {
        return 'offers';
    }

    public function module(): string
    {
        return 'promotion';
    }

    /** ⓘ ওয়েবের অফারের তালিকা আর ফোনের অফারের দরজার একই চাবি — মাঠের SR-এর ছাঁচে (Field Sales) আছে */
    public function permission(): ?string
    {
        return 'promotion.view';
    }

    public function data(User $user): array
    {
        $rows = app(PromotionApiController::class)->offers()->getData(true)['rows'] ?? [];

        $signed = Promotion::query()
            ->whereIn('public_id', array_column($rows, 'id'))
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_by')
            ->with('scopes')
            ->get()
            ->keyBy('public_id');

        $rows = array_values(array_filter($rows, fn (array $row): bool => $signed->has($row['id'])));
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['ends_on'] ?? '9999-12-31'), (string) ($b['ends_on'] ?? '9999-12-31')));
        $shown = array_slice($rows, 0, self::ROWS);

        $names = $this->scopeNames(collect($shown)->map(fn (array $row) => $signed[$row['id']])->all());

        return [
            'as_of' => now()->toIso8601String(),
            'count' => count($rows),
            'rows' => array_map(function (array $row) use ($signed, $names): array {
                $offer = $signed[$row['id']];

                return [
                    'title' => (string) $row['name'],
                    'product' => ($row['products'] ?? []) === [] ? null : implode(', ', $row['products']),
                    'scope' => $this->scopeOf($offer, $row, $names),
                    'ends_on' => $row['ends_on'] ?? null,
                    'link' => route('promotion.show', $offer->id, false),
                ];
            }, $shown),
            'link' => route('promotion.index', [], false),
        ];
    }

    /**
     * কার জন্য — শাখা, ডিলারের দল, এলাকা, দোকান; কিছু না থাকলে null (সবার জন্য)।
     *
     * @param  array<string, mixed>  $row
     * @param  array{branch: array<int, string>, customer: array<int, string>}  $names
     */
    private function scopeOf(Promotion $offer, array $row, array $names): ?string
    {
        $of = fn (ScopeKind $kind): array => $offer->scopes
            ->filter(fn (PromotionScope $s) => $s->kind === $kind)
            ->map(fn (PromotionScope $s) => $names[$kind->value][(int) $s->target_id] ?? null)
            ->filter()->unique()->values()->all();

        $parts = [...$of(ScopeKind::BRANCH), ...($row['party_types'] ?? []), ...($row['areas'] ?? []), ...$of(ScopeKind::CUSTOMER)];

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * এক ডাকে শাখা আর দোকানের নাম — সারির সংখ্যায় কোয়েরি বাড়ে না।
     * ⛔ দোকান কেবল এই মানুষের চোখে পড়া ([[Customer::scopeInViewedBranch()]] আর মডেলের ডিলার-স্কোপ, দরজার একই দেয়াল)।
     *
     * @param  list<Promotion>  $offers
     * @return array{branch: array<int, string>, customer: array<int, string>}
     */
    private function scopeNames(array $offers): array
    {
        $ids = [ScopeKind::BRANCH->value => [], ScopeKind::CUSTOMER->value => []];
        foreach ($offers as $offer) {
            foreach ($offer->scopes as $scope) {
                if (isset($ids[$scope->kind->value])) {
                    $ids[$scope->kind->value][] = (int) $scope->target_id;
                }
            }
        }

        $branches = $ids['branch'] === [] ? [] : Branch::query()->whereIn('id', array_unique($ids['branch']))->get()
            ->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])->all();
        $shops = $ids['customer'] === [] ? [] : Customer::query()->inViewedBranch()->with(Customer::PLACE)
            ->whereIn('id', array_unique($ids['customer']))->get()
            ->mapWithKeys(fn (Customer $c) => [(int) $c->id => $c->nameWithPoint()])->all();

        return ['branch' => $branches, 'customer' => $shops];
    }
}
