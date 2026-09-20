<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use App\Core\Services\PartyRegistry;
use App\Core\Contracts\KnowsWhereAPersonsMoneyBelongs;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\MoneyCategory;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Services\PersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ভাউচারের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * দুই আকারের ফর্ম এক ক্লাসে: সহজ ফর্মে (আদায়, পরিশোধ, খরচ, কন্ট্রা)
 * দুইটা খাত ও একটা অঙ্ক; জাবেদায় যত খুশি সারি। আলাদা দুইটা ক্লাস করলে
 * তারিখ, বিবরণ ও চেকের নিয়মগুলো দুইবার লিখতে হত।
 */
class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isJournal(): bool
    {
        return $this->input('type') === Voucher::JOURNAL;
    }

    /**
     * পর্দার একটামাত্র "পক্ষ" ঘরটাকে ধরন ও নামে ভাগ করা।
     *
     * ── কেন পর্দায় একটা ঘর, অথচ খতিয়ানে দুইটা কলাম ────────────────
     * দুইটা আলাদা ঘর দিলে একটা ভরে অন্যটা খালি রাখা যেত, আর খতিয়ানে
     * একটা আধা-পক্ষ বসত — যাকে বকেয়ার রিপোর্ট কোনোদিন খুঁজে পেত না।
     * একটা তালিকা থেকে বাছলে ওই ভুলটা করাই যায় না।
     *
     * ── কেন এখানে, কন্ট্রোলারে নয় ──────────────────────────────────
     * কন্ট্রোলার জাবেদার সারিগুলো কাঁচা ইনপুট থেকে নেয়। এখানে ভাগ
     * করলে যাচাই ও সংরক্ষণ **দুইটাই** একই ভাগ করা মান দেখে, তাই
     * মাঝখানে ফাঁক থাকে না।
     */
    protected function prepareForValidation(): void
    {
        /*
         * হেডারের পক্ষ — সহজ ফর্মের `party` ঘরটা।
         *
         * ⓘ সারির পক্ষ নিচে ভাঙা হয়, একই `type:id` নিয়মে। হেডারেরটা
         * এখানে, কারণ সহজ ফর্ম সারি পাঠায় না — সে দুইটা খাত ও একটা
         * অঙ্ক পাঠায়, আর সারি দুইটা [[VoucherService::twoLineEntry]]
         * বানিয়ে দেয়।
         *
         * ⭐ হেডারে বসালেই যথেষ্ট: [[VoucherService]] সারিতে পক্ষ না
         * পেলে **হেডার থেকে নেয়** — তাই খতিয়ানের দুইটা সারিতেই
         * পক্ষটা পৌঁছায়, আর পক্ষের খতিয়ান ভরে ওঠে।
         */
        /*
         * ⭐ নোটের হিসাব — খালি ঘরগুলো ছেঁটে ফেলা, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ পর্দায় দশটা ঘর (১০০০ … ১), আর মানুষ সাধারণত দুই-তিনটা ভরেন।
         * ⛔ না ছাঁটলে বাকি সাতটা `''` হয়ে JSON-এ বসত, আর খতিয়ানে
         * `{"1000":null,"500":null,…}` জমত — *"নোট গোনা হয়নি"* আর
         * *"নোট গুনে শূন্য পাওয়া গেছে"* তখন আর আলাদা করা যেত না।
         *
         * ⚠️ একটাও না ভরলে ঘরটা `null` থাকে, খালি অ্যারে `[]` নয়:
         * খালি অ্যারেও একটা JSON, আর ওটা *"গোনা হয়েছে"* বলে দাবি করত।
         */
        $counts = $this->input('note_counts');

        if (is_array($counts)) {
            $counts = array_filter(
                $counts,
                static fn ($v) => $v !== null && $v !== '' && (int) $v > 0,
            );

            $this->merge(['note_counts' => $counts === [] ? null : $counts]);
        }

        $picked = trim((string) $this->input('party', ''));

        if ($picked !== '' && str_contains($picked, ':')) {
            [$partyType, $partyId] = explode(':', $picked, 2);

            $this->merge([
                'party_type' => trim($partyType),
                'party_id' => (int) $partyId,
            ]);
        }

        /*
         * ⭐ তালিকায় না থাকা পক্ষ — হাতে লেখা নাম, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ মালিকের নির্দেশ ───────────────────────────────────────
         * *"ডিপোজিটরের নাম / প্রাপকের নাম হাতে লিখতে পারতে হবে।"*
         * ⚠️ এতদিন ঘরটা কেবল ড্রপডাউন ছিল, তাই কাউন্টারে একজন অচেনা
         * লোক টাকা দিয়ে গেলে রসিদই কাটা যেত না।
         *
         * ── ⭐ কেন এখানে, যাচাইয়ের আগে ──────────────────────────────
         * সারিটা এখানেই বসে যায়, তাই নিচের নিয়মগুলোর কাছে `party_id`
         * একটা **আসল আইডি** হয়ে পৌঁছায়। ⛔ তাই ভ্যালিডেশন শিথিল
         * করতে হয়নি — হাতে লেখা নাম আর কড়া নিয়ম দুইটাই একসাথে।
         *
         * ⓘ নামটা বসে `mdm_people`-এ, অর্থাৎ **পরের বার তালিকাতেই**
         * পাওয়া যাবে ([[PersonResolver]])। ⚠️ পঞ্চম একটা "অন্যান্য"
         * ধরন বানালে নামটা কোথাও থাকত না, আর একই মানুষ তিন বানানে
         * তিনজন হয়ে যেতেন।
         */
        $typed = trim((string) $this->input('party_new', ''));

        if ($typed !== '' && (int) $this->input('party_id', 0) <= 0) {
            $fields = [
                'person_new' => $typed,
                'person_mobile' => (string) $this->input('party_mobile', ''),
            ];

            $personId = app(PersonResolver::class)->resolve($fields);

            if ($personId !== null) {
                $this->merge([
                    'party_type' => 'person',
                    'party_id' => $personId,
                ]);
            }
        }

        /*
         * ⛔ শ্রেণি আগে, পক্ষ পরে — ১৯ সেপ্টেম্বর ২০২৬, মালিকের ২৫ লাখ থেকে।
         *
         * ── কী ঘটেছিল ───────────────────────────────────────────────────
         * মালিক ব্যাংকে ২৫,০০,০০০ মূলধন ঢোকালেন। ব্যাংক ঠিকই ডেবিট হলো,
         * কিন্তু ক্রেডিট গেল **১১১০ Accounts Receivable**-এ — মূলধনে নয়।
         * ⓘ খাতার মোট সম্পদ দাঁড়াল ৪,৬৪৭ টাকা, অথচ ব্যাংকে ২৫ লাখ।
         *
         * ── ⛔ কেন ─────────────────────────────────────────────────────
         * এখানে আগে লেখা ছিল *"পক্ষ আগে, শ্রেণি পরে… রসিদে পক্ষটাই বেশি
         * নির্দিষ্ট"*। ⚠️ মূলধনের বেলায় ঠিক উল্টো: **কে** দিলেন (মালিক)
         * খাত বলে না — **কী** টাকা (মূলধন) বলে। আর পক্ষ আগে বসায় শ্রেণিটা
         * — ব্যবহারকারীর **স্পষ্ট বাছাই** — `from_account` ভরা দেখে নীরবে
         * ফিরে যেত। মালিক "মূলধন" বেছে থাকলেও সেটা মুছে যেত, কোনো বার্তা
         * ছাড়া।
         *
         * ⭐ এখন স্পষ্ট বাছাই আগে জেতে, পক্ষ কেবল তখনই ভরে যখন আর কিছু
         * বলা নেই — আর তাও কেবল যেখানে পক্ষের ধরন খাতটাকে সত্যিই বলে দেয়
         * ([[fillAccountFromParty()]])।
         */
        $this->fillAccountFromCategory();
        $this->fillAccountFromParty();

        $lines = (array) $this->input('lines', []);

        if ($lines === []) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line) || ! array_key_exists('party', $line)) {
                continue;
            }

            $picked = trim((string) $line['party']);
            unset($lines[$index]['party']);

            if ($picked === '' || ! str_contains($picked, ':')) {
                continue;
            }

            [$type, $id] = explode(':', $picked, 2);

            $lines[$index]['party_type'] = trim($type);
            $lines[$index]['party_id'] = (int) $id;
        }

        $this->merge(['lines' => $lines]);
    }

    /**
     * শ্রেণি বাছা হয়েছে অথচ খাত খালি — খাতটা শ্রেণি থেকেই বসিয়ে দাও।
     *
     * ── ⭐ কেন এটা সার্ভারে, শুধু ব্রাউজারে নয় ──────────────────────
     * ফর্মে Alpine শ্রেণি বাছলেই খাতের ড্রপডাউনটা ভরে দেয়, তাই
     * স্বাভাবিক পথে এই মেথডের কিছু করার থাকে না।
     *
     * ⛔ কিন্তু ব্রাউজারের ভরাট একটা **সুবিধা**, পাহারা নয়। JS বন্ধ
     * থাকলে, পুরনো ফোনে, বা API থেকে সরাসরি পাঠালে খাতটা খালি আসত আর
     * ব্যবহারকারী দেখতেন *"খাত বাছুন"* — অথচ তিনি শ্রেণি বেছেই দিয়েছেন
     * আর সেটাই খাতের উত্তর। দুই জায়গায় একই নিয়ম নয়; নিয়মটা এখানে,
     * আর ব্রাউজার কেবল আগেভাগে দেখিয়ে দেয়।
     *
     * ⓘ ব্যবহারকারী নিজে খাত বাছলে সেটাই থাকে — শ্রেণি তার উপর দিয়ে
     * যায় না। শ্রেণি **ফাঁকা ঘর ভরে**, বাছাই মোছে না।
     */
    /**
     * ⭐ রসিদ ও পরিশোধে অন্য পাশটা পক্ষ থেকেই আসে — ১৬ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন এটা লাগল ─────────────────────────────────────────────
     * নমুনার রসিদ পর্দায় "কার কাছ থেকে" নামে কোনো ঘর **নেই** — উপরে
     * ডিপোজিটর বাছা হয়েই যায়, আর সেটাই বলে দেয় টাকা কার কাছ থেকে।
     * ⚠️ দুইটা ঘর রাখলে একজন গ্রাহক বাছতেন আর খাত বাছতেন অন্য কারও,
     * আর তখন খতিয়ানে কোনটা বসত তা বলা যেত না।
     *
     * ⛔ কিন্তু খাতার দুই পাশ লাগেই — `from_account_id` ছাড়া দাখিলা
     * অসম্পূর্ণ, আর যাচাই সেটা `required` ধরে। ⓘ তাই ঘরটা পর্দা থেকে
     * সরানোর সাথে সাথেই এই পূরণটা বসাতে হয়েছে, নাহলে **প্রতিটা রসিদ
     * জমা দিতে গিয়ে আটকাত**।
     *
     * ── ⓘ কোন খাত ─────────────────────────────────────────────────
     * গ্রাহক টাকা দিলে তাঁর দেনা কমে, তাই ক্রেডিট যায় **প্রাপ্য
     * হিসাবে** (১১১০)। সরবরাহকারীকে টাকা দিলে আমাদের দেনা কমে, তাই
     * ডেবিট যায় **প্রদেয় হিসাবে** (২১১০)।
     *
     * ⚠️ পক্ষ না বাছলে কিছুই করা হয় না — তখন পুরনো নিয়মেই শ্রেণি
     * থেকে ভরে, আর সেটাও না পেলে যাচাই সৎভাবে আটকায়।
     */
    private function fillAccountFromParty(): void
    {
        if (trim((string) $this->input('from_account_id', '')) !== '') {
            return;
        }

        $type = (string) $this->input('party_type', '');
        $partyId = (int) $this->input('party_id', 0);

        if ($type === '' || $partyId <= 0) {
            return;
        }

        /*
         * ⛔ পক্ষের **ধরন** মিললে তবেই — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে এখানে কেবল ভাউচারের ধরন দেখা হত: রসিদ মানেই প্রাপ্য,
         * পরিশোধ মানেই প্রদেয় — **পক্ষ যে-ই হোক**। ⛔ ফলে মালিক (`person`)
         * মূলধন দিলেও সেটা গ্রাহকের বাকি আদায় হয়ে ১১১০-এ বসত, আর খাতায়
         * ২৫ লাখের মূলধন "−২৫ লাখ প্রাপ্য" হয়ে দেখা দিল।
         *
         * ⭐ প্রাপ্য কেবল গ্রাহকের — তিনি আমাদের কাছে টাকা ধারেন। প্রদেয়
         * কেবল সরবরাহকারীর — আমরা তাঁর কাছে ধারি। ⓘ বাকি সব জোড়া
         * (মালিক, ঋণদাতা, বাড়িওয়ালা, কর্মী, বা উল্টো দিকে ফেরত) **এক খাত
         * দিয়ে বলা যায় না**: মালিকের টাকা মূলধন, ঋণদাতার টাকা দায়,
         * কর্মীর ফেরত অগ্রিম। ⚠️ তাই ওখানে আন্দাজ নয় — ঘরটা ফাঁকা থাকে,
         * আর যাচাই সৎভাবে বলে "খাতটা বাছুন"। একটা থামা ভুল খাতে চুপচাপ
         * বসার চেয়ে ভালো: প্রথমটা ব্যবহারকারী দেখেন, দ্বিতীয়টা মাস শেষে
         * হিসাবরক্ষক খোঁজেন।
         *
         * ⓘ কাউন্টারের সাধারণ আদায় (গ্রাহক, শ্রেণি ছাড়া) আগের মতোই
         * প্রাপ্য পায় — ঐ পথটা একটুও বদলায়নি।
         */
        $code = match ([(string) $this->input('type'), $type]) {
            [Voucher::RECEIPT, 'customer'] => StandardChart::RECEIVABLE,
            [Voucher::PAYMENT, 'supplier'] => StandardChart::PAYABLE_GROUP,
            default => null,
        };

        /*
         * ⭐ যিনি আগে মূলধন দিয়েছেন, তাঁর রসিদ মূলধনে — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিক: *"capital theke asle eta auto boslei to valo hoy"*। পর্দা
         * নিজেই ঘরটায় 3100 বসায় ([[party-fields]]); এটা তার সার্ভারের জোড়া,
         * যাতে ঘর খালি এলেও (JS বন্ধ, মোবাইল) টাকা AR-এ বা কোথাও না হারায়।
         * ⚠️ কেবল তাঁদের জন্য যাঁদের মূলধনের রেকর্ড আছে — একজন অচেনা ব্যক্তির
         * টাকা আন্দাজে মূলধন বানালে ঠিক সেই ভুলটাই ফিরত যেটা 5e7d508c সারাল।
         */
        if ($code === null && (string) $this->input('type') === Voucher::RECEIPT && $type === 'person') {
            /*
             * ⭐ চুক্তিটা জিজ্ঞেস করা হয়, মডিউলটা নয় — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ আগে এখানে সরাসরি `Finance\Models\CapitalEntry` ডাকা হত,
             * আর তাতে তীরটা উল্টো ছিল ([[KnowsWhereAPersonsMoneyBelongs]])।
             * ⓘ কেউ চুক্তিটা না মেটালে কোরের "জানি না" বাঁধনটা চলে, আর
             * উত্তর `null` — তখন নিচের সাধারণ নিয়মই খাটে।
             */
            $code = app(KnowsWhereAPersonsMoneyBelongs::class)->accountCodeFor((int) $partyId);
        }

        if ($code === null) {
            return;
        }

        $account = Account::query()
            ->where('company_id', CompanyContext::id())
            ->where('code', $code)
            ->value('id');

        if ($account !== null) {
            $this->merge(['from_account_id' => $account]);
        }
    }

    private function fillAccountFromCategory(): void
    {
        if (trim((string) $this->input('from_account_id', '')) !== '') {
            return;
        }

        /*
         * উপ-শ্রেণি আগে — নির্দিষ্টটাই জেতে।
         *
         * ⓘ [[MoneyCategory::resolvedAccountId()]] নিজেই মায়ের খাতে
         * ফিরে যায়, তাই এখানে দুইটা ধাপ লাগে না — কেবল কোন সারি ধরে
         * জিজ্ঞেস করব সেটা ঠিক করি।
         */
        $id = (int) ($this->input('money_subcategory_id') ?: $this->input('money_category_id'));

        if ($id <= 0) {
            return;
        }

        $account = MoneyCategory::query()->with('parent')->find($id)?->resolvedAccountId();

        if ($account !== null) {
            $this->merge(['from_account_id' => $account]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::in(Voucher::TYPES)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * ⛔ শাখাটা এই কোম্পানিরই — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ আগে `['nullable','integer']` ছিল, অর্থাৎ **যেকোনো
             * সংখ্যা**। ⓘ `BelongsToCompany` কেবল `company_id` বসায়,
             * `branch_id` নয় — তাই পাঠানো মানটা হুবহু বসে যেত।
             *
             * ⛔ ভাউচার মানে **টাকা**। অন্য কোম্পানির শাখার আইডি বসালে
             * সারিটা নিজের কোম্পানিতেই থাকত (পড়া ছাঁকা), কিন্তু ওই
             * শাখার হিসাবে কোনোদিন আসত না — আর শাখার মিলটা প্রতি মাসে
             * ঠিক ওই অঙ্কটা কম দেখাত, কারণ ছাড়াই।
             */
            'branch_id' => ['nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', CompanyContext::id())],

            /*
             * ⓘ ঘর দুইটা নিয়মে আছে যাতে ভুল হলে বার্তা দেখানো যায়।
             * ⛔ নিয়মে না থাকলে `validated()` ওদের ফেলে দিত, আর
             * `@error('party_new')` কোনোদিন কিছু দেখাত না।
             */
            'party_new' => ['nullable', 'string', 'max:120'],
            'party_mobile' => ['nullable', 'string', 'max:32'],

            'party_type' => ['nullable', 'string', 'max:32'],
            'party_id' => ['nullable', 'integer'],

            'instrument' => ['nullable', Rule::in(Voucher::INSTRUMENTS)],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'instrument_date' => ['nullable', 'date'],

            /*
             * ── টাকা চলাচলের ব্লকের ঘরগুলো, ১৫ সেপ্টেম্বর ২০২৬ ─────────
             *
             * ⛔ ঘরগুলো ১৪ তারিখে পর্দায় বসেছিল, কলামও হয়েছিল — কিন্তু
             * **এখানে নিয়ম না থাকায়** সেগুলো `validated()`-এ আসতই না।
             * ⚠️ তাই কন্ট্রোলার ওগুলো কোনোদিন দেখেনি, আর সেভও হয়নি।
             * দুই দিক থেকেই বন্ধ দরজা।
             */
            'carried_by' => ['nullable', 'integer',
                Rule::exists('users', 'id')],
            'moved_at' => ['nullable', 'date_format:H:i'],

            /*
             * নোটের গণনা — কোন নোট কয়টা।
             *
             * ⓘ চাবিগুলো [[CashCount::DENOMINATIONS]]-এর ভিতরেই থাকতে
             * হবে: বাইরের চাবি এলে যোগফল আর নোটের হিসাব মিলত না, আর
             * গরমিলটা ধরা পড়ত কেবল ক্যাশ মেলানোর দিন।
             */
            'note_counts' => ['nullable', 'array'],
            'note_counts.*' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'wallet' => ['nullable', 'string', 'max:32'],
            'wallet_medium' => ['nullable', 'string', 'max:32'],
            'counterparty_phone' => ['nullable', 'string', 'max:20'],

            /*
             * ⭐ চার্জটা কে দিয়েছে — মালিকের নির্দেশ, ১৪ সেপ্টেম্বর।
             *
             * ⓘ দুইটাই বৈধ উত্তর, আর দুইটায় খতিয়ান আলাদা হয়: আমরা
             * দিলে চার্জ আমাদের খরচ, প্রেরক দিলে তাঁর খতিয়ানে পুরো মোট।
             */
            'charge_borne_by' => ['nullable', Rule::in(['us', 'them'])],
            'transfer_mode_id' => ['nullable', 'integer',
                Rule::exists('mdm_transfer_modes', 'id')],

            'from_branch' => ['nullable', 'string', 'max:120'],
            'from_account_name' => ['nullable', 'string', 'max:120'],
            'deposit_slip_no' => ['nullable', 'string', 'max:64'],
            'lands_on' => ['nullable', 'date'],

            /*
             * ⭐ উল্টো দাখিলার তারিখ — সাময়িক জাবেদা নিজেকে যেদিন উল্টাবে।
             *
             * ⛔ ভাউচারের তারিখের পরে হতেই হবে: আগের তারিখে উল্টালে
             * এন্ট্রিটা বসার আগেই মুছে যেত, আর খাতায় কেবল উল্টোটাই
             * থাকত — অর্থাৎ হিসাবটা উল্টো দিকে ভুল হত।
             */
            'reverse_on' => ['nullable', 'date', 'after:trx_date'],

            /*
             * ── খরচ ভাউচারের নিজের ঘর, ১৫ সেপ্টেম্বর ২০২৬ ─────────────
             * নমুনার চৌদ্দটা ঘরের যেগুলো ভাউচারের সারিতে বসে।
             */
            'cost_centre_id' => ['nullable', 'integer',
                Rule::exists('acc_cost_centers', 'id')->where('company_id', CompanyContext::id())],

            /*
             * ⛔ খাতটা এই কোম্পানির, আর **খরচের** খাত।
             *
             * ⚠️ কেবল `exists` দিলে যে কেউ ব্যাংকের আইডি পাঠিয়ে খরচটা
             * সম্পদের খাতে বসিয়ে দিতে পারত, আর মুনাফা মিথ্যা বেশি
             * দেখাত — কোনো ভুল বার্তা ছাড়াই।
             */
            'expense_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->where('type', Account::EXPENSE)
                    ->where('is_group', false)],

            'bill_no' => ['nullable', 'string', 'max:60'],

            /*
             * ⭐ কাকে দেওয়া হলো — ধরন তালিকা থেকে, নাম লেখা।
             *
             * ⛔ ধরনটা **এই কোম্পানির** হতে হবে, আর সরবরাহকারীর
             * দিকের হতে হবে — কেবল `exists` দিলে অন্য কোম্পানির বা
             * গ্রাহকের ধরন পাঠানো যেত, আর খরচটা ভুল দলে গুনা হত।
             */
            'payee_type_id' => ['nullable', 'integer',
                Rule::exists('mdm_party_types', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->whereIn('applies_to', ['supplier', 'both'])
                    ->whereNull('deleted_at')],

            'payee_name' => ['nullable', 'string', 'max:120'],
            'gross_amount' => ['nullable', 'numeric', 'min:0'],
            'ait_amount' => ['nullable', 'numeric', 'min:0'],
            'vds_amount' => ['nullable', 'numeric', 'min:0'],

            /*
             * ⭐ কোন চালানের জন্য — এক খরচ, একাধিক চালান।
             *
             * ⓘ মালিকের কথা: *"এক ট্রাকে একাধিক চালান এলে সবগুলোই
             * বাছুন"*। খালি থাকা বৈধ, আর তার মানে **পরোক্ষ খরচ**।
             */
            'bill_shares' => ['nullable', 'array'],
            'bill_shares.*.purchase_bill_id' => ['required_with:bill_shares', 'integer',
                Rule::exists('pur_bills', 'id')->where('company_id', CompanyContext::id())],
            'bill_shares.*.share_amount' => ['required_with:bill_shares', 'numeric', 'min:0'],
            'alloc_basis' => ['nullable', Rule::in(['qty', 'value', 'weight'])],
        ];

        if ($this->isJournal()) {
            return $rules + [
                'lines' => ['required', 'array', 'min:2'],
                /*
                 * ⛔ কোম্পানি যাচাই — ২১ সেপ্টেম্বর ২০২৬ (অডিট ঙ২)।
                 *
                 * ⚠️ আগে কেবল `integer` ছিল। ⓘ পোস্টিং ইঞ্জিন শেষমেশ
                 * ধরত, কিন্তু ততক্ষণে **খসড়াটা সেভ হয়ে গেছে** — অন্য
                 * কোম্পানির একটা খাত নিয়ে বসে আছে, আর ব্যবহারকারী
                 * পোস্ট করার দিন একটা অনুবাদহীন ৫০০ পান।
                 *
                 * ⭐ ছাঁচটা এই ফাইলেই আছে — `expense_account_id`।
                 */
                'lines.*.account_id' => ['nullable', 'integer',
                    Rule::exists('accounts', 'id')
                    ->where('company_id', CompanyContext::id())],
                'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.narration' => ['nullable', 'string', 'max:500'],

                /*
                 * সারি ধরে পক্ষ — এক ভাউচারে দুই পক্ষ বসানোর জন্য।
                 *
                 * ── কেন লাগে ─────────────────────────────────────────
                 * পরিবেশকের রোজকার ঘটনা: ডিলার টাকাটা কোম্পানিকে
                 * সরাসরি দিলেন। তখন এক এন্ট্রিতে **ডেবিট সরবরাহকারী,
                 * ক্রেডিট ডিলার** — দুইটা আলাদা পক্ষ, একই ভাউচারে।
                 * মাথার একটামাত্র পক্ষ দিয়ে ওটা লেখাই যেত না।
                 *
                 * ── কেন যাচাইটা এখানে ─────────────────────────────────
                 * `VoucherService` ও `VoucherLine` লাইনের পক্ষ আগে
                 * থেকেই বোঝে, আর কন্ট্রোলার জাবেদার সারিগুলো **কাঁচা
                 * ইনপুট** থেকে নেয় (`$request->input('lines')`)। অর্থাৎ
                 * ঘরটা পর্দায় না থাকলেও যে কেউ অনুরোধে
                 * `lines[0][party_type]=whatever` পাঠিয়ে খতিয়ানে এমন
                 * একটা পক্ষ বসিয়ে দিতে পারত যা কোনো রিপোর্ট চেনে না —
                 * আর বকেয়াটা তখন কোথাও দেখা যেত না। নিয়ম ৪: প্রতিটা
                 * ইনপুটে ভ্যালিডেশন।
                 */
                'lines.*.party_type' => ['nullable', 'string', 'max:32',
                    Rule::in(app(PartyRegistry::class)->types())],
                'lines.*.party_id' => ['nullable', 'integer'],
            ];
        }

        return $rules + [
            /* ⛔ কোম্পানি যাচাই — একই কারণ, একই দিন (অডিট ঙ২) */
            'from_account_id' => ['required', 'integer', 'different:to_account_id',
                Rule::exists('accounts', 'id')
                    ->where('company_id', CompanyContext::id())],
            'to_account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', CompanyContext::id())],
            'amount' => ['required', 'numeric', 'gt:0'],

            /*
             * পক্ষ — ঐচ্ছিক, `type:id` আকারে।
             *
             * ⓘ বাধ্যতামূলক হওয়ার নিয়মটা এখানে নয়, `after()`-এ: সেটা
             * নির্ভর করে **কোন খাত বাছা হয়েছে** তার উপর, আর সেটা
             * একটা সাধারণ নিয়মে বলা যায় না।
             */
            'party' => ['nullable', 'string', 'max:64'],

            // ⓘ `prepareForValidation()` এগুলো বসায়; নিয়মে না থাকলে
            // `validated()` ওগুলো ফেলে দিত আর সেবায় পৌঁছাত না
            'party_type' => ['nullable', 'string', 'max:32',
                Rule::in(app(PartyRegistry::class)->types())],
            'party_id' => ['nullable', 'integer'],

            /*
             * মালিকের চাওয়া বাকি ঘরগুলো (১৪ সেপ্টেম্বর ২০২৬)।
             *
             * ⓘ "Collectable" এখানে নেই, আর থাকার কথাও নয় — ওটা
             * ব্যবহারকারী পাঠান না, পর্দা খতিয়ান থেকে দেখায়
             * ([[AccountsFacts::dueFrom()]])। নিয়মে রাখলে বোঝা যেত
             * সংখ্যাটা বাইরে থেকে আসে, আর একদিন কেউ সেটা পাঠাত।
             */
            'ref_date' => ['nullable', 'date'],

            /*
             * ⚠️ `exists` **দরকার**, কারণ ঘরটা একটা ড্রপডাউন হলেও
             * অনুরোধটা যেকোনো সংখ্যা বহন করতে পারে। না দিলে অন্য
             * কোম্পানির শ্রেণির আইডি পাঠিয়ে সেই খাতে টাকা বসানো যেত।
             *
             * ⓘ কোম্পানির ছাঁকনি আলাদা করে লেখা নেই — [[MoneyCategory]]
             * -এ [[BelongsToCompany]] গ্লোবাল স্কোপ আছে, কিন্তু
             * `Rule::exists` কাঁচা কোয়েরি বিল্ডারে চলে যেখানে স্কোপ
             * খাটে না। তাই শর্তটা হাতে বসানো।
             */
            'money_category_id' => ['nullable', 'integer',
                Rule::exists('acc_money_categories', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->whereNull('deleted_at')],
            'money_subcategory_id' => ['nullable', 'integer',
                Rule::exists('acc_money_categories', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->whereNull('deleted_at')],

            /*
             * যিনি দিলেন তাঁর ব্যাংক ও হিসাব নম্বর — মুক্ত লেখা, মালিকের
             * নিজের সিদ্ধান্ত। চেকে যা ছাপা আছে হুবহু তাই।
             *
             * ⚠️ আমাদের ব্যাংক নয় (`money_account_id`) — চেক ফেরত এলে
             * এই দুইটাই খোঁজার একমাত্র সূত্র।
             */
            'from_bank' => ['nullable', 'string', 'max:120'],
            'from_account_no' => ['nullable', 'string', 'max:64'],

            /*
             * ব্যাংক বা MFS যা কেটে রেখেছে।
             *
             * ⓘ `lt:amount` এখানে **নেই** ইচ্ছাকৃতভাবে — শর্তটা
             * [[VoucherService::withCharge()]]-এ, কারণ ওখানেই চার্জের
             * খাত বাছা হয় আর ওখানেই জানা যায় খাতটা ব্যাংক না MFS না
             * নগদ। দুই জায়গায় আধা-আধা নিয়ম রাখলে একদিন একটা বদলাত আর
             * অন্যটা থেকে যেত।
             */
            'charge_amount' => ['nullable', 'numeric', 'min:0'],

            /*
             * কোন নথির বিপরীতে — অর্থ মডিউল থেকে আসা রসিদের জন্য।
             *
             * ⚠️ ঘর দুইটা **একসাথে** আসতে হবে; একটা ছাড়া অন্যটা মানে
             * একটা আইডি যার কোনো ধরন নেই, বা একটা ধরন যার কোনো সারি
             * নেই — দুইটাই খতিয়ানে বসে থাকা আবর্জনা।
             */
            'against_type' => ['nullable', 'string', 'max:32', 'required_with:against_id'],
            'against_id' => ['nullable', 'integer', 'required_with:against_type'],
        ];
    }

    /**
     * জাবেদার সারিগুলো নিয়ে দুইটা কথা, যা এক-একটা সারি দেখে বলা যায় না।
     */
    public function after(): array
    {
        return [
            /*
             * বাকিতে খরচ হলে পক্ষ ছাড়া চলে না।
             *
             * ── কেন ─────────────────────────────────────────────────
             * ⛔ কারো কাছে **দেনা** হতে হলে "কার কাছে" জানতেই হবে।
             * পক্ষ ছাড়া লিখলে প্রদেয়ের ঘরে একটা টাকা বসে থাকত **যার
             * কোনো মালিক নেই**, আর *"কাকে কত দিতে হবে"* তালিকার যোগফল
             * স্থিতিপত্রের সাথে মিলত না।
             *
             * ⓘ নগদে খরচে পক্ষ ঐচ্ছিক — চা-নাস্তা বা রিকশাভাড়ায় "কাকে
             * দিলাম" লেখার দরকার নেই।
             *
             * ⚠️ নিয়মটা `rules()`-এ বসানো যেত না: এটা নির্ভর করে কোন
             * খাত বাছা হয়েছে তার উপর, আর খাতটা প্রদেয় কিনা তা জানতে
             * ডাটাবেসে দেখতে হয়।
             */
            function (Validator $validator): void {
                if ($this->isJournal() || blank($this->input('from_account_id'))) {
                    return;
                }

                /*
                 * ⚠️ দুইটা আকৃতিই মানতে হবে, আর কারণটা ১৪ সেপ্টেম্বর
                 * ২০২৬-এ তৈরি হয়েছে।
                 *
                 * আগে সহজ ফর্ম পক্ষটা পাঠাত `party` ঘরে `type:id`
                 * আকারে। মালিকের চাওয়া "Received From Type" বসানোর পর
                 * ঘরটা **দুইটা হয়েছে** — `party_type` আর `party_id`,
                 * আর `party` ঘরটা আর নেই।
                 *
                 * ⛔ কেবল `filled('party')` দেখলে নিয়মটা তখন **উল্টো
                 * দিকে ভাঙত**: ব্যবহারকারী পক্ষ বেছে দিয়েছেন, তবু
                 * "পক্ষ ছাড়া বাকিতে খরচ চলে না" বলে আটকে দিত — অর্থাৎ
                 * একটা সঠিক ভাউচার সেভই করা যেত না।
                 *
                 * ⓘ `party` ঘরটা তবু দেখা হয়, কারণ জাবেদার পথ ও
                 * পুরনো API অনুরোধ এখনো ওটাই পাঠায়।
                 */
                if (filled($this->input('party'))) {
                    return;
                }

                if (filled($this->input('party_type')) && filled($this->input('party_id'))) {
                    return;
                }

                /*
                 * ⛔ ── এখানে ভুল ধ্রুবক মানে নিয়মটা নিভে যাওয়া ──────────
                 *
                 * **কোড ধরে একটা খাত খুঁজলে `PAYABLE`; গোটা পরিবার চাইলে
                 * `PAYABLE_GROUP`।** ⓘ যে পোস্ট করে সে একটা ঘর চায়; যে
                 * তালিকা বা যাচাই করে সে পরিবার চায়।
                 *
                 * ⚠️ এখানে `PAYABLE` লেখা ছিল, আর প্রদেয় চার ঘরে ভাগ
                 * হওয়ার দিন সেটা `2111`-এ নেমে গেল। ফল **পর্দা সরু হওয়া
                 * নয় — একটা নিয়ম নিভে যাওয়া**: পরিবহন (২১১৬) বা
                 * হাম্মালির (২১১৭) দেনায় ক্রেডিট করলে যাচাইটা আর চলত না,
                 * আর পক্ষ ছাড়াই বাকিতে খরচ সেভ হয়ে যেত।
                 *
                 * ⛔ আর সরু পর্দা কেউ দেখে অভিযোগ করেন; **নিভে যাওয়া
                 * যাচাই কেউ দেখেন না** — ছয় মাস পরে প্রদেয়ের তালিকায়
                 * মালিকহীন টাকা দেখে লোকে ডাটা এন্ট্রির দোষ ভাবতেন।
                 *
                 * ⓘ নিচের মন্তব্যটা পড়ুন: **এই ফাঁদে এটা দ্বিতীয়বার** —
                 * আর প্রথমবারও কোনো টেস্ট ধরেনি।
                 */
                $payable = StandardChart::find(StandardChart::PAYABLE_GROUP);

                if ($payable === null) {
                    return;
                }

                /*
                 * ⚠️ **পুরো বংশ, কেবল ২১১০ নয়।**
                 *
                 * `2110` একটা গ্রুপ — ব্যবহারকারী বাছেন তার সন্তানদের
                 * একটাকে (পরিবহন · হাম্মালি · সরবরাহকারী · সেবা)। কেবল
                 * বাবার id মেলালে **নিয়মটা কোনোদিন চলতই না**, আর পক্ষ
                 * ছাড়াই বাকিতে খরচ সেভ হয়ে যেত।
                 *
                 * ⓘ ঠিক এটাই ঘটেছিল প্রথমবার — ব্রাউজারে সেভ করে দেখা
                 * গেছে, টেস্টে নয়।
                 */
                $payableIds = $payable->selfAndDescendants()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (in_array((int) $this->input('from_account_id'), $payableIds, true)) {
                    $validator->errors()->add('party', __('accounts::validation.credit_needs_a_party'));
                }
            },

            function (Validator $validator): void {
                if (! $this->isJournal()) {
                    return;
                }

                $debit = '0';
                $credit = '0';
                $filled = 0;

                foreach ((array) $this->input('lines', []) as $index => $line) {
                    $d = (string) ($line['debit'] ?? 0);
                    $c = (string) ($line['credit'] ?? 0);

                    $d = $d === '' ? '0' : $d;
                    $c = $c === '' ? '0' : $c;

                    $hasMoney = bccomp($d, '0', 4) > 0 || bccomp($c, '0', 4) > 0;

                    if ($hasMoney && blank($line['account_id'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.account_id",
                            __('accounts::validation.line_needs_account'));
                    }

                    // একই সারিতে দুই দিকেই টাকা মানে আসলে দুইটা সারি —
                    // মিলিয়ে লিখলে লেজারে ওই খাতের প্রকৃত চলাচল হারায়
                    if (bccomp($d, '0', 4) > 0 && bccomp($c, '0', 4) > 0) {
                        $validator->errors()->add("lines.{$index}.debit",
                            __('accounts::validation.line_both_sides'));
                    }

                    $this->checkParty($validator, $index, $line);

                    if ($hasMoney) {
                        $filled++;
                        $debit = bcadd($debit, $d, 4);
                        $credit = bcadd($credit, $c, 4);
                    }
                }

                if ($filled < 2) {
                    $validator->errors()->add('lines', __('accounts::validation.journal_needs_two_lines'));

                    return;
                }

                if (bccomp($debit, $credit, 4) !== 0) {
                    $validator->errors()->add('lines', __('accounts::validation.not_balanced', [
                        'debit' => Money::format($debit),
                        'credit' => Money::format($credit),
                    ]));
                }
            },
        ];
    }

    /**
     * সারির পক্ষটা সত্যিই আছে কি না, আর অর্ধেক লেখা নয় তো।
     *
     * ── কেন "অর্ধেক" আলাদা করে দেখা হয় ──────────────────────────────
     * ধরন দিয়ে নাম না দিলে (বা উল্টোটা) খতিয়ানে একটা আধা-পক্ষ বসত।
     * বকেয়ার রিপোর্ট `party_type` **আর** `party_id` দুইটা মিলিয়ে
     * খোঁজে, তাই ওই সারিটা কোনো ডিলারের নামের নিচে আসত না — অথচ
     * ভাউচারটা দেখতে ঠিকই থাকত, আর টাকাটা কার সেটা আর জানা যেত না।
     *
     * @param  array<string, mixed>  $line
     */
    private function checkParty(Validator $validator, int|string $index, array $line): void
    {
        $type = trim((string) ($line['party_type'] ?? ''));
        $id = (int) ($line['party_id'] ?? 0);

        if ($type === '' && $id === 0) {
            return;
        }

        if ($type === '' || $id === 0) {
            $validator->errors()->add("lines.{$index}.party_id",
                __('accounts::validation.party_half_written'));

            return;
        }

        if (! app(PartyRegistry::class)->exists($type, $id)) {
            $validator->errors()->add("lines.{$index}.party_id",
                __('accounts::validation.party_unknown'));
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'trx_date' => __('accounts::field.date'),
            'from_account_id' => __('accounts::field.from_account'),
            'to_account_id' => __('accounts::field.to_account'),
            'amount' => __('accounts::field.amount'),
            'narration' => __('core.table.narration'),
        ];
    }
}
