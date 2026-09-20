<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
     * আগে থেকে চলতে থাকা ঋণের খোলা ব্যালেন্সের উৎস।
     *
     * ⓘ সুবিধার নিজের উৎস (`bank_facility`) থেকে আলাদা, আর সেটা
     * ইচ্ছাকৃত: একই চাবি হলে পরে সুবিধাটা নিয়ে আর কোনো দাখিলা বসা
     * যেত না ([[FixedAsset::disposalSourceType]]-এ ঠিক এই ভুলটা ধরা পড়েছে)।
     */
    public const OPENING_SOURCE = 'bank_facility_opening';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly InstitutionService $institutions,
        private readonly PostingEngine $posting,
    ) {}

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

        /*
         * ⭐ ব্যাংকটা এখন তালিকা থেকে ([[Institution]]), আর নতুন নাম
         * ফর্মেই যোগ করা যায় ([[InstitutionService::resolve]])।
         *
         * ⓘ পুরনো `bank` ঘরটা তবু লেখা হয় — প্রতিষ্ঠানের নামটাই। ⚠️ ওটা
         * বাদ দিলে পুরনো সারি আর নতুন সারি দুই রকম হত, আর যে রিপোর্ট
         * ঐ ঘর পড়ে সেগুলো নতুন সারিতে ফাঁকা দেখাত।
         */
        $institutionId = $this->institutions->resolve($data, Institution::BANK);

        return BankFacility::query()->create([
            /*
             * ⭐ নথি নম্বর — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ হলো, আর এটা আমার
             * নিজের ফাঁক।
             *
             * ⛔ `drillDocumentNo()` লিখেছিলাম `document_no ?? sanction_no
             * ?? id` — অর্থাৎ ফলব্যাকটা কাজ করত, তাই **কিছুই ভাঙত না**,
             * আর প্রথম ঘরটা যে কোনোদিন ভরত না সেটা ধরাও পড়ত না।
             *
             * ⚠️ ফল: মঞ্জুরি নম্বর না লিখলে সুবিধাটার পরিচয় হত স্রেফ
             * একটা আইডি, আর ড্রিলের তালিকায় সেটা দেখতে ভুলের মতো লাগত।
             */
            'document_no' => $this->numbers->next('BFC'),
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'kind' => $kind,

            'institution_id' => $institutionId,
            'bank' => $this->institutions->nameOf($institutionId) ?? ($data['bank'] ?? ''),
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

            'opening_instalments_paid' => ($data['already_running'] ?? false)
                ? ($data['instalments_paid'] ?? null)
                : null,

            'early_charge' => $data['early_charge'] ?? null,
            'early_charge_kind' => $data['early_charge_kind'] ?? null,
            'early_charge_basis' => $data['early_charge_basis'] ?? null,

            'stock_value' => $data['stock_value'] ?? null,
            'margin_percent' => $data['margin_percent'] ?? null,

            /*
             * ⓘ স্টকের অঙ্কটা কবেকার — খালি থাকলে খালিই থাকে।
             * ⛔ `now()` বসানো হয় না: ওটা একটা **অনুমানকে তারিখের
             * ছদ্মবেশ** দিত, আর তখন তিন মাসের পুরনো হিসাবও আজকের
             * বলে চালিয়ে যেত।
             */
            'last_statement_on' => ($data['last_statement_on'] ?? '') ?: null,

            /*
             * ⓘ খোলার দিনের ব্যবহৃত অঙ্ক — খালি এলে শূন্য, আর শূন্যই
             * সঠিক: নতুন সুবিধায় কিছু তোলা হয়নি।
             */
            'opening_drawn' => $data['opening_drawn'] ?? 0,
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
     * আগে থেকেই চলতে থাকা ঋণ — আজকের বকেয়া খাতায় তোলা।
     *
     * ── ⭐ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ──────────────
     * নতুন ঋণে টাকা আসে রসিদ ভাউচারে। ⚠️ কিন্তু যে ঋণ বছর আগে
     * নেওয়া, তার টাকা তখনই ব্যাংকে এসেছিল — আজ আবার বসালে ব্যাংকের
     * জেরটাই মিথ্যা হয়। ⛔ তাই **টাকার খাত ছোঁয়া হয় না**।
     *
     * ── ⓘ তবে কোথায় বসে ────────────────────────────────
     * দায়ের খাতে ক্রেডিট (ঋণটা আছে), আর বিপরীতে সঞ্চিত মুনাফায় ডেবিট
     * — খোলা ব্যালেন্সের নিয়মটাই ([[OpeningBalanceService]])। ⓘ আগের
     * ব্যবসার ফল নতুন খাতায় তোলা হচ্ছে, এই বছরের খরচ নয়।
     *
     * ⛔ দুইবার বসার পথ নেই: উৎসের নামে সুবিধার আইডি আছে, আর
     * পোস্টিং ইঞ্জিন একই উৎসে দ্বিতীয়বার বসতে দেয় না।
     */
    public function openingFor(BankFacility $facility, string $outstanding): void
    {
        if (bccomp($outstanding, '0', 4) <= 0) {
            return;
        }

        /*
         * ⚠️ CC ও গ্যারান্টিতে দায়ের আলাদা খাত নেই — CC-র বকেয়া তো
         * ব্যাংক হিসাবের ঋণাত্মক জেরই। ⛔ সেটা এখান থেকে বসালে ব্যাংকের
         * জের দুইবার গোনা হত — একবার খাতের নিজের খোলা ব্যালেন্সে,
         * আরেকবার এখানে। ⓘ তাই স্পষ্ট করে না বলা হয়।
         */
        if ($facility->liability_account_id === null) {
            throw ValidationException::withMessages([
                'opening_drawn' => __('finance::validation.opening_needs_a_liability_account'),
            ]);
        }

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($equity === null) {
            throw ValidationException::withMessages([
                'opening_drawn' => __('finance::validation.opening_needs_the_chart'),
            ]);
        }

        $this->posting->post(
            sourceType: self::OPENING_SOURCE,
            sourceId: (int) $facility->id,
            trxDate: $facility->sanctioned_on->toDateString(),
            lines: [
                ['account_id' => (int) $equity->id, 'debit' => $outstanding],
                ['account_id' => (int) $facility->liability_account_id, 'credit' => $outstanding],
            ],
            documentNo: $facility->document_no,
        );
    }

    /**
     * কয়টা কিস্তি দেওয়া হলো, আর কয়টা বাকি — খাতা থেকে গোনা।
     *
     * ── ⛔ কোনো গুনতি সংরক্ষণ করা হয় না, ২০ সেপ্টেম্বর ২০২৬ ─────
     * মালিকের নিয়ম: যা ওই ঋণের খাতে শোধ হয়েছে, তাই শোধ।
     * ⚠️ সংরক্ষিত গুনতি আর খাতা একদিন আলাদা কথা বলত, আর তখন কোনটা
     * সত্যি সেটা কেউ বলতে পারত না।
     *
     * ⓘ শুরুর দিনের গুনতিটা যোগ হয়, কারণ ওই কিস্তিগুলো ব্যবস্থার
     * বাইরে দেওয়া হয়েছিল — খাতায় ওদের খুঁজে পাওয়ার কোনো পথই নেই।
     *
     * @return array{paid: int, left: int, repaid: string}
     */
    public function instalmentStanding(BankFacility $facility): array
    {
        $opening = (int) ($facility->opening_instalments_paid ?? 0);
        $each = (string) ($facility->instalment_amount ?? '0');
        $count = (int) ($facility->instalments ?? 0);

        $repaid = '0';

        if ($facility->liability_account_id !== null) {
            /*
             * ⓘ দায়ের খাতে ডেবিট মানে দায় কমা — অর্থাৎ শোধ।
             * ⚠️ খোলা ব্যালেন্সের সারিটা ক্রেডিট, তাই সে নিজেই এই
             * যোগফলে পড়ে না।
             */
            $repaid = (string) (DB::table('ledger_entries')
                ->where('company_id', CompanyContext::id())
                ->where('account_id', (int) $facility->liability_account_id)

                /*
                 * ⛔ খোলা ব্যালেন্সের সারিটা বাদ — সে দায় বসায়, শোধ করে না।
                 * ⚠️ না বাদ দিলে পুরনো ঋণে যোগফল ঋণাত্মক হয়ে যেত, আর
                 * পর্দা বলত একটা কিস্তিও দেওয়া হয়নি।
                 */
                ->where('source_type', '<>', self::OPENING_SOURCE)
                ->sum(DB::raw('debit - credit')) ?? '0');
        }

        if (bccomp($repaid, '0', 4) < 0) {
            $repaid = '0';
        }

        $fromLedger = bccomp($each, '0', 4) > 0
            ? (int) bcdiv($repaid, $each, 0)
            : 0;

        $paid = $opening + $fromLedger;

        // ⚠️ সংখ্যার বেশি শোধ হলেও বাকি ঋণাত্মক দেখানো হয় না
        $left = $count > 0 ? max(0, $count - $paid) : 0;

        return ['paid' => $paid, 'left' => $left, 'repaid' => $repaid];
    }

    /**
     * আজ সব শোধ করলে কত লাগবে — বকেয়া, চার্জ, আর মোট।
     *
     * ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ──────────────
     * *"majpothe setelment korle ze extra charge ase ta soho korbe"*।
     * ⓘ শতাংশ হলে বকেয়ার উপর, থোক হলে যা লেখা আছে তাই।
     *
     * ── ⚠️ সুদের উপর শতাংশ এখনো হিসাব হয় না ────────────────
     * ⛔ বাকি সুদ কত, সেটা বের করতে হলে বাকি কিস্তিগুলোর সুদাংশ
     * জানতে হয়, আর সেটা নির্ভর করে ব্যাংক flat না reducing হিসাব
     * করে তার উপর। ⓘ মালিকের উত্তর না আসা পর্যন্ত অনুমান করা হয়নি:
     * ভিত্তি `interest` লেখা থাকলে চার্জ শূন্য দেখায় আর কারণটা লেখা
     * থাকে — ভুল সংখ্যা দেখানোর চেয়ে শূন্য দেখানো ভালো।
     *
     * @return array{outstanding: string, charge: string, total: string, unknown: bool}
     */
    public function settlementToday(BankFacility $facility): array
    {
        $outstanding = $this->standing(collect([$facility]))[$facility->id]['used'] ?? '0';

        $amount = (string) ($facility->early_charge ?? '0');
        $kind = (string) ($facility->early_charge_kind ?? '');

        if (bccomp($amount, '0', 4) <= 0 || $kind === '') {
            return [
                'outstanding' => $outstanding,
                'charge' => '0.0000',
                'total' => $outstanding,
                'unknown' => false,
            ];
        }

        if ($kind === BankFacility::CHARGE_FLAT) {
            return [
                'outstanding' => $outstanding,
                'charge' => $amount,
                'total' => bcadd($outstanding, $amount, 4),
                'unknown' => false,
            ];
        }

        /*
         * ⭐ বাকি সুদের উপর শতাংশ — এখন হিসাব হয়, ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিকের ব্যাংক ক্ষয়িষ্ণু জেরে সুদ গোনে, তাই বাকি সুদ বলতে
         * বাকি কিস্তিগুলোর সুদাংশের যোগফল ([[LoanSchedule::interestLeft]])।
         * ⚠️ কয়টা দেওয়া হয়েছে সেটা খাতা থেকেই গোনা।
         */
        if ((string) $facility->early_charge_basis === BankFacility::ON_INTEREST) {
            $months = (int) ($facility->instalments ?? 0);

            if ($months < 1) {
                return [
                    'outstanding' => $outstanding,
                    'charge' => '0.0000',
                    'total' => $outstanding,
                    'unknown' => true,
                ];
            }

            $left = LoanSchedule::interestLeft(
                (string) $facility->limit_amount,
                (string) ($facility->interest_rate ?? '0'),
                $months,
                $this->instalmentStanding($facility)['paid'],
            );

            $charge = bcdiv(bcmul($left, $amount, 4), '100', 4);

            return [
                'outstanding' => $outstanding,
                'charge' => $charge,
                'total' => bcadd($outstanding, $charge, 4),
                'unknown' => false,
            ];
        }

        $charge = bcdiv(bcmul($outstanding, $amount, 4), '100', 4);

        return [
            'outstanding' => $outstanding,
            'charge' => $charge,
            'total' => bcadd($outstanding, $charge, 4),
            'unknown' => false,
        ];
    }

    /**
     * কিস্তির তালিকা — মাস, আসল, সুদ, জের।
     *
     * ⓘ মালিকের ছবির চারটা কলাম (২১ সেপ্টেম্বর ২০২৬)। ⭐ প্রথম মাসে
     * সুদ বেশি আসল কম, শেষ মাসে উল্টো, আর শেষ সারিতে জের ঠিক শূন্য।
     *
     * ⛔ কিস্তি নেই এমন সুবিধায় (CC, গ্যারান্টি) তালিকাটাই আসে না।
     *
     * @return array{rows: list<array<string, mixed>>, instalment: string,
     *     interest_total: string, paid_total: string}|null
     */
    public function schedule(BankFacility $facility): ?array
    {
        $months = (int) ($facility->instalments ?? 0);

        if ($months < 1 || bccomp((string) $facility->limit_amount, '0', 4) <= 0) {
            return null;
        }

        return LoanSchedule::build(
            (string) $facility->limit_amount,
            (string) ($facility->interest_rate ?? '0'),
            $months,
        );
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
     * কত তোলা হয়েছে, আর সীমার কতটা বাকি — খতিয়ান থেকে, দ্বিতীয় কপি নয়।
     *
     * ── ⭐ অর্থের মানচিত্র §১৪গ, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────
     * লাইনটার টীকাই বলে দিয়েছিল কীভাবে করতে হবে: *"খতিয়ানে থাকে, এখানে
     * দ্বিতীয় কপি রাখা হয়নি"*। ⛔ সুবিধার সারিতে একটা `drawn` কলাম বসালে
     * সেটা একদিন খতিয়ানের সাথে আলাদা হয়ে যেত, আর কোনটা সত্যি তা নিয়ে
     * প্রশ্ন উঠত — এই রিপোজিটরিতে ঐ ফাঁদ [[SalesInvoice::collectedAmount]]
     * নিয়ে একবার দেখা হয়েছে।
     *
     * ── ⚠️ দুই ধরনের সুবিধা, দুই জায়গায় দেনাটা বসে ───────────────────
     * · মেয়াদি · LTR · লিজ — নিজের **দায়ের খাত**, যা ক্রেডিটে বাড়ে।
     * · CC — আলাদা দায়ের খাত নেই ([[BankFacility::isBalanceSheetDebt()]]);
     *   দেনাটা **ব্যাংক হিসাবের ঋণাত্মক জের**, তাই চিহ্ন উল্টে নেওয়া হয়।
     * ⓘ গ্যারান্টিতে টাকা তোলাই হয় না, তাই ব্যবহৃত শূন্য — যতক্ষণ না
     * ব্যাংক ওটা নগদায়ন করে, আর তখন দাখিলাটা এমনিতেই খাতায় আসে।
     *
     * ⓘ `opening_drawn` যোগ হয়: পুরনো ব্যবস্থা থেকে তোলা টাকা খতিয়ানে নেই।
     * ⚠️ একটাই গ্রুপড কোয়েরি — সারিপ্রতি একটা করে কোয়েরি হলে তালিকাটা
     * সুবিধার সংখ্যার সাথে ধীর হত।
     *
     * @param  \Illuminate\Support\Collection<int, BankFacility>  $facilities
     * @return array<int, array{used: string, left: string}>
     */
    public function standing(\Illuminate\Support\Collection $facilities): array
    {
        $accounts = $facilities
            ->map(fn (BankFacility $f) => $this->accountOf($f))
            ->filter()
            ->unique()
            ->values();

        $balances = $accounts->isEmpty() ? collect() : DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $accounts->all())
            ->groupBy('account_id')
            ->pluck(DB::raw('SUM(credit - debit)'), 'account_id');

        /* ⓘ কার খোলা বকেয়া ইতিমধ্যে খাতায় বসেছে — এক কোয়েরিতে */
        $opened = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('source_type', self::OPENING_SOURCE)
            ->whereIn('source_id', $facilities->pluck('id')->all())
            ->distinct()
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $standing = [];

        foreach ($facilities as $facility) {
            $account = $this->accountOf($facility);

            // ⓘ CC-তে টাকা বেরোলে ব্যাংকের জের ঋণাত্মক, আর ঋণ ততটাই
            $ledger = (string) ($balances[$account] ?? '0');

            /*
             * ⛔ খোলা বকেয়া দুইবার গোনা যাবে না — ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ আগে `opening_drawn` কেবল একটা লেখা সংখ্যা ছিল, খাতায়
             * যেত না — তাই খাতার সাথে যোগ করতে হত। ⭐ এখন "আগে থেকেই
             * চলছে" বললে ওটা খাতায় বসে, তাই যোগ করলে বকেয়া দ্বিগুণ
             * দেখাত — আর মাঝপথে শোধের চার্জও দ্বিগুণ হত।
             *
             * ⚠️ পুরনো সারিগুলোর খোলা দাখিলা নেই, তাই ওদের লেখা
             * সংখ্যাটাই যোগ হয় — নাহলে ওদের বকেয়া হঠাৎ শূন্য দেখাত।
             */
            $used = in_array((int) $facility->id, $opened, true)
                ? $ledger
                : bcadd((string) ($facility->opening_drawn ?? '0'), $ledger, 4);

            // ⛔ ঋণাত্মক "ব্যবহৃত" মানে বেশি শোধ — পর্দায় ওটা শূন্য
            if (bccomp($used, '0', 4) < 0) {
                $used = '0.0000';
            }

            $left = bcsub((string) $facility->limit_amount, $used, 4);

            $standing[(int) $facility->id] = [
                'used' => $used,
                'left' => bccomp($left, '0', 4) > 0 ? $left : '0.0000',
            ];
        }

        return $standing;
    }

    /**
     * দেনাটা কোন খাতে বসে — ধরনটাই ঠিক করে ([[standing()]]-এর ব্যাখ্যা)।
     */
    private function accountOf(BankFacility $facility): ?int
    {
        if ($facility->kind === BankFacility::GUARANTEE) {
            return null;
        }

        return $facility->isBalanceSheetDebt()
            ? ($facility->liability_account_id === null ? null : (int) $facility->liability_account_id)
            : ($facility->money_account_id === null ? null : (int) $facility->money_account_id);
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
