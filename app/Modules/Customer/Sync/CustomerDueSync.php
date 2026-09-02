<?php

declare(strict_types=1);

namespace App\Modules\Customer\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * দোকানের বকেয়া — সেলসম্যান অর্ডার নেওয়ার আগে যেটা দেখেন।
 *
 * ── ⚠️ এই হ্যান্ডলারের ওয়াটারমার্কটাই এর সবচেয়ে কঠিন অংশ ────────────
 * বকেয়া গ্রাহকের সারিতে **লেখা থাকে না** — সেটা গোনা হয় খতিয়ান থেকে
 * ([[Customer::outstanding()]]: `SUM(debit) - SUM(credit)`)। অর্থাৎ একটা
 * বিল কাটলে বা টাকা জমা পড়লে **বকেয়া বদলায় অথচ `customers.updated_at`
 * নড়েও না**।
 *
 * সরল পথটা — `where('updated_at', '>', $since)` — তাই **নীরবে ভুল** হত:
 * ফোন প্রথম সিঙ্কে বকেয়া পেত, তারপর দোকানটা তিন মাস ধরে টাকা দিত ও
 * মাল নিত, আর ফোনে সংখ্যাটা **প্রথম দিনেরটাই** থেকে যেত। সেলসম্যান
 * "৫,০০০ বাকি" দেখে অর্ডার নিতেন, বাস্তবে বাকি ৮০,০০০।
 *
 * তাই ওয়াটারমার্কটা আসে **খতিয়ানের শেষ নড়াচড়া থেকে** — গ্রাহকের সারি
 * থেকে নয়। একটা সাব-কোয়েরি, আর সেটাই দিয়ে ছাঁকা ও সাজানো দুটোই হয়।
 *
 * ── কেন সবগুলো প্রতিবার পাঠিয়ে দেওয়া হয় না ─────────────────────────
 * সেটাও সঠিক হত, আর অনেক সহজ। কিন্তু তখন `hasMore` চিরকাল সত্যি থাকত
 * (তালিকা লিমিটের চেয়ে বড় হলে), ফোন কোনোদিন `pull-complete` ডাকত না,
 * আর ওয়াটারমার্ক কোনোদিন এগোত না — প্রতিটা সিঙ্কে পুরো তালিকা, চিরকাল।
 */
final class CustomerDueSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'customer';
    }

    public static function entityType(): string
    {
        return 'CustomerDue';
    }

    /**
     * গ্রাহকের তালিকার চাবিটাই — বকেয়া ওই তালিকারই একটা ঘর, আলাদা
     * গোপনীয়তার স্তর নয়।
     */
    public static function requiredPermission(): ?string
    {
        return 'customer.view';
    }

    /**
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        /*
         * এই গ্রাহকের খাতায় শেষ কবে কিছু নড়েছে।
         *
         * `created_at`, `updated_at` নয় — খতিয়ানের সারি শুধু যোগের,
         * কখনো বদলায় না ([[EveryChangeableRowRemembersWhoChangedItTest]]-এ
         * `LedgerEntry` সেই কারণেই ছাড়প্রাপ্ত)। তাই ওখানে `updated_at`
         * ব্যবহার করা মানে এমন একটা ঘরের উপর নির্ভর করা যেটা নড়ে না।
         */
        $lastMoved = LedgerEntry::query()
            ->selectRaw('MAX(ledger_entries.created_at)')
            ->whereColumn('ledger_entries.party_id', 'customers.id')
            ->where('ledger_entries.party_type', Customer::drillSourceType());

        $query = Customer::query()
            ->withOutstanding()
            ->addSelect(['due_moved_at' => $lastMoved])
            ->orderBy('due_moved_at')
            ->orderBy('customers.id')
            ->limit($limit);

        if ($since !== null) {
            /*
             * ⚠️ গ্রাহকের নিজের সারিও গোনা হয়, শুধু খতিয়ান নয়।
             *
             * ক্রেডিট সীমা `customers`-এ বসে, আর সেটা বদলালে খতিয়ানে
             * কিছুই নড়ে না। কেবল খতিয়ান দেখলে সীমা বাড়ানোর খবরটা
             * ফোনে কোনোদিন পৌঁছাত না — আর ঠিক সেই সীমাটাই সেলসম্যান
             * দেখে অর্ডার নেন।
             */
            $query->where(function ($outer) use ($since, $lastMoved): void {
                $outer->where('customers.updated_at', '>', $since)
                    ->orWhere(fn ($inner) => $inner->whereRaw('('.$lastMoved->toSql().') > ?', [
                        ...$lastMoved->getBindings(),
                        $since,
                    ]));
            });
        }

        return $query->get()->map(function (Customer $customer): SyncRecord {
            $outstanding = $customer->outstanding();

            return new SyncRecord(
                entityType: self::entityType(),
                entityId: (string) $customer->public_id,
                payload: [
                    'customerId' => (string) $customer->public_id,

                    /*
                     * ধনাত্মক মানে দোকান আমাদের টাকা দেবে (ডেবিট জের),
                     * ঋণাত্মক মানে আমরা তাদের — অগ্রিম জমা দিলে যা হয়।
                     * চিহ্নটা ফোনে ব্যাখ্যা করা হয় না; ঠিক এই নিয়মেই
                     * ওয়েবের পর্দাটাও পড়ে।
                     */
                    'outstanding' => $outstanding,
                    'creditLimit' => (string) $customer->credit_limit,
                    'creditDays' => (int) $customer->credit_days,

                    /*
                     * সীমা শূন্য মানে "মাল দেওয়া বন্ধ" নয় — মানে
                     * নগদ/অগ্রিম। সিদ্ধান্তটা কোম্পানির সুইচে
                     * (`customer.zero_limit_blocks`), তাই ফোনে কাঁচা
                     * সংখ্যাটাই যায় আর পর্দা নিজে কিছু ধরে নেয় না।
                     */
                    'isActive' => (bool) $customer->is_active,
                ],
                updatedAt: $this->movedAt($customer),
            );
        })->all();
    }

    /**
     * কোনটা পরে — গ্রাহকের সারি, না খতিয়ানের শেষ নড়াচড়া।
     *
     * দুইটার বড়টাই ওয়াটারমার্ক, কারণ যেকোনো একটা বদলালেই ফোনের কাছে
     * থাকা সংখ্যাটা পুরনো হয়ে যায়।
     */
    private function movedAt(Customer $customer): Carbon
    {
        $ledger = $customer->getAttribute('due_moved_at');
        $ledgerAt = $ledger === null ? null : Carbon::parse((string) $ledger);
        $rowAt = $customer->updated_at ?? $customer->created_at;

        if ($ledgerAt === null) {
            return $rowAt ?? now();
        }

        if ($rowAt === null) {
            return $ledgerAt;
        }

        return $ledgerAt->greaterThan($rowAt) ? $ledgerAt : $rowAt;
    }

    public function acceptsPush(): bool
    {
        return false;
    }

    /**
     * বকেয়া কোনো নথি নয়, একটা **যোগফল** — ফোন থেকে "বসানোর" মতো জিনিসই
     * নয়। টাকা জমা পড়লে সেটা একটা আদায়, আর আদায় নেট ছাড়া লেখা যায় না
     * (মালিকের সিদ্ধান্ত: শুধু অর্ডার)।
     */
    public function apply(User $user, PushedChange $change): string
    {
        throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
    }
}
