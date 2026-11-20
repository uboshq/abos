<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Support\Money;
use App\Models\LedgerEntry;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ সরবরাহকারীর বাকির দেয়াল — মালিক, ১৮ নভেম্বর ২০২৬: সীমার ভিতরে বাকিতে মাল নেওয়া চলে; সীমা পেরোলে নতুন বিল বা মাল
 * গ্রহণ **নিশ্চিত হয় না** — টাকা দিন, বা সীমা বাড়ান (সইয়ে, [[SupplierLimitRequests]])।
 *
 * ── ⭐ মালিকের তিন কথা ─────────────────────────────────────────────────
 *   · ফাঁকা বা ০ সীমা = কোনো দেয়াল নেই — লাইভে প্রথম দিনেই কেনা থামবে না।
 *   · খসড়া লেখা থামে না; থামে কেবল নিশ্চিত/পাকা করার মুহূর্তে (আর সইয়ে পাঠানোর আগে — সইকারী এমন কাগজ পান না যা পাকা হবে না)।
 *   · "সতর্কতা কাজ থামায় না" — এর একমাত্র ব্যতিক্রম এই দেয়াল, মালিকের নিজের কথায়।
 *
 * ── ⓘ কী গোনা হয় ─────────────────────────────────────────────────────────
 * দেনা (২১১০-পরিবার) + বিল-না-আসা মাল (GRNI ২১৬০) — গোটা কোম্পানির, শাখা ধরে নয় ([[Supplier::isOverTheirLimit()]]-এর
 * একই অঙ্ক)। কাগজটা যতটা **নতুন** বাকি জন্মায় কেবল ততটা যোগ: মাল গ্রহণে পুরো মূল্য; চালান থেকে আসা বিলে কেবল চালানের
 * চেয়ে বেশিটুকু (মূল্য GRNI থেকে দেনায় সরে, মোট বাকি বাড়ে না); সরাসরি ক্রয়ে এখনই দেওয়া টাকা বাদে।
 *
 * ⓘ গ্রাহকের দেয়ালের ([[CreditExposure::assertRoom()]]) ছাঁচ: আগে তালা ছাড়া (সইয়ে পাঠানোর আগে), তারপর পাকা করার লেনদেনে
 * সরবরাহকারীর সারিতে তালা দিয়ে আবার — দুই টেবিলে একই মুহূর্তে দুই বিল একা একা সীমার ভিতরে, মিলে বাইরে, এমন যেন না হয়।
 */
final class SupplierCreditWall
{
    /** "সীমার কাছে" — সীমার এত ভাগ ভরলে তালিকার ছাঁকনিতে ওঠে */
    public const NEAR_SHARE = '0.9';

    /** সীমা বসানো কি না — ফাঁকা/০ মানে দেয়াল নেই */
    public static function hasLimit(Supplier $supplier): bool
    {
        return bccomp(bcadd((string) ($supplier->credit_limit ?? '0') ?: '0', '0', 4), '0', 4) > 0;
    }

    /**
     * সীমায় গোনা বাকি — দেনা + বিল-না-আসা মাল, গোটা কোম্পানির।
     *
     * ⓘ তালিকা [[scopeWithCreditUsed()]] দিয়ে এলে সারির ঘরটাই (`credit_used`)।
     */
    public function used(Supplier $supplier, bool $latest = false): string
    {
        if (! $latest && $supplier->getAttribute('credit_used') !== null) {
            return bcadd((string) $supplier->getAttribute('credit_used'), '0', 4);
        }

        $net = LedgerEntry::query()
            ->forParty(Supplier::drillSourceType(), (int) $supplier->id)
            ->whereIn('account_id', self::accountIds())
            ->when($latest, fn ($q) => $q->sharedLock())
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
            ->value('net') ?? 0;

        return bcadd((string) $net, '0', 4);
    }

