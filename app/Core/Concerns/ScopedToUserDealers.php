<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ডিলার ধরে সারি ছাঁকা — বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলার আর তাঁদের কাগজ দেখেন
 * (⛔১৬, ২ অক্টোবর ২০২৬; নিয়ম [[DealerScope]]-এ)।
 *
 * ⓘ গ্লোবাল স্কোপ, তাই Eloquent-এর প্রতিটা পথ একসাথে ঢাকা: তালিকা, রুট-বাইন্ডিং (অন্যের
 * কাগজের ঠিকানা খুললে ৪০৪), তালিকার রপ্তানি, সর্বজনীন খোঁজা, ফোনের টেনে আনা ও পাঠানো,
 * সম্পর্ক (`$invoice->customer`)।
 *
 * ── ⚠️ দেয়ালটা ডিলার ধরে, কাগজের লেখক ধরে নয় ─────────────────────────────
 * একটা ডিলার দেখা গেলে তাঁর **সব** কাগজ দেখা যায় — হাতবদলের আগের বিলসহ (মালিকের
 * উত্তর ৫)। ⓘ তাই একটা দেখা ডিলারের বকেয়া বা বাকির সীমা কখনো অর্ধেক সারিতে গোনা হয়
 * না: হয় ডিলারটাই নেই, নয় তাঁর পুরোটা আছে।
 *
 * ── ⛔ যা এই trait ঢাকে না ───────────────────────────────────────────────
 * কাঁচা টেবিল-কোয়েরির পথ (কোয়েরি-বিল্ডার, মডেল নয়) — রিপোর্ট, উইজেট, ট্র্যাকিং। ওখানে [[DealerScope::restrict()]]
 * হাতে ডাকতে হয়, আর রিপোর্টের বেলায় [[ReportEngine::dealerWall()]]; না ডাকলে ইঞ্জিন
 * দেয়ালের ভিতরের মানুষকে রিপোর্টটা দেয়ই না (ফেরত, ফাঁস নয়)।
 */
trait ScopedToUserDealers
{
    public const DEALER_WALL = 'user-dealers';

    public static function bootScopedToUserDealers(): void
    {
        static::addGlobalScope(self::DEALER_WALL, function (Builder $builder): void {
            $scope = app(DealerScope::class);

            if (! $scope->walled()) {
                return;
            }

            $builder->getModel()->applyDealerWall($builder, $scope->dealerIds(auth()->user()));
        });
    }

    /**
     * কোন ঘরে ডিলারটা লেখা — কাগজে `customer_id`; গ্রাহকের নিজের তালিকায় `id`
     * ([[Customer::dealerScopeColumn()]])।
     */
    public function dealerScopeColumn(): string
    {
        return 'customer_id';
    }

    /**
     * দেয়ালটা কীভাবে বসে — ডিফল্টে নিজের ঘরে।
     *
     * ⓘ যে কাগজের নিজের ডিলার নেই, চালান দিয়ে যায় (ট্রিপ, গেট পাস, ডেলিভারির ধাপ, ছাপার
     * সারি), সে এটা বদলে চালানের ডিলার ধরে ([[Shipment::applyDealerWall()]])।
     *
     * @param  Builder<static>  $builder
     */
    public function applyDealerWall(Builder $builder, QueryBuilder $dealerIds): void
    {
        $builder->whereIn($this->getTable().'.'.$this->dealerScopeColumn(), $dealerIds);
    }

    /**
     * দেখা ডিলারের চালানগুলো — চালান-ধরে-চলা কাগজের দেয়ালের জন্য।
     */
    protected static function challansOfDealers(QueryBuilder $dealerIds): QueryBuilder
    {
        return DB::table('sal_challans')
            ->select('sal_challans.id')
            ->where('sal_challans.company_id', CompanyContext::id())
            ->whereIn('sal_challans.customer_id', $dealerIds);
    }

    /**
     * ⛔ দেয়াল উপেক্ষা — কেবল যাচাইয়ের পথে, দেখানোর পথে কখনো নয়।
     *
     * ⓘ যেমন ডিলার-সংকেত খালি কি না, নকল ঠেকানো — অন্যের ডিলার না দেখলে *"খালি"*
     * বলত, আর ডাটাবেজের unique নিয়ম ৫০০ দিত।
     *
     * @return Builder<static>
     */
    public static function acrossDealers(): Builder
    {
        return static::query()->withoutGlobalScope(self::DEALER_WALL);
    }
}
