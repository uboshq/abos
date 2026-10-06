<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ এই ডিলার কার — আজ বাঁধা বিক্রয়কর্মী(রা) আর তাঁদের উপরের প্রত্যেকে (SM · TSM · RSM · DSM)।
 * ⛔১৬, ২ অক্টোবর ২০২৬ — abos-2c-র ডেলিভারির খবরের জন্য স্থির দরজা (*"ডিলার → SR → এলাকার
 * ম্যানেজার"*)।
 *
 * ⓘ দেখার দেয়াল ([[DealerScope]]) নিচের দিকে হাঁটে — একজন কাকে দেখেন; এটা উপরের দিকে —
 * একটা ডিলারের খবর কার কাছে যাবে। দুইটাই একই দুই টেবিল পড়ে (`dealer_bindings`,
 * `staff_supervisors`), তাই উত্তর কখনো আলাদা হয় না।
 *
 * ⛔ কখনো ছোড়ে না: বাঁধনহীন ডিলারে খালি তালিকা। কেবল ডিলারের নিজের কোম্পানি — অন্য কোম্পানির
 * কেউ কখনো আসে না, বাঁধন বা গাছে ভুল করে বসে থাকলেও।
 */
final class DealerOwnership
{
    /**
     * ⭐ বিক্রির দিনে এই ডিলার কার — লক্ষ্যমাত্রার অর্জন আর কমিশন **একটাই** উত্তর এখান থেকে
     * পায় (মালিকের উত্তর ৫, ২৬ সেপ্টেম্বর; "ক", ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ বিল যিনি কেটেছেন তিনি নন, আজ যিনি বাঁধা তিনিও নন — **বিলের তারিখে** যিনি বাঁধা। হাতবদলের
     * আগের বিক্রি পুরনো জনের, পরের বিক্রি নতুন জনের।
     * ⓘ একই দিনে কয়েকজন বাঁধা থাকলে (এক পয়েন্টে কয়েকজন SR) — যাঁর বাঁধন আগে শুরু, সমান হলে
     * যাঁর সারি আগে। ⛔ বাঁধনহীন ডিলারে `null` — বিক্রিটা কারো খাতায় ওঠে না।
     */
    public function srOn(Customer|int $customer, CarbonInterface $date): ?int
    {
        $companyId = $customer instanceof Customer ? (int) $customer->company_id : (int) \App\Core\Support\CompanyContext::id();
        $customerId = $customer instanceof Customer ? (int) $customer->getKey() : $customer;

        $id = DB::query()
            ->selectSub(self::boundOn(DB::raw((string) $customerId), DB::raw(DB::getPdo()->quote($date->toDateString())), DB::raw((string) $companyId)), 'sr')
            ->value('sr');

        return $id === null ? null : (int) $id;
    }

    /**
     * ⭐ একই নিয়ম SQL-এ — একটা সারিতে বসানো সাবকোয়েরি, রিপোর্ট আর অর্জনের হিসাবের জন্য।
     *
     * ⓘ [[srOn()]] নিজেও এটাই চালায়, তাই দুই পথের উত্তর কখনো আলাদা হয় না।
     *
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $customer  কলাম (যেমন `i.customer_id`)
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $date  কলাম (যেমন `i.trx_date`)
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $company  কলাম (যেমন `i.company_id`)
     */
    public static function boundOn(mixed $customer, mixed $date, mixed $company): \Illuminate\Database\Query\Builder
    {
        return DB::table('dealer_bindings as sr_b')
            ->select('sr_b.user_id')
            ->whereColumn('sr_b.company_id', '=', $company)
            ->whereColumn('sr_b.customer_id', '=', $customer)
            ->whereColumn('sr_b.starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('sr_b.ends_on')->orWhereColumn('sr_b.ends_on', '>=', $date))
            ->orderBy('sr_b.starts_on')
            ->orderBy('sr_b.id')
            ->limit(1);
    }

    /**
     * আজ বাঁধা বিক্রয়কর্মী আর তাঁদের উপরের গোটা সারি — ব্যবহারকারীর আইডি, একবার করে।
     *
     * @return Collection<int, int>
     */
    public function peopleFor(Customer $customer): Collection
    {
        $companyId = (int) $customer->company_id;
        $today = Carbon::today()->toDateString();

        $bound = DB::table('dealer_bindings')
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->getKey())
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($bound === []) {
            return collect();
        }

        $above = DB::table('staff_supervisors')
            ->where('company_id', $companyId)
            ->pluck('supervisor_id', 'user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $people = [];

        foreach ($bound as $id) {
            // ⓘ উপরের দিকে হাঁটা — চক্র থাকলেও থামে (`$people`-এ আগেই থাকলে)
            while ($id !== 0 && ! isset($people[$id])) {
                $people[$id] = true;
                $id = $above[$id] ?? 0;
            }
        }

        // ⛔ কেবল এই কোম্পানির সদস্য — অন্য কোম্পানির কেউ কখনো নয়
        return User::query()
            ->whereIn('id', array_keys($people))
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
