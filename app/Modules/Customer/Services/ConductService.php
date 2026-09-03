<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Customer\Support\ConductType;
use Illuminate\Validation\ValidationException;

/**
 * পার্টির আচরণ লেখা ও নামানো — মোছা কখনো নয়।
 *
 * Status বলে কারবার করা যাবে কিনা; আচরণ বলে কেমন কারবার। দুইটা আলাদা
 * প্রশ্ন, তাই আলাদা সার্ভিস।
 */
final class ConductService
{
    /**
     * একটা আচরণ লেখা।
     *
     * ── দুইটা নিয়ম এখানেই ───────────────────────────────────────────
     * `recorded_by` সবসময় বসে (বেনামি লাল পতাকা প্রশ্নের অতীত), আর
     * `OTHER` বাছলে নোট বাধ্যতামূলক — নাহলে বাঁধা তালিকাটা মুক্ত লেখার
     * পিছনের দরজা হয়ে যেত।
     */
    public function record(Customer $customer, string $type, ?string $note = null): CustomerConduct
    {
        if (! ConductType::isValid($type)) {
            throw ValidationException::withMessages([
                'type' => __('customer::conduct.invalid_type'),
            ]);
        }

        if (ConductType::requiresNote($type) && blank($note)) {
            throw ValidationException::withMessages([
                'note' => __('customer::conduct.note_required'),
            ]);
        }

        return CustomerConduct::create([
            'company_id' => CompanyContext::id(),
            'customer_id' => $customer->id,
            'type' => $type,
            'note' => filled($note) ? trim((string) $note) : null,
            'is_active' => true,
            'recorded_by' => auth()->id(),
            'recorded_at' => now(),
        ]);
    }

    /**
     * পতাকা নামানো — সারিটা থাকে, শুধু আর চলমান নয়।
     *
     * মোছা হয় না: "আগে দেরি করত, এখন ঠিক" — এই কথাটাই হারিয়ে যেত।
     * ইতিমধ্যে নামানো থাকলে কিছুই হয় না (idempotent)।
     */
    /**
     * একজন গ্রাহকের চলমান পতাকাগুলো — কাউন্টারে দেখানোর জন্য।
     *
     * ── কেন সবচেয়ে গুরুতরটা আগে ─────────────────────────────────────
     * কাউন্টারে জায়গা নেই আর সময়ও নেই। বিক্রয়কর্মী নামের পাশে এক
     * ঝলক দেখেন — সেখানে "৯০ দিন নেয়" আর "সবসময় সময়মতো দেয়" পাশাপাশি
     * থাকলে চোখ কোনটায় পড়বে তার কোনো নিশ্চয়তা নেই।
     *
     * তাই ক্রমটা `risk → notice → good`, আর তার ভিতরে নতুনটা আগে:
     * পুরনো একটা লাল পতাকার চেয়ে গত সপ্তাহের ঘটনাটাই বেশি কাজের।
     *
     * @return \Illuminate\Support\Collection<int, CustomerConduct>
     */
    public function activeFor(Customer $customer)
    {
        return $this->sorted(
            CustomerConduct::query()
                ->active()
                ->where('customer_id', $customer->id)
                ->get()
        );
    }

    /**
     * অনেক গ্রাহকের পতাকা একসাথে — তালিকার পর্দার জন্য।
     *
     * ⚠️ ── কেন এটা আলাদা পদ্ধতি ──────────────────────────────────────
     * সরাসরি বিক্রয়ের পর্দায় গ্রাহকের তালিকা আসে, আর প্রতিটা সারিতে
     * `activeFor()` ডাকলে **পঞ্চাশ সারিতে পঞ্চাশটা কোয়েরি**। ডিপোর
     * নেট ধীর, আর ওই পর্দাটাই সবচেয়ে বেশি খোলা হয়।
     *
     * ⓘ টেবিলের index-টা ঠিক এই কাজের জন্যই বসানো
     * (`company_id, customer_id, is_active` — মাইগ্রেশনের মন্তব্যে
     * লেখা আছে), কিন্তু আজ পর্যন্ত কেউ ওটা ব্যবহার করেনি।
     *
     * @param  list<int>  $customerIds
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, CustomerConduct>>
     */
    public function activeForMany(array $customerIds)
    {
        if ($customerIds === []) {
            return collect();
        }

        return CustomerConduct::query()
            ->active()
            ->whereIn('customer_id', $customerIds)
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($rows) => $this->sorted($rows));
    }

    /**
     * গুরুতর আগে, তারপর নতুন আগে।
     *
     * ⓘ সাজানোটা PHP-তে, ডাটাবেসে নয় — কারণ গুরুত্বটা `type` কোড থেকে
     * [[ConductType]] বের করে, আর ওই মিলটা ডাটাবেস জানে না। সারির
     * সংখ্যা একজন গ্রাহকপ্রতি হাতে গোনা, তাই খরচও নেই।
     *
     * @param  \Illuminate\Support\Collection<int, CustomerConduct>  $rows
     * @return \Illuminate\Support\Collection<int, CustomerConduct>
     */
    private function sorted($rows)
    {
        $rank = [ConductType::RISK => 0, ConductType::NOTICE => 1, ConductType::GOOD => 2];

        return $rows
            ->sortBy([
                fn (CustomerConduct $c) => $rank[$c->severity()] ?? 1,
                fn (CustomerConduct $c) => -$c->id,
            ])
            ->values();
    }

    public function retire(CustomerConduct $conduct): CustomerConduct
    {
        if (! $conduct->is_active) {
            return $conduct;
        }

        $conduct->forceFill([
            'is_active' => false,
            'retired_by' => auth()->id(),
            'retired_at' => now(),
        ])->save();

        return $conduct->fresh();
    }
}