    /** অবশিষ্ট জায়গা — সীমা − বাকি, ০-এর নিচে নয়; সীমা নেই = null */
    public function room(Supplier $supplier): ?string
    {
        if (! self::hasLimit($supplier)) {
            return null;
        }

        $left = bcsub((string) $supplier->credit_limit, $this->used($supplier), 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    /** তালিকার অবস্থা — `over` · `near` · `ok`; সীমা নেই = null */
    public function state(Supplier $supplier): ?string
    {
        if (! self::hasLimit($supplier)) {
            return null;
        }

        $used = $this->used($supplier);
        $limit = (string) $supplier->credit_limit;

        if (bccomp($used, $limit, 4) >= 0) {
            return 'over';
        }

        return bccomp($used, bcmul($limit, self::NEAR_SHARE, 4), 4) >= 0 ? 'near' : 'ok';
    }

    /**
     * ⛔ দেয়াল — এই কাগজ এলে সীমা পেরোবে কি না; পেরোলে বার্তাসহ থামে (ঘর `supplier_id` — বিলের পর্দাগুলো ঐ ঘরের নিচেই দেখায়)।
     *
     * @param  string  $adding  এই কাগজ যতটা নতুন বাকি জন্মায় (০ বা কম = কিছুই নয়, পার)
     * @param  string  $payingNow  এখনই দেওয়া টাকা (সরাসরি ক্রয়ের পর্দা)
     */
    public function assertRoom(Supplier $supplier, string $adding, string $payingNow = '0', bool $latest = false): void
    {
        if (! self::hasLimit($supplier)) {
            return;
        }

        $unpaid = bcsub(bcadd($adding, '0', 4), bcadd($payingNow ?: '0', '0', 4), 4);

        // ⓘ নতুন বাকি নেই (পুরো টাকা দেওয়া, বা বিল চালানের চেয়ে কম) — পুরনো দেনা যত বড়ই হোক, এই কাগজ সীমা পেরোয় না
        if (bccomp($unpaid, '0', 4) <= 0) {
            return;
        }

        $limit = bcadd((string) $supplier->credit_limit, '0', 4);
        $used = $this->used($supplier, $latest);

        if (bccomp(bcadd($used, $unpaid, 4), $limit, 4) <= 0) {
            return;
        }

        $left = bcsub($limit, $used, 4);

        throw ValidationException::withMessages(['supplier_id' => __('supplier::validation.over_their_limit', [
            'supplier' => $supplier->name(),
            'limit' => Money::format($limit),
            'used' => Money::format($used),
            'left' => Money::format(bccomp($left, '0', 4) > 0 ? $left : '0'),
            'adding' => Money::format($unpaid),
        ])]);
    }

    /**
     * ⛔ একই দেয়াল, সরবরাহকারীর সারিতে তালা দিয়ে — পাকা করার লেনদেনের ভিতরে।
     *
     * ⓘ সারিটা `FOR UPDATE`, খাতার যোগ `LOCK IN SHARE MODE` — বাইরের লেনদেন আগে সাধারণ কিছু পড়ে থাকলেও তাজা অঙ্ক
     * ([[CreditExposure::lockCustomer()]]-এর একই কারণ)। দ্বিতীয় টেবিল তালায় অপেক্ষা করে, প্রথমজনের নতুন দেনা দেখে।
     */
    public function assertRoomLocked(int $supplierId, string $adding, string $payingNow = '0'): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('SupplierCreditWall::assertRoomLocked() must run inside the posting transaction.');
        }

        $fresh = Supplier::query()->withoutGlobalScopes()->whereKey($supplierId)->lockForUpdate()->first();

        if ($fresh === null) {
            return;
        }

        $this->assertRoom($fresh, $adding, $payingNow, latest: true);
    }

    // ── তালিকার জন্য ─────────────────────────────────────────────────────────

    /** সীমায় গোনা খাত — দেনার পরিবার + GRNI-র পরিবার */
    public static function accountIds(): array
    {
        return array_values(array_unique([...Supplier::payableAccountIds(), ...Supplier::goodsNotBilledAccountIds()]));
    }

    /** সারি-ধরা সাব-কোয়েরি — সীমায় গোনা বাকি, গোটা কোম্পানির (শাখা ধরে নয়: দেয়াল যা দেখে, তালিকাও তা-ই) */
    public static function usedSubquery(): \Illuminate\Database\Eloquent\Builder
    {
        return LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0)')
            ->whereColumn('ledger_entries.party_id', 'suppliers.id')
            ->where('ledger_entries.party_type', Supplier::drillSourceType())
            ->whereIn('ledger_entries.account_id', self::accountIds());
    }

    /** তালিকায় `credit_used` ঘর যোগ */
    public static function withUsed(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('suppliers.*');
        }

        return $query->addSelect(['credit_used' => self::usedSubquery()]);
    }

    /**
     * তালিকার ছাঁকনি — `over` (জায়গা নেই) বা `near` (৯০%-এর বেশি ভরা, এখনো জায়গা আছে); সীমাহীন সারি কোনোটাতেই নয়।
     */
    public static function narrow(Builder $query, ?string $which): Builder
    {
        if (! in_array($which, ['over', 'near', 'over_or_near'], true)) {
            return $query;
        }

        $sub = self::usedSubquery()->toBase();
        $sql = '('.$sub->toSql().')';
        $bindings = $sub->getBindings();

        $query->where('suppliers.credit_limit', '>', 0);

        return match ($which) {
            'over' => $query->whereRaw($sql.' >= suppliers.credit_limit', $bindings),
            'near' => $query->whereRaw($sql.' < suppliers.credit_limit', $bindings)
                ->whereRaw($sql.' >= suppliers.credit_limit * '.self::NEAR_SHARE, $bindings),
            default => $query->whereRaw($sql.' >= suppliers.credit_limit * '.self::NEAR_SHARE, $bindings),
        };
    }
}
