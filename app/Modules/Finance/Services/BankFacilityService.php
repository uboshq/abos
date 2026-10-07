<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Voucher;
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
    use ReadsTheRowUnderLock;

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

        // ⛔ চলতি ঋণের খোলা বকেয়াও সই ছাড়া খাতায় নয় — অডিট গ১, ৪ অক্টোবর ২০২৬
        private readonly DocumentApproval $approval,
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

            // ⭐ প্রথম কিস্তির দিন — কিস্তির সূচির তারিখ ([[datedSchedule()]]); না দিলে মঞ্জুরির পরের মাস
            'first_instalment_on' => ($data['first_instalment_on'] ?? '') ?: null,

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
    public function openingFor(BankFacility $facility, string $outstanding): bool
    {
        if (bccomp($outstanding, '0', 4) <= 0) {
            return false;
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

        /*
         * ⛔ সই ছাড়া খাতায় নয় — অডিট গ১, ৪ অক্টোবর ২০২৬। ⓘ খোলা বকেয়া দায় বাড়ায় আর সঞ্চিত মুনাফা কমায়;
         * ছক থাকলে অপেক্ষা, শেষ সই পড়লে [[finishSigned()]] এই পথটাই আবার ডাকে, আর তখন সইটা পাওয়া।
         */
        if ($this->approval->stopping($facility, FinanceSignature::MODULE, FinanceSignature::BANK_FACILITY, $outstanding) !== null) {
            return true;
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

        return false;
    }

    /**
     * ⭐ শেষ সই পড়ল — খোলা বকেয়াটা খাতায় ([[FinishTheFinancePaperOnTheLastSignature]])।
     *
     * ⓘ সারিতে তালা, আর আগে বসে গিয়ে থাকলে কিছু নয় — একই উৎসে দ্বিতীয় দাখিলা পোস্টিং ইঞ্জিন এমনিতেই
     * নেয় না, কিন্তু সেই ব্যতিক্রম সইকারীর ঘাড়ে ফেরা উচিত নয়।
     */
    public function finishSigned(BankFacility $facility): void
    {
        DB::transaction(function () use ($facility): void {
            $this->lockFresh($facility);

            $already = DB::table('ledger_entries')
                ->where('company_id', CompanyContext::id())
                ->where('source_type', self::OPENING_SOURCE)
                ->where('source_id', (int) $facility->id)
                ->exists();

            if (! $already) {
                $this->openingFor($facility, (string) ($facility->opening_drawn ?? '0'));
            }
        });
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
        $count = (int) ($facility->instalments ?? 0);

        /*
         * ⛔ শোধ মানে শোধের ভাউচারগুলোর আসল — দায়ের খাতের "ডেবিট − ক্রেডিট" নয় (অডিট গ১৬, ৪ অক্টোবর ২০২৬)।
         *
         * ⓘ আগের যোগফলে টাকা তোলার ক্রেডিটও ঢুকত: নতুন ঋণে ২৫ লাখ তোলা (ক্রেডিট) আর বারো কিস্তির আসল
         * (ডেবিট) মিলে ঋণাত্মক, আর পর্দা বলত "০ দেওয়া"। ⭐ এখন ভাউচার ধরে: যে ভাউচার দায় কমায় সেটাই শোধ;
         * বাতিল ভাউচার নিজের উল্টো সারিতে শূন্যে নামে, তাই গোনা হয় না।
         */
        $repaid = $this->repayments($facility)['repaid'];

        /*
         * ⛔ কিস্তি গোনা হয় আসল ধরে, "শোধ ÷ কিস্তি" নয় — অডিট গ১৬, ৪ অক্টোবর ২০২৬।
         *
         * ⓘ দায়ের খাতে বসে কেবল **আসল**, আর কিস্তির অঙ্কে (EMI) সুদও আছে — তাই ভাগ করলে সংখ্যাটা সবসময় কম
         * আসত: বারোটা কিস্তির পরেও নতুন ঋণ বলত "০ বা ৮ দেওয়া", আর আগাম শোধের চার্জ ([[settlementToday()]])
         * ফুলে যেত। ⭐ এখন সূচির আসলের যোগফল মিলিয়ে গোনা হয় ([[LoanSchedule::build()]]); সূচি না থাকলে
         * কয়টা শোধের ভাউচার এসেছে সেটাই।
         */
        $fromLedger = $this->instalmentsCovered($facility, $repaid, $opening);

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

            $left = (string) $this->interestLeft($facility);

            $charge = bcdiv(bcmul($left, $amount, 4), '100', 4);

            return [
                'outstanding' => $outstanding,
                'charge' => $charge,
                'total' => bcadd($outstanding, $charge, 4),
                'unknown' => false,
            ];
        }

        /*
         * ⛔ ভিত্তি না বলা থাকলে অঙ্কটা দেখানো হয় না — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে এই শেষ লাইনটা **চুপচাপ আসল ধরে নিত**। ১০ লাখ
         * বকেয়ায় ২% আসলের উপর মানে ২০,০০০ টাকা, আর বাকি সুদের
         * উপর হলে সংখ্যাটা সম্পূর্ণ আলাদা — অর্থাৎ অনুমানটাই টাকা।
         *
         * ⓘ মালিকের উত্তর (২১ সেপ্টেম্বর): *"manual korbo"* — তিনি
         * প্রতিবার নিজে বেছে নেবেন, কোনো ডিফল্ট চান না। তাই না বাছলে
         * উত্তরটা হবে "জানি না", আর পর্দা সেটাই বলবে
         * ([[bank-facility/show]] মানে `unknown`)।
         */
        if ((string) $facility->early_charge_basis !== BankFacility::ON_PRINCIPAL) {
            return [
                'outstanding' => $outstanding,
                'charge' => '0.0000',
                'total' => $outstanding,
                'unknown' => true,
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
        /*
         * ⛔ এক খাতে কয়েকটা ঋণ থাকলে প্রত্যেকে কেবল নিজের সারি — অডিট গ১৬, ৪ অক্টোবর ২০২৬।
         *
         * ⓘ সব মেয়াদি ঋণ একই দায়ের খাতে (২২১১) বসে, আর আগে খাতের পুরো জের প্রতিটা ঋণের নামে দেখাত — দুইটা
         * ২৫ লাখের ঋণ প্রত্যেকে "ব্যবহৃত ৫০ লাখ, বাকি ০"। ⭐ এখন খাতা-সারি ঋণের নামে বাঁধা ([[ownEntries()]]),
         * আর গোনা হয় সুবিধা ধরে; খাতে একটাই ঋণ থাকলে আগের মতো পুরো খাত।
         */
        $balances = [];

        foreach ($facilities as $facility) {
            $account = $this->accountOf($facility);

            if ($account !== null) {
                $balances[(int) $facility->id] = (string) ($this->ownEntries($facility, $account)
                    ->sum(DB::raw('credit - debit')) ?? '0');
            }
        }

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
            $ledger = (string) ($balances[(int) $facility->id] ?? '0');

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
     * ⭐ এই সুবিধার খাতা-সারি — নিজের খাতে, আর খাতটা অন্য ঋণের সাথে ভাগ হলে কেবল নিজের নামে বাঁধাগুলো।
     *
     * ── ⓘ "নিজের নামে" মানে কী ─────────────────────────────────────────
     *   · খোলা বকেয়া — উৎসটাই এই সুবিধা ([[OPENING_SOURCE]], আইডি)।
     *   · ভাউচার — যেটা এই সুবিধার বিপরীতে লেখা (`against_type = bank_facility`), সুবিধার পাতার
     *     "কিস্তি দিন" বোতাম ঠিক এই জোড়া নিয়েই ভাউচার খোলে।
     *
     * ⚠️ খাতে একটাই সুবিধা থাকলে আগের মতো পুরো খাত — পুরনো, জোড়া-ছাড়া ভাউচারগুলো হারায় না। ⛔ ভাগ হলে
     * জোড়া-ছাড়া সারি কারও নামে বসে না: কোন ঋণের টাকা সেটা খাতা জানে না, আর ভুল ঋণে বসানোর চেয়ে না বসানো
     * ভালো — ভুল সংখ্যা দেখে মানুষ আগাম শোধ করেন।
     */
    private function ownEntries(BankFacility $facility, int $account): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account);

        if (! $this->sharesItsAccount($facility, $account)) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where(fn ($o) => $o->where('source_type', self::OPENING_SOURCE)->where('source_id', (int) $facility->id))
            ->orWhere(fn ($v) => $v
                ->whereIn('source_type', [
                    ...array_values(Voucher::SOURCE_TYPES),
                    ...array_map(fn (string $t) => $t.':reversal', array_values(Voucher::SOURCE_TYPES)),
                ])
                ->whereIn('source_id', Voucher::query()
                    ->where('against_type', BankFacility::drillSourceType())
                    ->where('against_id', (int) $facility->id)
                    ->select('id'))));
    }

    /**
     * এই ঋণের শোধ — কোন কোন ভাউচার দায় কমিয়েছে, আর মোট কত আসল।
     *
     * ⓘ খাতা-সারি ভাউচার ধরে জোড়া হয় (উল্টো সারি `…:reversal` সহ), আর যে ভাউচারের নিট ডেবিট সেটাই শোধ।
     * টাকা তোলা (নিট ক্রেডিট) আর বাতিল ভাউচার (নিট শূন্য) বাদ পড়ে নিজে থেকেই।
     *
     * @return array{repaid: string, count: int}
     */
    private function repayments(BankFacility $facility): array
    {
        if ($facility->liability_account_id === null) {
            return ['repaid' => '0', 'count' => 0];
        }

        $rows = $this->ownEntries($facility, (int) $facility->liability_account_id)
            ->where('source_type', '<>', self::OPENING_SOURCE)
            ->groupBy('source_type', 'source_id')
            ->get(['source_type', 'source_id', DB::raw('SUM(debit - credit) as net')]);

        $byPaper = [];

        foreach ($rows as $row) {
            $key = preg_replace('/:reversal$/', '', (string) $row->source_type).'#'.$row->source_id;
            $byPaper[$key] = bcadd($byPaper[$key] ?? '0', (string) $row->net, 4);
        }

        $repaid = '0';
        $count = 0;

        foreach ($byPaper as $net) {
            if (bccomp($net, '0', 4) > 0) {
                $repaid = bcadd($repaid, $net, 4);
                $count++;
            }
        }

        return ['repaid' => $repaid, 'count' => $count];
    }

    /** একই খাতে আরেকটা সুবিধা আছে কি — বন্ধগুলোও, কারণ তাদের পুরনো সারিও ঐ খাতেই। */
    private function sharesItsAccount(BankFacility $facility, int $account): bool
    {
        return BankFacility::query()
            ->whereKeyNot($facility->getKey())
            ->get()
            ->contains(fn (BankFacility $other) => $this->accountOf($other) === $account);
    }

    /**
     * শোধ হওয়া আসলে কয়টা কিস্তি ঢাকে — সূচির আসল মিলিয়ে।
     *
     * ⓘ খোলার দিনে যতগুলো দেওয়া ছিল তার পরের কিস্তি থেকে গোনা শুরু, কারণ খাতায় শোধ বসে তার পরের গুলোর।
     * ⚠️ কিস্তিপ্রতি এক টাকার ছাড় রাখা হয় — সূচি দুই দশমিকে গোল করে, আর ব্যাংক মাসিক হার ছেঁটে নেয়।
     */
    private function instalmentsCovered(BankFacility $facility, string $repaid, int $opening): int
    {
        $schedule = $this->schedule($facility);

        if ($schedule !== null) {
            $covered = 0;
            $sum = '0';

            foreach (array_slice($schedule['rows'], $opening) as $row) {
                $sum = bcadd($sum, (string) $row['principal'], 2);

                // ⓘ কিস্তিপ্রতি এক টাকার ছাড় — ব্যাংক মাসিক হার ছেঁটে গোনে ([[LoanSchedule]]-এর টীকা)
                if (bccomp($sum, bcadd($repaid, (string) ($covered + 1), 4), 4) > 0) {
                    break;
                }

                $covered++;
            }

            return $covered;
        }

        // ⓘ সূচি নেই (লিজ, হার/সংখ্যা লেখা নেই) — কয়টা শোধের ভাউচার এসেছে
        return $this->repayments($facility)['count'];
    }

    /**
     * ⭐ বাকি সুদ — বাকি কিস্তিগুলোর সুদাংশের যোগফল ([[LoanSchedule::interestLeft]]), কয়টা দেওয়া হয়েছে সেটা খাতা থেকে।
     *
     * ⓘ একটাই হিসাব: মাঝপথে শোধের চার্জ ([[settlementToday()]]) আর ব্যাংক ঋণের তালিকার "বাকি সুদ" (মালিক, ৫ অক্টোবর ২০২৬)
     * দুইটাই এখান থেকে। ⚠️ কিস্তির সংখ্যা লেখা না থাকলে (CC, লিজ) সূচিই নেই — null, পর্দায় "—", শূন্য নয়।
     */
    public function interestLeft(BankFacility $facility): ?string
    {
        $months = (int) ($facility->instalments ?? 0);

        if ($months < 1) {
            return null;
        }

        return LoanSchedule::interestLeft(
            (string) $facility->limit_amount,
            (string) ($facility->interest_rate ?? '0'),
            $months,
            $this->instalmentStanding($facility)['paid'],
        );
    }

    /**
     * ⭐ এই ঋণের খাতা-সারি — [[standing()]] যে সারি থেকে বকেয়া গোনে, হুবহু সেগুলো ([[ownEntries()]]); ঋণের খাতার
     * রিপোর্ট ([[LoanLedgerReports::BANK_LOAN]]) এটাই পড়ে, তাই খাতার শেষ জের আর তালিকার "বাকি আসল" কখনো আলাদা হয় না।
     * ⓘ খাত নেই (গ্যারান্টি) — null।
     */
    public function ledgerRowsOf(BankFacility $facility): ?\Illuminate\Database\Query\Builder
    {
        $account = $this->accountOf($facility);

        return $account === null ? null : $this->ownEntries($facility, $account);
    }

    /**
     * পুরনো ব্যবস্থা থেকে তোলা টাকা যেটা খাতায় বসেনি — [[standing()]]-এর একই নিয়ম: খোলা দাখিলা থাকলে শূন্য (ওটা খাতায়
     * আছে), নাহলে লেখা `opening_drawn`। ⓘ খাতার রিপোর্ট এটা খোলা জেরে যোগ করে।
     */
    public function legacyOpening(BankFacility $facility): string
    {
        $opened = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('source_type', self::OPENING_SOURCE)
            ->where('source_id', (int) $facility->id)
            ->exists();

        return $opened ? '0.0000' : bcadd((string) ($facility->opening_drawn ?? '0'), '0', 4);
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
     * ⭐ কিস্তির সূচি, তারিখসহ — অর্থ-মডিউলের পরিকল্পনা ৩.২, ৬ অক্টোবর ২০২৬: "প্রতিটা কিস্তির আসল আর সুদ আলাদা, দেওয়া
     * হয়েছে কিনা"।
     *
     * ⓘ সারিগুলো [[schedule()]]-এর (ক্ষয়িষ্ণু জের, [[LoanSchedule]]) — কোনো সারি সংরক্ষণ হয় না। দিন: প্রথম কিস্তির দিন
     * (`first_instalment_on`), না থাকলে মঞ্জুরির পরের মাসের একই দিন; তারপর মাসে মাসে (মাসের শেষ দিনে আটকায়, ৩১ জানুয়ারির
     * পরে ২৮/২৯ ফেব্রুয়ারি)। ⓘ "দেওয়া" খাতা থেকে — [[instalmentStanding()]]-এর গুনতি পর্যন্ত সব শোধ; বাকিগুলোর অবস্থা
     * দিন পার / আজ / সামনে, আজকের তারিখ ধরে।
     *
     * @return array{rows: list<array<string, mixed>>, instalment: string, interest_total: string, paid_total: string, paid: int}|null
     */
    public function datedSchedule(BankFacility $facility, ?\Illuminate\Support\Carbon $today = null): ?array
    {
        $schedule = $this->schedule($facility);

        if ($schedule === null) {
            return null;
        }

        $today = ($today ?? \Illuminate\Support\Carbon::today())->toDateString();
        $paid = $this->instalmentStanding($facility)['paid'];
        $first = $this->firstInstalmentOn($facility);

        foreach ($schedule['rows'] as &$row) {
            $due = $first?->copy()->addMonthsNoOverflow((int) $row['month'] - 1);
            $row['due_on'] = $due?->toDateString();
            $row['amount'] = bcadd((string) $row['principal'], (string) $row['interest'], 2);
            $row['state'] = match (true) {
                (int) $row['month'] <= $paid => self::PAID,
                $due === null => self::UNDATED,
                $row['due_on'] < $today => self::OVERDUE,
                $row['due_on'] === $today => self::DUE_TODAY,
                default => self::UPCOMING,
            };
        }
        unset($row);

        return $schedule + ['paid' => $paid];
    }

    /** কিস্তির অবস্থা — [[datedSchedule()]] */
    public const PAID = 'paid';

    public const OVERDUE = 'overdue';

    public const DUE_TODAY = 'today';

    public const UPCOMING = 'upcoming';

    public const UNDATED = 'undated';

    /** প্রথম কিস্তির দিন — লেখা থাকলে সেটা, নইলে মঞ্জুরির পরের মাসের একই দিন */
    public function firstInstalmentOn(BankFacility $facility): ?\Illuminate\Support\Carbon
    {
        if ($facility->first_instalment_on !== null) {
            return \Illuminate\Support\Carbon::parse($facility->first_instalment_on);
        }

        return $facility->sanctioned_on === null
            ? null
            : \Illuminate\Support\Carbon::parse($facility->sanctioned_on)->addMonthNoOverflow();
    }

    /**
     * ⭐ সব চালু ঋণের বাকি কিস্তি যা আজ বা তার আগে পড়েছে, আর সামনের `$days` দিনে যা পড়বে — কিস্তির রিপোর্ট, ঘণ্টা আর
     * ড্যাশবোর্ড এটাই পড়ে (পরিকল্পনা ৩.৫, "মেয়াদ পার আর নবায়ন")।
     *
     * ⓘ দেখার শাখা মানে ([[ListedInViewedBranch]]); কিস্তি কেবল যেসব ঋণে সূচি আছে।
     *
     * @return list<array{facility: BankFacility, month: int, due_on: ?string, principal: string, interest: string, amount: string, state: string}>
     */
    public function instalmentsDue(int $days = 30, ?\Illuminate\Support\Carbon $today = null): array
    {
        $today ??= \Illuminate\Support\Carbon::today();
        $until = $today->copy()->addDays($days)->toDateString();
        $out = [];

        $facilities = BankFacility::query()->live()
            ->inViewedBranch()
            ->whereNotNull('instalments')
            ->orderBy('id')
            ->get();

        foreach ($facilities as $facility) {
            foreach ($this->datedSchedule($facility, $today)['rows'] ?? [] as $row) {
                if ($row['state'] === self::PAID || $row['due_on'] === null || $row['due_on'] > $until) {
                    continue;
                }

                $out[] = [
                    'facility' => $facility,
                    'month' => (int) $row['month'],
                    'due_on' => $row['due_on'],
                    'principal' => (string) $row['principal'],
                    'interest' => (string) $row['interest'],
                    'amount' => (string) $row['amount'],
                    'state' => $row['state'],
                ];
            }
        }

        usort($out, fn (array $a, array $b) => strcmp((string) $a['due_on'], (string) $b['due_on']));

        return $out;
    }

    /**
     * ⭐ একটা তারিখে খাতায় এই ঋণের দেনা — [[standing()]]-এর একই নিয়মে (নিজের খাতা-সারি + খাতায় না-বসা পুরনো তোলা),
     * কেবল সেই দিন পর্যন্ত। ব্যাংকের বিবরণীর পাশে বসে ([[statementGaps()]])।
     */
    public function owedOn(BankFacility $facility, string $date): string
    {
        $rows = $this->ledgerRowsOf($facility);

        if ($rows === null) {
            return '0.0000';
        }

        $ledger = (string) ((clone $rows)->where('trx_date', '<=', $date)->sum(DB::raw('credit - debit')) ?? '0');

        return bcadd($this->legacyOpening($facility), $ledger, 4);
    }

    /**
     * ⭐ ব্যাংকের বিবরণী বনাম খাতা — অর্থ-মডিউলের পরিকল্পনা ৩.৬। প্রতিটা লেখা বিবরণীর পাশে সেই দিনের খাতার দেনা আর ফাঁক
     * (ব্যাংক − খাতা); ধনাত্মক ফাঁক মানে ব্যাংক বেশি বলে — সাধারণত খাতায় না-বসা সুদ বা চার্জ।
     *
     * @return list<array{statement: \App\Modules\Finance\Models\FacilityStatement, books: string, gap: string}>
     */
    public function statementGaps(BankFacility $facility): array
    {
        return $facility->statements()->get()->map(function ($statement) use ($facility) {
            $books = $this->owedOn($facility, $statement->statement_on->toDateString());

            return [
                'statement' => $statement,
                'books' => $books,
                'gap' => bcsub((string) $statement->bank_balance, $books, 4),
            ];
        })->values()->all();
    }

    /**
     * ⭐ ব্যাংকের বিবরণীর জের লেখা — একই ঋণে একই দিনে একটাই; আবার লিখলে বদলায়। টাকা নড়ে না।
     *
     * @param  array{statement_on: string, bank_balance: string, note?: ?string}  $data
     */
    public function recordStatement(BankFacility $facility, array $data): \App\Modules\Finance\Models\FacilityStatement
    {
        return \App\Modules\Finance\Models\FacilityStatement::query()->updateOrCreate(
            ['bank_facility_id' => $facility->id, 'statement_on' => $data['statement_on']],
            [
                'company_id' => CompanyContext::id(),
                'branch_id' => $facility->branch_id,
                'bank_balance' => $data['bank_balance'],
                'note' => ($data['note'] ?? '') ?: null,
                'created_by' => auth()->id(),
            ],
        );
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
