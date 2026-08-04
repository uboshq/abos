<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;

/**
 * পুরনো হিসাব থেকে নিয়ে আসা ব্যালেন্স খাতায় বসানো।
 *
 * এটা না থাকলে যা হত — আর প্রথমে হয়েছিলও — গ্রাহক ও সরবরাহকারীর খোলা
 * ব্যালেন্স শুধু তাদের নিজের সারিতে বসে থাকত, লেজারে কিছুই যেত না। ফলে
 * একজনের পাতায় "প্রদেয় ১,২৫,০০০" দেখাত, অথচ প্রদেয় তালিকায় তার নামই
 * থাকত না — কারণ ওই রিপোর্ট লেজার থেকে গোনে। দুইটা সংখ্যা দুই জায়গা
 * থেকে এলে একদিন আলাদা হবেই, আর তখন কোনটা সত্যি তা বলার উপায় থাকে না।
 *
 * এখন খোলা ব্যালেন্সও একটা দাখিলা: পক্ষের খাত বনাম সঞ্চিত মুনাফা।
 * সঞ্চিত মুনাফাই ঠিক প্রতিপক্ষ, কারণ পুরনো বছরের ফল ওখানেই জমা —
 * নতুন খাতায় ঢোকার সময় সম্পদ ও দায়ের পার্থক্যটা ওটাই ধরে রাখে।
 *
 * দুই পক্ষের জন্য দুইটা পদ্ধতি, একটা সাধারণ post() নয়: চিহ্ন উল্টো,
 * আর "কোন দিকে ডেবিট" প্রশ্নটা ডাকার জায়গায় ছেড়ে দিলে একদিন কেউ
 * উল্টো দিকে বসাত — VoucherService::twoLineEntry()-তেও একই কারণে
 * সিদ্ধান্তটা একবারই নেওয়া হয়েছে।
 */
final class OpeningBalanceService
{
    /** এই দাখিলাগুলোর নিজস্ব ধরন — ড্রিল-ডাউনে পক্ষের পাতাতেই ফেরে। */
    public const SOURCE_SUFFIX = ':opening';

    public function __construct(private readonly PostingEngine $posting) {}

    /**
     * গ্রাহকের কাছে আমাদের পাওনা — প্রাপ্য ডেবিট।
     *
     * @return list<LedgerEntry>
     */
    public function forReceivable(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: StandardChart::RECEIVABLE,
            partyIsDebit: true,
        );
    }

    /**
     * সরবরাহকারীকে আমাদের দেনা — প্রদেয় ক্রেডিট।
     *
     * @return list<LedgerEntry>
     */
    public function forPayable(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: StandardChart::PAYABLE,
            partyIsDebit: false,
        );
    }

    /**
     * খোলা ব্যালেন্সের দাখিলা আছে কি না।
     *
     * সম্পাদনায় খোলা ব্যালেন্স বদলানো যায় না, তবু এটা দরকার: পুরনো
     * ডাটা ঠিক করার সময় জানতে হয় কার দাখিলা ইতিমধ্যেই বসেছে, নাহলে
     * দ্বিতীয়বার চালালে সংখ্যাটা দ্বিগুণ হত।
     */
    public function exists(string $partyType, int $partyId): bool
    {
        return LedgerEntry::query()
            ->where('source_type', $partyType.self::SOURCE_SUFFIX)
            ->where('source_id', $partyId)
            ->exists();
    }

    /**
     * @return list<LedgerEntry>
     */
    private function post(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date,
        string $partyAccount,
        bool $partyIsDebit,
    ): array {
        // শূন্য খোলা ব্যালেন্স মানে কিছুই আনার নেই — দাখিলা বসালে
        // লেজারে দুইটা শূন্য সারি থাকত, যা পড়ার সময় শুধু বিভ্রান্ত করে
        if (bccomp($amount, '0', 4) === 0) {
            return [];
        }

        /*
         * ঋণাত্মক খোলা ব্যালেন্স বাস্তব: সরবরাহকারীকে আগাম দেওয়া আছে,
         * বা গ্রাহক বেশি দিয়ে ফেলেছে। তখন দুই দিক উল্টে যায়, আর অঙ্কটা
         * ধনাত্মক করে বসাতে হয় — লেজারে ঋণাত্মক ডেবিট বলে কিছু নেই।
         */
        if (bccomp($amount, '0', 4) < 0) {
            $partyIsDebit = ! $partyIsDebit;
            $amount = bcmul($amount, '-1', 4);
        }

        $party = StandardChart::find($partyAccount);
        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($party === null || $equity === null) {
            throw new PostingException(
                'Opening balances need the standard chart — install it before adding parties with a balance.'
            );
        }

        $narration = $this->narration();

        $partyLine = [
            'account_id' => $party->id,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'narration' => $narration,
            $partyIsDebit ? 'debit' : 'credit' => $amount,
        ];

        $equityLine = [
            'account_id' => $equity->id,
            'narration' => $narration,
            $partyIsDebit ? 'credit' : 'debit' => $amount,
        ];

        return $this->posting->post(
            sourceType: $partyType.self::SOURCE_SUFFIX,
            sourceId: $partyId,
            trxDate: $this->dateFor($date),
            lines: [$partyLine, $equityLine],
            documentNo: $documentNo,
        );
    }

    /**
     * সারির বিবরণ — কোম্পানির ভাষায়, ব্যবহারকারীর নয়।
     *
     * এটাই একমাত্র জায়গা যেখানে সিস্টেম নিজে লেজারের narration লেখে,
     * আর লেখাটা সারিতে জমা থাকে। ব্যবহারকারীর ভাষা ধরলে ইংরেজিতে
     * কাজ করা হিসাবরক্ষকের বসানো সারি বাংলায় চলা প্রতিষ্ঠানের খাতায়
     * ইংরেজিতে থেকে যেত — একই খাতায় দুই ভাষা।
     */
    private function narration(): string
    {
        $locale = Company::query()->whereKey(CompanyContext::id())->value('locale');

        return __('accounts::message.opening_balance', [], $locale ?? config('app.locale'));
    }

    /**
     * তারিখ না বললে চলতি অর্থবছরের প্রথম দিন।
     *
     * আজকের তারিখ নয়: খোলা ব্যালেন্স বছরের শুরুর অবস্থা, আর মাঝপথে
     * বসালে ওই তারিখের আগের যেকোনো রিপোর্টে সংখ্যাটা উধাও হয়ে যেত।
     */
    private function dateFor(Carbon|string|null $date): Carbon
    {
        if ($date !== null) {
            return $date instanceof Carbon ? $date : Carbon::parse($date);
        }

        $year = FinancialYear::query()->where('is_current', true)->first();

        if ($year === null) {
            throw new PostingException(
                'Opening balances need a current financial year — none is set for this company.'
            );
        }

        return Carbon::parse($year->starts_on);
    }
}
