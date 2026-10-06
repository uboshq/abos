<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\DuplicateGuard;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * গ্রাহক তৈরি ও সম্পাদনা — সেকশন ১৯.৬ অনুযায়ী লজিক এখানে, কন্ট্রোলারে নয়।
 *
 * কন্ট্রোলার শুধু অনুরোধ নেয় ও উত্তর দেয়; কোড কীভাবে তৈরি হয়, বাংলা নাম
 * বাধ্যতামূলক কি না, খোলা ব্যালেন্স হিসাবে কীভাবে বসে — সব এখানে। ফলে
 * একই কাজ পরে API বা ইমপোর্ট থেকে ডাকলে নিয়মগুলো আবার লিখতে হয় না।
 */
final class CustomerService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly SettingsService $settings,
        private readonly OpeningBalanceService $openings,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    /** ⓘ শুরুর বাকি বসানোর চাবি — ছাঁচে কেবল হিসাবরক্ষকের ([[assertMayOpenABalance()]]) */
    public const OPENING_KEY = 'customer.opening_balance';

    public function create(array $data): Customer
    {
        $this->assertBornWithoutALimit($data);
        $this->assertMayOpenABalance($data);
        $this->assertBanglaNameIfRequired($data);
        $this->assertNotADuplicate($data);
        $this->assertOnlyOneDistributorPerPoint($data);

        return DB::transaction(function () use ($data) {
            // কোড না দিলে সিরিজ থেকে — নম্বর ইস্যু ট্রানজেকশনের ভেতরে,
            // নাহলে গ্রাহক সেভ ব্যর্থ হলেও কোডটা খরচ হয়ে যেত।
            $givenCode = filled($data['code'] ?? null);

            $data['code'] = $givenCode ? trim($data['code']) : $this->numbers->next('CUS');

            $this->assertCodeIsFree($data['code']);

            $customer = Customer::create([
                ...$data,
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'status' => DocumentStatus::CONFIRMED,
                // ডাটাবেজে ডিফল্ট true আছে, তবু এখানে বসানো হয়: ডিফল্টটা
                // শুধু সারিতে বসে, ফেরত দেওয়া মডেলে নয়। ফলে যে কোড এই
                // মডেলটা ধরে is_active দেখত, সে null পেত — আর null মিথ্যা
                // বলেই গ্রাহকটাকে নিষ্ক্রিয় ভাবত।
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            /*
             * ইস্যু করা কোডটা কোন গ্রাহকে বসল, সেটা নম্বর-রেজিস্টারে
             * ফেরত লেখা হয় — নাহলে "CUS-0007 কার" প্রশ্নের উত্তর থাকত না।
             *
             * শর্তটা আগে $data['code_was_given'] দেখত, অথচ ওই কী কেউ
             * কোথাও বসাত না — মানে শর্তটা সবসময় সত্যি ছিল। হাতে লেখা
             * কোডের জন্য রেজিস্টারে সারি থাকে না বলে ক্ষতি হয়নি, কিন্তু
             * শর্তটা কিছুই বাছাই করছিল না।
             */
            if (! $givenCode) {
                IssuedNumber::query()
                    ->where('document_no', $customer->code)
                    ->whereNull('source_id')
                    ->update(['source_type' => Customer::drillSourceType(), 'source_id' => $customer->id]);
            }

            /*
             * খোলা ব্যালেন্স খাতায়ও যায়, শুধু গ্রাহকের সারিতে নয়।
             *
             * না গেলে গ্রাহকের পাতায় পাওনা দেখাত, অথচ ট্রায়াল ব্যালেন্স
             * বা বকেয়া তালিকায় অঙ্কটা কোথাও থাকত না — ওরা লেজার থেকে
             * গোনে। দুই জায়গা থেকে দুই সংখ্যা মানে একদিন অমিল।
             */
            $this->openings->forReceivable(
                Customer::drillSourceType(),
                $customer->id,
                $customer->code,
                (string) $customer->opening_balance,
                $customer->opening_date,
            );

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $this->assertBanglaNameIfRequired($data, $customer);
        $this->assertNotADuplicate($data, $customer->id);
        $this->assertOnlyOneDistributorPerPoint($data, $customer);

        if (isset($data['code']) && $data['code'] !== $customer->code) {
            $this->assertCodeIsFree($data['code'], $customer->id);
        }

        // খোলা ব্যালেন্স বদলানো হিসাবের কাজ, সম্পাদনার নয়: গ্রাহকের
        // পাওনা লেজার থেকে আসে, আর এখানে সংখ্যাটা বদলালে লেজার ও তালিকা
        // দুই রকম বলত। বদলাতে হলে একটা জাবেদা ভাউচার লাগবে।
        unset($data['opening_balance'], $data['opening_date']);

        /*
         * ⭐ বাকির সীমা বাড়ানো সই চায় — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কেন এই একটা ঘর ───────────────────────────
         * বাকির সীমাটাই সবচেয়ে চুপচাপ দরজা: সংখ্যাটা একবার বাড়িয়ে
         * দিলেই ওই গ্রাহকের কাছে যত খুশি মাল বাকিতে যায় — প্রতিটা
         * বিক্রয় তখন নিয়ম মেনেই হয়, আর কোথাও কিছু ভাঙে না।
         *
         * ⚠️ ⛔ আর ধরা পড়ে বহু পরে, যখন টাকাটা আর ওঠে না।
         *
         * ── ⓘ কেবল বাড়ালে, কমালে নয় ────────────────────
         * সীমা কমানো ঝুঁকি কমায়, তাই ওতে সই চাওয়া কেবল কাজ থামাত।
         * ⓘ অঙ্ক হিসেবে বাড়তিটাই যায় — তাই কোম্পানি "পঞ্চাশ হাজারের
         * বেশি বাড়ালে সই" বসাতে পারে।
         */
        /*
         * ⛔ অঙ্কটা `null`, আর এই অঙ্কটাই একটা ফাঁক বন্ধ করে।
         *
         * ⚠️ আগে অঙ্ক হিসেবে **বাড়তিটা** যেত (`bcsub($after, $before)`),
         * যাতে কোম্পানি *"পঞ্চাশ হাজারের বেশি বাড়ালে সই"* বসাতে পারে।
         *
         * ⛔ কিন্তু [[ApprovalFlow::appliesTo()]] সীমার **নিচের** অঙ্কে
         * অনুমোদন চায় না — ওটাও ইচ্ছাকৃত, কারণ *"৫০ টাকার
         * ডিসকাউন্টে মালিকের সই"* কেউ মানে না।
         *
         * ⚠️ দুইটা **ঠিক** সিদ্ধান্ত একসাথে বসে ফলটা হয়েছিল এই:
         * **২০ হাজার করে তিনবার বাড়িয়ে ৬০ হাজার**, আর একটাও সই লাগত না।
         * ⓘ প্রতিটা ধাপ আলাদাভাবে নিয়ম মেনেই হত, খাতায় তিনটা বৈধ
         * সম্পাদনা বসত, আর কিছুই ভাঙত — ধরা পড়ত বহু পরে, যখন
         * ওই গ্রাহকের টাকা আর ওঠে না।
         *
         * ⭐ মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬: সীমা পরম, আর সীমা
         * বাড়ানোই একমাত্র বৈধ পথ — তাই ওই পথটা ফাঁকা থাকতে পারে না।
         * *"টাকার বিষয়ে অঙ্ক দেখে সই এড়ানো যাবে না"*, এক পয়সা হলেও।
         *
         * ⓘ `null` পাঠানো ব্যবস্থাটার নিজেরই পথ —
         * [[DocumentApproval::stopping()]]-এর নথিতে লেখা:
         * *"থাকলে `null`, আর তখন সীমা যা-ই হোক অনুমোদন লাগে"*।
         *
         * ⚠️ অঙ্কটা তাই অনুরোধের সারিতে আর বসে না, কিন্তু **হারায় না**:
         * `reason`-এ কত থেকে কত দুইটাই যায়, আর সইকারী সেটাই পড়েন
         * ([[DocumentApproval::awaitingWord()]])।
         *
         * ⛔ ফল: `credit_limit` ছকে `threshold_amount` ঘরটা আর কিছু
         * বাছাই করে না। ⓘ ওটা ইচ্ছাকৃত — যে সীমা টুকরো করে পার
         * হওয়া যায়, সেটা সীমা নয়।
         *
         * ⓘ পাহারা: [[TheLimitCouldBeRaisedInSlicesWithoutASignatureTest]] (৭টা)।
         */
        $before = (string) ($customer->credit_limit ?? '0');
        $after = (string) ($data['credit_limit'] ?? $before);

        if (bccomp($after, $before, 4) > 0) {
            $this->assertRaiseIsSigned($customer, $before, $after);
        }

        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * ⭐ একটা গ্রাহকের নতুন বাকির সীমা — একসাথে অনেকের সীমা বসানোর জন্য ([[CustomerLimitImporter]]), ২ অক্টোবর ২০২৬।
     *
     * ⓘ মালিকের প্রয়োজন: erp-এ UB-র ৪১৪ জনের সবার সীমা ০, আর ০ মানে বাকি নেই (১ অক্টোবরের চূড়ান্ত কথা)। ধরে ধরে
     * সম্পাদনা মানে ৪১৪ বার সংরক্ষণ, ৪১৪ সই, তারপর আবার ৪১৪ বার সংরক্ষণ।
     *
     * ⭐ নিয়মটা সম্পাদনারই ([[assertRaiseIsSigned()]]), কেবল ফলটা ব্যতিক্রম নয় — কথা:
     *   · কমানো বা সমান → সাথে সাথে বসে (`applied` / `same`) — কমাতে সই লাগে না।
     *   · বাড়ানো, আর ঠিক এই অঙ্কে সই আগেই পড়েছে → বসে (`applied`)।
     *   · বাড়ানো, সই নেই → ঠিক এই অঙ্কে অনুরোধ বসে (`awaiting`); শেষ সই পড়লে নিজে বসে
     *     ([[ApplyTheLimitOnTheLastSignature]])। ⛔ ছক না থাকলে আগের মতোই পরিষ্কার কথায় থামে।
     */
    public function proposeLimit(Customer $customer, string $after): string
    {
        $after = bcadd($after, '0', 4);
        $before = bcadd((string) ($customer->credit_limit ?? '0'), '0', 4);

        if (bccomp($after, $before, 4) === 0) {
            return 'same';
        }

        if (bccomp($after, $before, 4) > 0) {
            try {
                $this->assertRaiseIsSigned($customer, $before, $after);
            } catch (ValidationException $e) {
                $waiting = Approval::query()
                    ->where('approvable_type', $customer->getMorphClass())
                    ->where('approvable_id', $customer->getKey())
                    ->where('action', 'credit_limit')
                    ->where('status', Approval::PENDING)
                    ->where('amount', $after)
                    ->exists();

                if (! $waiting) {
                    throw $e;
                }

                return 'awaiting';
            }
        }

        $customer->forceFill(['credit_limit' => $after])->save();

        return 'applied';
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * যে গ্রাহকের বিল বা আদায় আছে তাকে মুছে ফেললে ওই লেনদেনগুলো কার,
     * সেই প্রশ্নের উত্তর হারিয়ে যায়।
     */
    /**
     * ⭐ "বাকি বন্ধ" বসানো — বাকি ও আদায় (SAP Credit Management-এর "credit block"), ৫ অক্টোবর ২০২৬।
     *
     * ⓘ কারণ বাধ্যতামূলক; কে আর কখন সারিতেই বসে, আর বদলটা নিরীক্ষার খাতায় ওঠে — ঘরগুলোর আগে-পরে
     * ([[IsAudited]]) আর আলাদা কাজের নাম `credit_blocked`, কারণসহ। ⛔ ডাকার পক্ষ চাবি দেখে (`customer.update`,
     * সীমা বদলানোর একই চাবি — [[CreditBlockController]])।
     */
    public function blockCredit(Customer $customer, string $reason): Customer
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($customer, $reason) {
            $fresh = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isCreditBlocked()) {
                throw ValidationException::withMessages(['reason' => __('customer::credit_block.already')]);
            }

            $fresh->forceFill([
                'credit_blocked_at' => now(),
                'credit_blocked_by' => auth()->id(),
                'credit_block_reason' => $reason,
            ])->save();

            $fresh->auditAction('credit_blocked', $reason);

            return $fresh->fresh();
        });
    }

    /** ⭐ "বাকি বন্ধ" তোলা — একই চাবি, কারণ বাধ্যতামূলক, খাতায় `credit_unblocked` */
    public function clearCreditBlock(Customer $customer, string $reason): Customer
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($customer, $reason) {
            $fresh = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isCreditBlocked()) {
                throw ValidationException::withMessages(['reason' => __('customer::credit_block.not_blocked')]);
            }

            $fresh->forceFill([
                'credit_blocked_at' => null,
                'credit_blocked_by' => null,
                'credit_block_reason' => null,
            ])->save();

            $fresh->auditAction('credit_unblocked', $reason);

            return $fresh->fresh();
        });
    }

    public function deactivate(Customer $customer): Customer
    {
        $customer->update(['is_active' => false]);

        return $customer->fresh();
    }

    public function activate(Customer $customer): Customer
    {
        /*
         * ⚠️ সক্রিয় করাই সেই বাঁকে যেখানে নিয়মটা ভাঙতে পারে।
         *
         * ⓘ নিষ্ক্রিয় থাকা পরিবেশক কারো জায়গা নেন না, তাই তৈরি বা সম্পাদনার
         * সময় তাঁকে আটকানো হয় না। ⛔ কিন্তু এই এক ক্লিকেই তিনি আবার
         * বসে পড়তে পারেন — আর তখন এক পয়েন্টে দুইজন হয়ে যেত।
         */
        $this->assertOnlyOneDistributorPerPoint(['is_active' => true], $customer);

        $customer->update(['is_active' => true]);

        return $customer->fresh();
    }

    /**
     * ⭐ সীমা বাড়ানো যায় কেবল **ঠিক সেই অঙ্কে** যেটায় সই পড়েছে — অডিট §১.২, ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ যে ফাঁকটা বন্ধ হলো ────────────────────────────────────────
     * নতুন অঙ্কটা থাকত কেবল কারণের **লেখায়**, আর সইয়ের ছাপ নেওয়া হত
     * **পুরনো** সীমার উপর। ⚠️ ১ লাখ → ২ লাখের সই পাওয়ার পর ৫০ লাখ লিখে
     * সেভ করলে ছাপ মিলত — কাগজটা তখনো ১ লাখেই ছিল — আর ৫০ লাখ বসে যেত।
     *
     * ── ⭐ এখন প্রস্তাবিত সীমাটা দুই জায়গায় বাঁধা ─────────────────────
     * ১. অনুরোধের `amount` ঘরে — [[Approval::covers()]] হুবহু সমান চায়,
     *    তাই এক টাকা বেশি হলেও নতুন সই লাগে। ⓘ সইকারীর ক্ষমতার সীমাও
     *    ([[AuthorityService]]) এখন এই অঙ্ক দিয়েই মাপা হয়।
     * ২. ছাপের ভেতরে — প্রস্তাবটা কাগজের একটা তোলা সম্পর্ক হিসেবে যায়
     *    ([[DocumentFingerprint]] তোলা সম্পর্কও ধরে), তাই "কোন সীমা থেকে
     *    কোন সীমা" দুইটাই ছাপে বসে।
     *
     * ── ⚠️ ছকের সীমা (threshold) এখনো কিছু বাছাই করে না ───────────────
     * 653f65c8-এর নিয়ম অটুট: ধরার প্রশ্নটা ইঞ্জিনকে `null` অঙ্কে করা হয়,
     * তাই সীমার নিচের বাড়ানোও সই চায়। ⓘ অঙ্কটা অনুরোধ বসার পরেই তার
     * সারিতে লেখা হয় — `request()`-কে অঙ্ক দিলে threshold আবার টুকরো
     * করে পার হওয়ার দরজা খুলত।
     *
     * ── ⛔ ছক না থাকলে নীরবে পার নয় ─────────────────────────────────
     * আগে ছক না বসানো কোম্পানিতে বাড়ানো এমনিই পার হত। ⭐ মালিকের নিয়ম:
     * টাকার প্রতিটা সিদ্ধান্তে মানুষের সই — তাই এখন থামে, আর বার্তা বলে
     * কোথায় ছক বসাতে হবে।
     *
     * ⓘ পাহারা: [[TheSignatureWasForOneLakhAndFiftyWereSetTest]]।
     */
    private function assertRaiseIsSigned(Customer $customer, string $before, string $after): void
    {
        // ⓘ "200000" আর "200000.00" একই প্রস্তাব — ছাপ যেন দুইটাকে আলাদা না ভাবে
        $after = bcadd($after, '0', 4);

        $paper = $this->withProposedLimit($customer, $after);
        $hash = app(DocumentFingerprint::class)->of($paper);

        $latest = app(ApprovalEngine::class)->latestFor($customer, 'credit_limit');

        if ($latest?->status === Approval::APPROVED && $latest->stillCovers($after, $hash)) {
            return;
        }

        $reason = __('customer::approval.limit_raised', ['from' => $before, 'to' => $after]);

        $held = $this->approvals->stopping(
            document: $paper,
            module: 'customer',
            action: 'credit_limit',
            amount: null,
            reason: $reason,
        );

        if ($held === null) {
            throw ValidationException::withMessages([
                'credit_limit' => __('customer::validation.limit_needs_a_flow'),
            ]);
        }

        /*
         * ⓘ অঙ্কটা বসে কেবল **এই প্রস্তাবের জন্যই বসা** অনুরোধে — ছাপ
         * মিলিয়ে। ⚠️ আগে থেকে ঝুলে থাকা অন্য কোনো অনুরোধ (অন্য অঙ্কের)
         * ফেরত এলে তার অঙ্ক বদলানো যায় না — সইকারী যা পড়েছেন সেটাই থাকে।
         */
        if ($held->status === Approval::PENDING
            && $held->amount === null
            && $held->state_hash !== null
            && hash_equals((string) $held->state_hash, $hash)) {
            $held->update(['amount' => $after]);
        }

        // ⓘ বার্তাটা (অপেক্ষায় · প্রত্যাখ্যাত) ইঞ্জিনের নিজের — একই অনুরোধটাই ফেরে
        $this->approvals->assertClear(
            document: $paper,
            module: 'customer',
            action: 'credit_limit',
            field: 'credit_limit',
            amount: null,
            reason: $reason,
        );

        // ⛔ এখানে পৌঁছানো মানে কিছু একটা থামায়নি — তবু সই ছাড়া সীমা বসবে না
        throw ValidationException::withMessages([
            'credit_limit' => __('core.approval.awaiting'),
        ]);
    }

    /**
     * গ্রাহকের একটা কপি, যার সাথে প্রস্তাবিত সীমাটা তোলা সম্পর্ক হিসেবে লাগানো।
     *
     * ⚠️ `withoutRelations()` — হাতের মডেলে যা-ই তোলা থাকুক (পর্দা হয়তো
     * `partyType` তুলেছে), ছাপ যেন কেবল সারি আর প্রস্তাবটাই ধরে; নাহলে
     * একই প্রস্তাব দুই পর্দা থেকে দুই ছাপ পেত।
     */
    private function withProposedLimit(Customer $customer, string $after): Customer
    {
        $proposal = new Customer;
        $proposal->setRawAttributes(['credit_limit' => $after]);

        $paper = $customer->withoutRelations();
        $paper->setRelation('proposed_credit_limit', $proposal);

        return $paper;
    }

    /**
     * ⛔ নতুন গ্রাহক শূন্য সীমায় জন্মায় — ফর্ম, ইমপোর্ট, লিড, তিন দরজাতেই।
     *
     * ⓘ সইয়ের অনুরোধ বসে একটা **আছে এমন** কাগজে; যে গ্রাহক এখনো তৈরিই হয়নি
     * তাঁর জন্য অনুরোধ বসানো যায় না। ⚠️ আর তৈরি করে শূন্যে নামিয়ে দিয়ে
     * "সফল" বললে ব্যবহারকারী ভাবতেন সীমা বসেছে — নীরব ভুল। ⭐ তাই সীমা
     * দিলে তৈরি থামে, আর বার্তা বলে: আগে তৈরি, তারপর সম্পাদনা থেকে বাড়ানো
     * (সেখানে সই চাওয়া হয়)।
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * ⛔ শুরুর বাকি খাতায় বসানো টাকার কাজ — নিজের চাবি লাগে (গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)।
     *
     * আগে গ্রাহক বানানোর চাবিতেই (Field Sales ছাঁচেও আছে) যেকোনো অঙ্ক, যেকোনো চিহ্ন, যেকোনো তারিখে পাওনা বসত
     * — কোনো সই ছাড়া; মালিকের নিয়ম যেকোনো টাকায় মানুষের সিদ্ধান্ত। ⭐ পাহারাটা এখানে, দরজায় নয় — ফর্ম,
     * ইমপোর্ট ([[CustomerImporter]]) আর যেকোনো নতুন পথ একই জায়গা দিয়ে যায়। ⓘ শূন্য বা ফাঁকা অঙ্কে চাবি লাগে
     * না (গ্রাহক বানানো আটকায় না, কেবল টাকাটা), আর মানুষ ছাড়া পথ (সিডার, কমান্ড) আগের মতো। ⛔ চুপচাপ শূন্য
     * করা হয় না — তাহলে লোকটা ভাবতেন বসেছে; ফেরত দেওয়া হয় কারণসহ।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertMayOpenABalance(array $data): void
    {
        $amount = trim((string) ($data['opening_balance'] ?? ''));

        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 4) === 0) {
            return;
        }

        $user = auth()->user();

        if ($user === null || $user->can(self::OPENING_KEY)) {
            return;
        }

        throw ValidationException::withMessages([
            'opening_balance' => __('customer::validation.opening_needs_key'),
        ]);
    }

    private function assertBornWithoutALimit(array $data): void
    {
        $limit = trim((string) ($data['credit_limit'] ?? ''));

        if ($limit === '' || ! is_numeric($limit)) {
            return;
        }

        if (bccomp($limit, '0', 4) > 0) {
            throw ValidationException::withMessages([
                'credit_limit' => __('customer::validation.limit_on_create'),
            ]);
        }
    }

    /**
     * বাংলা নাম বাধ্যতামূলক কি না — Control Panel থেকে (নিয়ম ৭)।
     *
     * ডিফল্টে নয়: বাধ্যতামূলক করলে ডাটা এন্ট্রি দ্বিগুণ ভারী হয়, আর
     * অনেক প্রতিষ্ঠান ইংরেজিতেই কাজ করে (সেকশন ১৮.৩)।
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * সেভ না করে দেখা — সারিটা গ্রহণযোগ্য কি না।
     *
     * ইমপোর্টের যাচাই-পর্দার জন্য। ওখানে একই নিয়ম আলাদা করে লিখলে একদিন
     * একটা বদলে যেত আর অন্যটা পুরনো থেকে যেত — তখন পর্দায় সারিটা সবুজ
     * দেখাত, আর বসানোর সময় ব্যর্থ হত।
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertImportable(array $data): void
    {
        $this->assertBornWithoutALimit($data);
        $this->assertBanglaNameIfRequired($data);

        /*
         * ⭐ পরিবেশকের নিয়মটাও যাচাই-পর্দায় দেখা যাক, ১৫ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ এটা না থাকলে একশো সারির ফাইলে দুইটা পরিবেশক একই পয়েন্টে
         * থাকলে পর্দায় সব সবুজ দেখাত, আর বসানোর সময় ঠিক মাঝপথে ভেঙে
         * পড়ত। ⛔ তখন কিছু সারি বসে গেছে, কিছু বসেনি — আর কোনগুলো
         * বসেছে সেটা ব্যবহারকারীকে হাতে মিলিয়ে দেখতে হত।
         */
        $this->assertOnlyOneDistributorPerPoint($data);
    }

    private function assertBanglaNameIfRequired(array $data, ?Customer $existing = null): void
    {
        if (! $this->settings->enabled('customer.require_bn_name')) {
            return;
        }

        $bangla = $data['name_bn'] ?? $existing?->name_bn;

        if (blank($bangla)) {
            throw ValidationException::withMessages([
                'name_bn' => __('customer::validation.bn_name_required'),
            ]);
        }
    }

    /**
     * এই গ্রাহকটা কি আগে থেকেই খাতায় আছেন।
     *
     * ফোন মিললে আটকায় — একই নম্বর মানে প্রায় নিশ্চিতভাবে একই মানুষ, আর
     * দুইটা সারি হলে তাঁর বকেয়া দুই ভাগ হয়ে যায়, কেউ মোট পাওনা জানে না।
     *
     * নাম মিললে আটকায় না, কেবল বলে। "রহিম স্টোর" নামে দুই বাজারে দুইটা
     * আলাদা দোকান সত্যিই থাকতে পারে; ওটা আটকালে সৎ ব্যবহারকারী কাজই
     * করতে পারতেন না। তিনি `allow_duplicate` টিক দিয়ে এগোতে পারেন, আর
     * সেই সিদ্ধান্তটা সারিতে বসে থাকে।
     *
     * `$data` রেফারেন্সে নেওয়া, কারণ শেষে `allow_duplicate` মুছে ফেলতে
     * হয়। ওটা একটা সিদ্ধান্তের ঘর, গ্রাহকের কোনো কলাম নয় — রেখে দিলে
     * `Customer::create()` mass-assignment-এ ছুঁড়ে ফেলত, আর প্রতিটা
     * জেনেশুনে বসানো নকল সেভ হওয়ার আগেই ভেঙে পড়ত।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotADuplicate(array &$data, ?int $exceptId = null): void
    {
        $guard = app(DuplicateGuard::class);

        $allowed = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);

        $guard->assertPhoneIsFree(Customer::class, ['phone'], $data['phone'] ?? null, $exceptId);

        if ($allowed) {
            return;
        }

        $matches = $guard->nameMatches(
            Customer::class,
            ['name_en', 'name_bn'],
            $data['name_en'] ?? null,
            $exceptId,
        );

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                'name_en' => __('core.duplicate.name_matches').' '.__('core.duplicate.confirm_hint'),
            ]);
        }
    }

    /**
     * ⭐ এক পয়েন্টে একজনই সক্রিয় পরিবেশক — আর পরিবেশকের পয়েন্ট লাগবেই।
     *
     * ── ⛔ মালিকের নিয়ম, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * *"গ্রাহকের ধরন যদি পরিবেশক হয় তাহলে পয়েন্ট বাধ্যতামূলক। আর এক
     * পয়েন্টে দুজন সক্রিয় পরিবেশক হবে না — দুজন থাকলে একটা নিষ্ক্রিয়
     * করে আরেকটা সক্রিয় করতে হবে। কেন? **এক এলাকায় একজনই পরিবেশক হয়।**"*
     *
     * ── ⚠️ কেন এটা সার্ভিসে, ভ্যালিডেশনে নয় ────────────────────────────
     * গ্রাহক তিনটা দরজা দিয়ে ঢোকে: ফর্ম, ইমপোর্ট, আর মোবাইল সিংক। ⛔
     * নিয়মটা [[CustomerRequest]]-এ লিখলে কেবল **প্রথম** দরজাটা পাহারা
     * পেত, আর বাকি দুইটা দিয়ে এক পয়েন্টে দুই পরিবেশক দিব্যি ঢুকে যেত।
     *
     * ⓘ কোডের অনন্যতাও ঠিক এই কারণেই এখানে ([[assertCodeIsFree]]) —
     * একই যুক্তি, একই জায়গা।
     *
     * ── ⓘ "সক্রিয়" শব্দটা এখানে মূল কথা ────────────────────────────────
     * পুরনো পরিবেশক ইতিহাসে থেকে যান, নইলে তাঁর নামের বিলগুলো অনাথ হত।
     * ⭐ তাই বাধাটা কেবল **সক্রিয়** সারির উপর: পুরনোজনকে নিষ্ক্রিয় করে
     * নতুনজনকে বসানো যায়, আর দুইজন একসাথে সক্রিয় থাকতে পারেন না।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyOneDistributorPerPoint(array $data, ?Customer $existing = null): void
    {
        $typeId = array_key_exists('party_type_id', $data)
            ? $data['party_type_id']
            : $existing?->party_type_id;

        if (blank($typeId) || ! PartyType::query()->whereKey($typeId)->first()?->isDistributor()) {
            return;
        }

        $pointId = array_key_exists('location_id', $data)
            ? $data['location_id']
            : $existing?->location_id;

        /*
         * ⛔ পয়েন্ট ছাড়া পরিবেশক হয় না।
         *
         * ⓘ বাকি সব ধরনে পয়েন্টটা ঐচ্ছিক (নতুন দোকান বসানোর সময় এলাকা
         * ভাগ ঠিক না-ও থাকতে পারে)। ⚠️ কিন্তু পরিবেশকের পুরো সংজ্ঞাটাই
         * এলাকা ধরে — পয়েন্ট না জানলে "এক এলাকায় একজন" নিয়মটা কীসের
         * উপর দাঁড়াবে?
         */
        if (blank($pointId)) {
            throw ValidationException::withMessages([
                'location_id' => __('customer::validation.distributor_needs_a_point'),
            ]);
        }

        // নিষ্ক্রিয় পরিবেশক কারো জায়গা নেন না — তাই তাঁকে আটকানোর কিছু নেই
        $willBeActive = (bool) (array_key_exists('is_active', $data)
            ? $data['is_active']
            : ($existing?->is_active ?? true));

        if (! $willBeActive) {
            return;
        }

        // ⛔ দেয়াল ছাড়া — অন্যের ডিলার না দেখলে পয়েন্টটা "খালি" দেখাত (⛔১৬, §গ৫)
        $sitting = Customer::acrossDealers()
            ->where('location_id', $pointId)
            ->where('is_active', true)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->whereHas('partyType', fn ($q) => $q->where('code', PartyType::DISTRIBUTOR))
            ->first();

        if ($sitting !== null) {
            throw ValidationException::withMessages([
                'location_id' => __('customer::validation.point_already_has_a_distributor', [
                    'name' => $sitting->name(),
                    'code' => $sitting->code,
                ]),
            ]);
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        // ⛔ দেয়াল ছাড়া — অন্যের ডিলারের সংকেত না দেখলে "খালি" বলত, আর unique নিয়ম ৫০০ দিত (⛔১৬, §গ৫)
        $taken = Customer::acrossDealers()
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // মুছে ফেলা গ্রাহকের কোডও দখলে থাকে: সফট ডিলিট মানে রেকর্ডটা
            // এখনো আছে, আর একই কোডে দুইটা রেকর্ড থাকলে লেজারের ড্রিল-ডাউন
            // কোনটায় যাবে বলা যেত না।
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('customer::validation.code_taken', ['code' => $code]),
            ]);
        }
    }
}
