<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Finance\Models\BankFacility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ব্যাংকের সুবিধা খোলা, বন্ধ করা, আর কোনটা ফুরাতে চলেছে তা বলা।
 *
 * ── ⛔ যা এই সেবা করে না: টাকা নাড়া ──────────────────────────────────
 * একটাও দাখিলা এখান থেকে যায় না, আর সেটাই নকশার মূল কথা:
 * **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে · `voucher_id` দুইটাকে বাঁধে।**
 *
 * ⓘ মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ — *"ক্যাপিটাল থেকেই টাকা
 * রিসিভ করার ব্যবস্থা করো"*। ⚠️ দুইটা দরজা থাকলে একদিন একটায় চার্জের
 * ঘর বসত, অন্যটায় না, আর কেউ ধরত না।
 */
class BankFacilityService
{
    /**
     * নতুন সুবিধা।
     *
     * ── ⚠️ ধরন অনুযায়ী কোন ঘর লাগে, সেটা এখানেই মাপা হয় ─────────────
     * পাঁচটা ধরনের ঘরগুলো আলাদা, তাই "সব ঘর বাধ্যতামূলক" বলা যেত না।
     * ⛔ আর কিছুই না মাপলে অর্ধেক ভরা সারি বসত, আর পর্দায় খালি ঘর
     * দেখে কেউ বুঝত না কী হারিয়ে গেছে।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): BankFacility
    {
        $kind = (string) ($data['kind'] ?? '');

        $this->assertKindHasWhatItNeeds($kind, $data);

        return BankFacility::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'kind' => $kind,

            'bank' => $data['bank'],
            'branch_name' => $data['branch_name'] ?? null,
            'sanction_no' => $data['sanction_no'] ?? null,
            'sanctioned_on' => $data['sanctioned_on'],

            'limit_amount' => $data['limit_amount'],
            'interest_rate' => $data['interest_rate'] ?? 0,
            'term_months' => $data['term_months'] ?? null,

            /*
             * ⭐ নবায়নের তারিখ — দেওয়া না থাকলে মেয়াদ থেকে বসে।
             *
             * ⚠️ কিন্তু **সংরক্ষিত** হয়, প্রতিবার হিসাব করা হয় না: পরে
             * মেয়াদ বদলালে পুরনো মঞ্জুরিপত্রে লেখা তারিখটাও নীরবে বদলে
             * যেত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
             */
            'renews_on' => $data['renews_on'] ?? (
                isset($data['term_months'])
                    ? now()->addMonths((int) $data['term_months'])->toDateString()
                    : null
            ),

            'stock_value' => $data['stock_value'] ?? null,
            'margin_percent' => $data['margin_percent'] ?? null,
            'instalments' => $data['instalments'] ?? null,
            'instalment_amount' => $data['instalment_amount'] ?? null,
            'down_payment' => $data['down_payment'] ?? null,
            'charges' => $data['charges'] ?? 0,

            'security_type' => $data['security_type'] ?? BankFacility::UNSECURED,
            'security_value' => $data['security_value'] ?? null,
            'guarantors' => $data['guarantors'] ?? null,
            'covenant' => $data['covenant'] ?? null,

            'liability_account_id' => $data['liability_account_id'] ?? null,
            'money_account_id' => $data['money_account_id'] ?? null,

            'status' => DocumentStatus::CONFIRMED,
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * ⛔ ধরনটা যা ছাড়া অর্থহীন, সেটা না থাকলে থামা।
     *
     * ── কেন এটা ভ্যালিডেশনের নিয়মে লেখা যেত না ───────────────────────
     * `required_if:kind,cc` লেখা যেত, আর প্রথমে সেটাই সহজ মনে হয়।
     * ⚠️ কিন্তু নিয়মটা তখন **পাঁচ জায়গায় ছড়িয়ে** থাকত — প্রতিটা ঘরের
     * পাশে একটু করে — আর *"CC-তে আসলে কী কী লাগে"* প্রশ্নের উত্তর
     * কোথাও এক জায়গায় পড়া যেত না।
     *
     * ⭐ এখানে পাঁচটা ধরনের চাহিদা পাশাপাশি, তাই একদিন ভুল হলে
     * তুলনা করেই ধরা যায়।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertKindHasWhatItNeeds(string $kind, array $data): void
    {
        $needs = match ($kind) {
            /*
             * CC-তে দায়ের খাত লাগে **না** — টাকাটা ব্যাংক হিসাবে চলে,
             * তাই ঐ হিসাবটাই বাধ্যতামূলক। ⓘ স্টক ও মার্জিন ছাড়া
             * ড্রয়িং পাওয়ার বের করা যায় না, আর ওটাই CC-র আসল সীমা।
             */
            BankFacility::CC => ['money_account_id', 'stock_value', 'margin_percent'],

            /* কিস্তির সংখ্যা ছাড়া মেয়াদি ঋণের কোনো সময়সূচি হয় না */
            BankFacility::TERM => ['liability_account_id', 'instalments'],

            /* মার্জিন ছাড়া এলসি খোলা যায় না — ব্যাংক নিজের ঝুঁকি ঢাকে */
            BankFacility::LTR => ['liability_account_id', 'margin_percent'],

            /* সম্পদ আজ, টাকা বছরের পর বছর — দুইটাই লাগে */
            BankFacility::LEASE => ['liability_account_id', 'down_payment', 'instalment_amount'],

            /* ⚠️ গ্যারান্টিতে দায়ের খাত **চাওয়া হয় না** — ওটা দায় নয় */
            BankFacility::GUARANTEE => ['margin_percent'],

            default => [],
        };

        $missing = [];

        foreach ($needs as $field) {
            if (($data[$field] ?? null) === null || $data[$field] === '') {
                $missing[$field] = __('finance::validation.facility_needs', [
                    'kind' => __('finance::field.facility_'.$kind),
                ]);
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * যেগুলো নবায়ন করতে হবে — ৩০ দিনের ভিতরে।
     *
     * ⛔ এই তালিকাটা না থাকলে যা ঘটে তা নীরব: CC-র মঞ্জুরি ফুরিয়ে যায়,
     * ব্যাংক নতুন করে তুলতে দেয় না, আর টের পাওয়া যায় **একটা চেক ফেরত
     * এলে** — সাধারণত সরবরাহকারীর সামনে।
     *
     * @return Collection<int, BankFacility>
     */
    public function dueForRenewal(): Collection
    {
        return BankFacility::query()
            ->live()
            ->whereNotNull('renews_on')
            ->where('renews_on', '<=', now()->addDays(30)->toDateString())
            ->orderBy('renews_on')
            ->get();
    }

    /**
     * সুবিধাটা বন্ধ — শোধ হয়ে গেছে, বা ব্যাংক তুলে নিয়েছে।
     *
     * ⓘ সারিটা মোছা হয় না (নিয়ম ৫)। *"গত বছর আমাদের কত সীমা ছিল"* —
     * প্রশ্নটা বিরল, কিন্তু যেদিন ওঠে সেদিন উত্তরটা না থাকলে আর
     * কোনোদিন পাওয়া যায় না।
     */
    public function close(BankFacility $facility, ?string $note = null): void
    {
        $facility->forceFill([
            'status' => DocumentStatus::CLOSED,
            'closed_on' => now()->toDateString(),
            'note' => $note ?: $facility->note,
        ])->save();
    }
}
