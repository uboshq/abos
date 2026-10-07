<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বীমা পলিসি বসানো, বদলানো আর নবায়ন।
 *
 * ⓘ প্রতিটা মেয়াদে একটা প্রিমিয়ামের সারি বসে (খসড়া)। টাকা দেওয়া হয়
 * পরিশোধ ভাউচারে; পলিসির পর্দা কেবল বোতাম দেয়।
 */
final class InsuranceService
{
    public function __construct(private readonly InstitutionService $institutions) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): InsurancePolicy
    {
        return DB::transaction(function () use ($data) {
            $data['institution_id'] = $this->insurer($data);
            $this->assertPolicyNoIsFree($data);

            $policy = InsurancePolicy::query()->create([
                ...$this->clean($data),
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            $this->addPremium($policy, $policy->starts_on, $policy->ends_on, (string) $policy->premium);

            return $policy;
        });
    }

    /**
     * ⚠️ চলতি মেয়াদের প্রিমিয়াম এখনো খসড়া থাকলে সেটাও পলিসির সাথে
     * মিলিয়ে বদলায় — নাহলে ভুল টাইপ করা প্রিমিয়াম শোধরানোর পরেও
     * "টাকা দিন" বোতাম পুরনো অঙ্কটাই নিয়ে যেত। দেওয়া হয়ে গেলে ছোঁয়া হয়
     * না: ওটা এখন খাতার কথা।
     *
     * @param  array<string, mixed>  $data
     */
    public function update(InsurancePolicy $policy, array $data): InsurancePolicy
    {
        return DB::transaction(function () use ($policy, $data) {
            $data['institution_id'] = $this->insurer($data);
            $this->assertPolicyNoIsFree($data, $policy->id);

            $wasFrom = $policy->starts_on->toDateString();
            $wasTo = $policy->ends_on->toDateString();

            $policy->update($this->clean($data));

            /*
             * ⭐ চলতি মেয়াদের কিস্তিগুলো — একটাও দেওয়া না হলে নতুন অঙ্ক, দিন আর কিস্তির ধরনে আবার ভাগ (পরিকল্পনা ৬.২)।
             * ⛔ একটাও দেওয়া হয়ে গেলে কিছুই ছোঁয়া হয় না — ওটা এখন খাতার কথা।
             */
            $term = InsurancePremium::query()->where('policy_id', $policy->id)
                ->whereBetween('period_from', [$wasFrom, $wasTo]);

            if (! (clone $term)->where('status', '!=', InsurancePremium::DRAFT)->exists()
                && ! (clone $term)->whereNotNull('voucher_id')->exists()
                && (clone $term)->exists()) {
                (clone $term)->delete();
                $this->addPremium($policy, $policy->starts_on, $policy->ends_on, (string) $policy->premium);
            }

            // ⓘ শূন্য প্রিমিয়ামে বসানো পলিসিতে পরে অঙ্ক বসলে — তখনই প্রথম সারি
            if (! InsurancePremium::query()->where('policy_id', $policy->id)->exists()) {
                $this->addPremium($policy, $policy->starts_on, $policy->ends_on, (string) $policy->premium);
            }

            return $policy->fresh();
        });
    }

    /**
     * নবায়ন — নতুন মেয়াদ, নতুন প্রিমিয়াম; আগের মেয়াদ প্রিমিয়ামের সারিতে থাকে।
     *
     * ⛔ নতুন মেয়াদ আগেরটার শেষের আগে শুরু হতে পারে না: তাহলে একই দিনের
     * জন্য দুইবার প্রিমিয়াম গুনত, আর "পাঁচ বছরে কত গেল" প্রশ্নের উত্তর
     * বাড়িয়ে বলত।
     *
     * @param  array{starts_on: string, ends_on: string, premium: string|int|float, sum_insured?: string|int|float|null}  $data
     */
    public function renew(InsurancePolicy $policy, array $data): InsurancePolicy
    {
        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on']);

        if ($from->lte($policy->ends_on)) {
            throw ValidationException::withMessages([
                'starts_on' => __('finance::insurance.renew_overlaps', ['date' => $policy->ends_on->toDateString()]),
            ]);
        }

        return DB::transaction(function () use ($policy, $data, $from, $to) {
            $policy->update([
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'premium' => $data['premium'],
                'sum_insured' => $data['sum_insured'] ?? $policy->sum_insured,
                'is_active' => true,
            ]);

            $this->addPremium($policy, $from, $to, (string) $data['premium']);

            return $policy->fresh();
        });
    }

    public function setActive(InsurancePolicy $policy, bool $active): InsurancePolicy
    {
        $policy->update(['is_active' => $active]);

        return $policy;
    }

    private function addPremium(InsurancePolicy $policy, mixed $from, mixed $to, string $amount): void
    {
        /*
         * ⓘ শূন্য প্রিমিয়াম (কোম্পানির দেওয়া বা গ্রুপ বীমা) মানে দেওয়ার
         * কিছু নেই — খসড়া সারি বসালে সেটা চিরকাল "দেওয়া বাকি" দেখাত।
         */
        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        /*
         * ⭐ কিস্তিতে ভাগ — পরিকল্পনা ৬.২, ৬ অক্টোবর ২০২৬: মেয়াদের শুরু থেকে কিস্তির মাপে (বছরে ১২, ছয় মাসে ৬, তিন মাসে ৩, মাসে
         * ১ মাস) এক এক ভাগ, শেষেরটা মেয়াদের শেষ দিন পর্যন্ত। অঙ্ক সমান ভাগ, পয়সায় গোল; ⓘ শেষ কিস্তি বাকিটা নেয়, তাই যোগফল
         * হুবহু প্রিমিয়াম। "বছরে" মানে পুরো মেয়াদ একটা সারি — মেয়াদ বারো মাসের না হলেও, যেমন ছিল।
         */
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $step = InsurancePolicy::FREQUENCIES[$policy->frequency ?? InsurancePolicy::YEARLY] ?? 12;
        $parts = [];

        for ($at = $start; $at->lte($end); $at = $at->addMonthsNoOverflow($step)) {
            $next = $at->addMonthsNoOverflow($step)->subDay();
            $parts[] = [$at, ($step === 12 || $next->gt($end)) ? $end : $next];

            if ($step === 12) {
                break;
            }
        }

        // ⓘ এক সারি হলে অঙ্ক হুবহু (চার ঘর); ভাগ হলে পয়সায় গোল, আর শেষটা বাকি সবটুকু — যোগফল হুবহু প্রিমিয়াম
        $share = count($parts) === 1 ? bcadd($amount, '0', 4) : bcdiv($amount, (string) count($parts), 2);
        $left = bcadd($amount, '0', 4);

        foreach ($parts as $i => [$partFrom, $partTo]) {
            $piece = $i === count($parts) - 1 ? $left : $share;
            $left = bcsub($left, $piece, 4);

            InsurancePremium::query()->create([
                'company_id' => $policy->company_id,
                'policy_id' => $policy->id,
                'period_from' => $partFrom->toDateString(),
                'period_to' => $partTo->toDateString(),
                'amount' => $piece,
                'status' => InsurancePremium::DRAFT,
                'created_by' => auth()->id(),
            ]);
        }
    }

    /**
     * বীমা কোম্পানি — তালিকা থেকে বাছা, অথবা এখানেই যোগ করা।
     *
     * @param  array<string, mixed>  $data
     */
    private function insurer(array &$data): int
    {
        $id = $this->institutions->resolve($data, Institution::INSURANCE);

        if ($id === null) {
            throw ValidationException::withMessages([
                'institution_id' => __('finance::insurance.insurer_required'),
            ]);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $data */
    private function assertPolicyNoIsFree(array $data, ?int $exceptId = null): void
    {
        $clash = InsurancePolicy::query()
            ->where('institution_id', $data['institution_id'])
            ->where('policy_no', trim((string) ($data['policy_no'] ?? '')))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'policy_no' => __('finance::insurance.policy_no_taken'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $keep = ['institution_id', 'policy_no', 'covers', 'subject', 'sum_insured', 'premium', 'frequency',
            'starts_on', 'ends_on', 'notes'];

        $out = collect($data)
            ->only($keep)
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->all();

        // ⓘ অঙ্ক না জানা মানে শূন্য, খালি নয় — কলামটা null নেয় না
        if (array_key_exists('sum_insured', $out)) {
            $out['sum_insured'] ??= 0;
        }

        return $out;
    }
}
