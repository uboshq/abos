<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\ClassifiesDrafts;
use App\Core\Contracts\SignedBeforeItIsPaid;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\ListSummary;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ভাউচার পোস্ট করার আগে অনুমোদন লাগে কি না।
 *
 * ── কেন নতুন কোনো ইঞ্জিন লেখা হয়নি ──────────────────────────────────
 * অনুমোদনের ইঞ্জিন কোরেই আছে ([[ApprovalEngine]]) আর সে ধরন-নিরপেক্ষ:
 * `request(Model $document, …)` যেকোনো ডকুমেন্ট নেয়। স্তর, একই স্তরে
 * একাধিক অনুমোদনকারী, টাকার অঙ্ক ধরে সীমা, নিজের অনুরোধ নিজে অনুমোদনের
 * নিষেধ, Approval Centre-এর ইনবক্স — সব তৈরি। **আজ পর্যন্ত কেবল
 * বিক্রয়ের ছাড় ওটা ব্যবহার করত।** এই ফাইলটা কেবল ভাউচারকে ওই ইঞ্জিনের
 * সাথে যুক্ত করে।
 *
 * ── কেন ধরনটাই কাজের নাম ────────────────────────────────────────────
 * `action` হিসেবে ভাউচারের নিজের ধরন (`expense`) ব্যবহার করা হয়, তাই
 * ভবিষ্যতে কেউ `payment`-এও অনুমোদন চাইলে কেবল `module.php`-তে একটা
 * সারি যোগ করলেই হয় — এই ফাইলে কিছু লিখতে হয় না।
 *
 * ⚠️ ── ডিফল্ট পথটাই সবচেয়ে জরুরি ────────────────────────────────────
 * কোনো কোম্পানি অনুমোদনের ছক না বসালে `request()` **`null`** ফেরত দেয়
 * (প্রবাহ নেই, বা অঙ্ক সীমার নিচে), আর তখন পোস্টিং **আজকের মতোই** চলে।
 * এটা সুবিধা নয়, **শর্ত**: এই ফাইলটা যোগ করার পর যেন এমন একটাও
 * প্রতিষ্ঠান না থাকে যেখানে খরচ পোস্ট করা বন্ধ হয়ে গেল।
 */
final class VoucherApproval implements ClassifiesDrafts
{
    /** অনুমোদনের ছকে মডিউলের নাম — `module.php`-র `code`। */
    public const MODULE = 'accounts';

    /*
     * ⭐ খসড়ার সইয়ের অবস্থা — পাতার নোট ([[waitFor()]]), ফোনের সারি, অপেক্ষার তালিকা আর তালিকার মোট ([[standing()]]) সবাই
     * এই এক উত্তর পড়ে ([[decide()]]; তালিকার মোটের চুক্তি v6 — fe, a4, a6, ২৪ অক্টোবর ২০২৬)।
     *   awaiting — অনুরোধ গেছে, আর তারপর ভাউচার বদলায়নি: সিদ্ধান্তের অপেক্ষা;
     *   stale    — অনুরোধের পরে ভাউচার বদলেছে: "পোস্ট" চাপলে পুরনোটা তুলে নতুন করে সইয়ে যাবে ([[stopping()]]);
     *   needs    — অনুরোধ হয়নি, কিন্তু ছক বলছে পোস্টে সই লাগবে;
     *   signed   — সই আছে, ঢাকে — লেখক পোস্ট করবেন;
     *   rejected — ফেরানো, আর তারপর ভাউচার বদলায়নি;
     *   null     — সই লাগেই না।
     * ⛔ আগে ফোন আর ওয়েবের অপেক্ষার তালিকা যেকোনো PENDING-কেই "সইয়ের অপেক্ষায়" বলত — বাসি অনুরোধও, যেটা পরের পোস্টে তুলে নেওয়া হয়।
     */
    public const AWAITING = 'awaiting';

    public const STALE = 'stale';

    public const NEEDS = 'needs';

    public const SIGNED = 'signed';

    public const REJECTED = 'rejected';

    /** সইয়ের অবস্থা থেকে তালিকার মোটের দল — বাকি সব (stale, needs, signed, null) খসড়া */
    public const GROUP_OF = [self::AWAITING => ListSummary::PENDING, self::REJECTED => ListSummary::REJECTED];

    /**
     * বিপরীতের কাগজগুলো আগে থেকে তোলা — [[signingOf()]]-এর সময়টুকু, নইলে null ([[paperAlreadySigned()]])।
     *
     * @var array<string, bool>|null
     */
    private ?array $signedPapers = null;

    /** কাউন্টারের ডিপোজিটের অনুমোদনের কাজ — [[stopping()]]। */
    public const COUNTER_DEPOSIT = 'counter_deposit';

    /**
     * ক্রয়ের কাউন্টারে দেওয়া টাকা — ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ মালিকের কথা: *"বিক্রয় কাউন্টারের নিয়মেই করো।"* ⚠️ তবু ছকটা
     * আলাদা, কারণ ঘটনাটাই উল্টো: ওখানে টাকা আসে, এখানে টাকা যায়। ⛔ এক
     * ছকে বাঁধলে যিনি ক্রয়ে সই চান তিনি বিক্রয়ের প্রতিটা জমাও আটকাতেন।
     */
    public const COUNTER_PAYMENT = 'counter_payment';

    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * পোস্ট আটকাচ্ছে এমন কিছু আছে কি?
     *
     * `null` মানে **এগিয়ে যাও** — হয় অনুমোদন লাগে না, নয় ইতিমধ্যে
     * পাওয়া গেছে। কিছু ফেরত এলে সেটাই কারণ, আর তার `status` বলে দেয়
     * অপেক্ষায় নাকি প্রত্যাখ্যাত।
     */
    /**
     * ⭐ কম-বেশিতে মালিকের সই — ছাড় যেকোনো অঙ্কে, রাউন্ডিং ৳০.৫০-এর বেশি হলে ([[\App\Core\Support\Settlement::differenceNeedsOwner()]];
     * আদায়ের হুবহু নিয়ম আর হুবহু ছক — `sales.discount`, মালিকের একটাই ধাপ; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
     *
     * ⓘ সই লাগলে খসড়া থাকে আর অনুরোধ যায়; শেষ সইয়ে নিজে পোস্ট ([[\App\Modules\Accounts\Listeners\PostTheVoucherOnTheLastSignature]])।
     * ⛔ ছক বন্ধ বা নেই মানে ফেরত (fail-closed — অনুমোদন বন্ধ কোম্পানিতেও, আদায় আর বিলের মতোই); ফেরানো সই ঠিক সেই অঙ্কে আবার
     * পাঠালে ফেরত; সীমার বেশি রাউন্ডিং কখনো নয়।
     */
    public function differenceStopping(Voucher $voucher): ?Approval
    {
        [$kind, $amount] = $this->differenceOf($voucher);

        if (! \App\Core\Support\Settlement::differenceNeedsOwner($kind, $amount)) {
            return null;
        }

        $this->assertWithinLimit($kind, $amount);

        $hash = $this->differenceFingerprint($voucher);
        $decided = $this->approvals->latestFor($voucher, \App\Modules\Accounts\Support\DifferenceSignature::ACTION);

        if ($decided?->status === Approval::APPROVED && $decided->stillCovers($amount, $hash)) {
            return null;
        }

        if ($decided?->status === Approval::PENDING && $decided->state_hash !== null && ! hash_equals((string) $decided->state_hash, $hash)) {
            $this->approvals->withdrawStale($decided);
        }

        if ($decided?->status === Approval::REJECTED && $decided->covers($amount)) {
            throw ValidationException::withMessages(['difference.amount' => __('accounts::validation.difference_rejected')]);
        }

        $approval = $this->approvals->request(
            document: $voucher,
            module: \App\Modules\Accounts\Support\DifferenceSignature::MODULE,
            action: \App\Modules\Accounts\Support\DifferenceSignature::ACTION,
            amount: $amount,
            // ⓘ কোন সইয়ের জায়গা নিল — বিক্রির বিলের ছাড়ের হুবহু ([[SalesInvoiceService::assertDiscountApproved()]]); শর্ত মাপা হয় না (`matchOn: []`)
            payload: array_filter([
                'supersedes' => $decided?->status === Approval::APPROVED ? (int) $decided->id : null,
                'was_amount' => $decided?->status === Approval::APPROVED && $decided->amount !== null ? (string) $decided->amount : null,
            ], fn ($v) => $v !== null),
            reason: $voucher->narration,
            stateHash: $hash,
            matchOn: [],
        );

        if ($approval === null) {
            throw ValidationException::withMessages(['difference.amount' => $kind === 'rounding'
                ? __('accounts::validation.difference_rounding_no_signer', ['amount' => Money::format($amount), 'free' => Money::format(\App\Core\Support\Settlement::UNSIGNED)])
                : __('accounts::validation.difference_discount_no_signer')]);
        }

        return $approval;
    }

    /**
     * ⛔ পোস্টের নিজের পাহারা — যে পথেই আসুক (ওয়েব, ফোন, শেষ সইয়ের শেষকারী), সই লাগলে ঠিক এই কাগজের পাওয়া সই চাই।
     */
    public function assertDifferenceSigned(Voucher $voucher): void
    {
        [$kind, $amount] = $this->differenceOf($voucher);

        if (! \App\Core\Support\Settlement::differenceNeedsOwner($kind, $amount)) {
            return;
        }

        $this->assertWithinLimit($kind, $amount);

        $decided = $this->approvals->latestFor($voucher, \App\Modules\Accounts\Support\DifferenceSignature::ACTION);

        if ($decided?->status !== Approval::APPROVED || ! $decided->stillCovers($amount, $this->differenceFingerprint($voucher))) {
            throw ValidationException::withMessages(['difference.amount' => $kind === 'rounding'
                ? __('accounts::validation.difference_rounding_awaiting', ['amount' => Money::format($amount), 'free' => Money::format(\App\Core\Support\Settlement::UNSIGNED)])
                : __('accounts::validation.difference_discount_awaiting')]);
        }
    }

    /**
     * সইয়ের ছাপ — পক্ষ, ধরন, অঙ্ক, খাতার প্রতিটা সারি (খাত, টাকার খাত, টাকা, কাটা, পার্থক্য) আর বিলের প্রতিটা সারি; বিবরণ বাদ।
     */
    public function differenceFingerprint(Voucher $voucher): string
    {
        $voucher->loadMissing(['lines', 'allocations']);

        return hash('sha256', json_encode([
            (string) $voucher->party_type,
            (int) $voucher->party_id,
            (string) $voucher->difference_kind,
            bcadd((string) ($voucher->difference_amount ?? '0'), '0', 4),
            $voucher->lines->map(fn ($l) => [(int) $l->account_id, bcadd((string) $l->debit, '0', 4), bcadd((string) $l->credit, '0', 4)])->values()->all(),
            $voucher->allocations->map(fn ($a) => [(string) $a->against_type, (int) $a->against_id, bcadd((string) $a->amount, '0', 4)])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{0: ?string, 1: string} */
    private function differenceOf(Voucher $voucher): array
    {
        if (! in_array($voucher->type, [Voucher::RECEIPT, Voucher::PAYMENT], true)) {
            return [null, '0.0000'];
        }

        return [$voucher->difference_kind, bcadd((string) ($voucher->difference_amount ?? '0'), '0', 4)];
    }

    private function assertWithinLimit(?string $kind, string $amount): void
    {
        $limit = \App\Core\Support\Settlement::limit(app(\App\Core\Services\SettingsService::class));

        if ($kind === 'rounding' && bccomp($amount, $limit, 4) > 0) {
            throw ValidationException::withMessages(['difference.amount' => __('accounts::validation.difference_over_limit', [
                'amount' => Money::format($amount), 'limit' => Money::format($limit),
            ])]);
        }
    }

    public function stopping(Voucher $voucher): ?Approval
    {
        // ⭐ কম-বেশির মালিকের সই আগে — ভাউচারের নিজের ছক বন্ধ থাকলেও (cb-ma-5; [[differenceStopping()]])
        $held = $this->differenceStopping($voucher);

        if ($held !== null) {
            return $held;
        }

        /*
         * ⭐ কাউন্টারের ডিপোজিট নিজের নিয়মে — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর।
         *
         * ⓘ *"কাউন্টারের জন্য আলাদা নিয়ম, বাকিগুলো আলাদা।"* ⚠️ না করলে
         * কাউন্টারে সই চাইতে গিয়ে হিসাবের প্রতিটা হাতে লেখা রসিদ আটকাত।
         */
        /*
         * ⭐ নিজের বাক্সে নগদ — সই লাগে না, ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ মালিকের নিয়ম ─────────────────────────────────────────
         * *"bank mfs e gele approval e asbe, cash e sudu tar nijer cash
         * accounts e taka nite parbe tai app er dorkar nai — din sese
         * emnite tar kachtekei buje nibe"*।
         *
         * ── ⚠️ ছাড়টা একা দাঁড়ায় না ────────────────────────────────
         * এর শর্ত [[VoucherService::assertCashLandsInOwnTill()]]: নগদ
         * কেবল **নিজের নামে বসা বাক্সেই** যেতে পারে। ⛔ ঐ সীমা ছাড়া
         * এই ছাড়টা মানে যে কেউ যেকোনো ক্যাশ খাতে টাকা বসিয়ে দিতে
         * পারত, আর দিন শেষে মেলানোর সময় ধরাই পড়ত না।
         *
         * ⓘ তাই দুইটা একসাথে পড়তে হয়: সীমাটা আগে বসেছে, ছাড়টা পরে।
         *
         * ── ⓘ কেবল রসিদ, আর কেবল নগদ ─────────────────────────────
         * ⚠️ পরিশোধে (টাকা বেরোনো) ছাড় নেই — নিজের বাক্স থেকে টাকা
         * বের করা আর নিজের বাক্সে টাকা নেওয়া এক ঝুঁকি নয়।
         * ⛔ ব্যাংক ও MFS-এ টাকা প্রতিষ্ঠানের খাতে যায়, তাই সেখানে
         * সই আগের মতোই লাগে।
         */
        $action = $this->actionFor($voucher);

        if ($action === null) {
            return null;
        }

        $latest = $this->approvals->latestFor($voucher, $action);

        /*
         * অনুমোদন পাওয়া গেছে — এগোও, আর **নতুন অনুরোধ কোরো না**।
         *
         * ⚠️ `ApprovalEngine::approve()` নিজে কাজটা এগোয় না, কেবল
         * অনুরোধটাকে `approved` করে আর খবর দেয়। অর্থাৎ মানুষটাকে ফিরে
         * এসে আবার "পোস্ট" চাপতে হয়। ওই দ্বিতীয় চাপে যদি আমরা আবার
         * `request()` ডাকতাম, সে **পুরনো অনুরোধটা pending নয় বলে
         * খুঁজে পেত না** আর একটা নতুন অনুরোধ বানাত — অনুমোদনটা তখন
         * অসীম লুপে পড়ত, আর কেউ কোনোদিন খরচটা পোস্ট করতে পারত না।
         */
        /*
         * ⚠️ …তবে সইটা যে অঙ্কের উপর দেওয়া হয়েছিল, ভাউচারটা এখনো সেই
         * অঙ্কেই আছে কি না সেটা দেখে ([[Approval::covers()]])।
         *
         * ⛔ ২২ সেপ্টেম্বর ২০২৬ পর্যন্ত দেখত না: সইয়ের পর ভাউচারটা খসড়াই
         * থাকে, তাই অঙ্কটা বাড়িয়ে "পোস্ট" চাপলে পুরনো সইটাই ছেড়ে দিত।
         *
         * ⓘ নিয়মটা কোরের [[DocumentApproval]]-এ একবারই লেখা — এই ক্লাসটা
         * ওর যমজ, আর দুই জায়গায় দুই রকম নিয়ম লিখলে পার্থক্যটা **নীরব**
         * হত: ভাউচারের সই টিকে যেত, বাকি সব কাগজের যেত না।
         */
        $superseded = null;

        /*
         * ⭐ কাগজটা সইয়ের দিন যেমন ছিল — তার ছাপ।
         *
         * ── ⛔ এই ফাঁকটা ঠিক টাকার পথেই খোলা ছিল ──────────────
         * ⓘ [[DocumentApproval::stopping()]] ২৪ সেপ্টেম্বর থেকে গোটা
         * কাগজের ছাপ মেলায় (মালিকের সিদ্ধান্ত: *"যেকোনো ঘর
         * বদলালেই"*)। ⚠️ কিন্তু এই যমজ ক্লাসটা কেবল অঙ্ক দেখত।
         *
         * ⛔ ফল: অঙ্ক ঠিক রেখে ভাউচারের খাত, পক্ষ, বা তারিখ
         * বদলে "পোস্ট" চাপলে পুরনো সইটাই চলত — আর সেটা
         * সবচেয়ে খারাপ জায়গা, কারণ ভাউচারেই টাকা নড়ে।
         */
        $hash = app(DocumentFingerprint::class)->of($voucher);

        if ($latest?->status === Approval::APPROVED) {
            if ($latest->stillCovers((string) $voucher->amount, $hash)) {
                return null;
            }

            /*
             * ⭐ পুরনো সইটা কোন অঙ্কের ছিল — নিয়মটা কোরের
             * [[DocumentApproval::stopping()]]-এ একবারই লেখা।
             *
             * ⚠️ অঙ্ক বদলালে নিচে নতুন অনুরোধ বসে, আর সইকারীর ইনবক্সে
             * একই ভাউচার দ্বিতীয়বার আসে। ⛔ কারণ না জানলে সেটা ভুলের
             * মতো দেখায়।
             */
            $superseded = $latest;
        }

        /*
         * প্রত্যাখ্যাত — আর তারপর কাগজটা বদলায়নি।
         *
         * ── কেন এখানে নীরবে আবার অনুরোধ পাঠানো হয় না ────────────────
         * পাঠালে **প্রত্যাখ্যানের কোনো মানে থাকত না**: যিনি "না" শুনলেন
         * তিনি আবার বোতামটা চেপে আবার অনুরোধ পাঠাতেন, আর অনুমোদনকারী
         * একই কাগজ বারবার দেখতেন। ইঞ্জিনের নিজের মন্তব্যেও লেখা —
         * আবার চাইলে অনুরোধটা **নতুন করে** করতে হবে।
         *
         * ── তাহলে আবার চাওয়ার পথ কী ─────────────────────────────────
         * কাগজটা বদলানো। "না" বলার কারণ থাকে (`reject()` কারণ ছাড়া
         * নেয় না), আর কারণটা মেটানো মানেই ভাউচারে হাত দেওয়া। তাই
         * সম্পাদনার পর (`updated_at` সিদ্ধান্তের পরে) নিচের
         * `request()` স্বাভাবিকভাবেই নতুন একটা অনুরোধ বানায়।
         */
        /*
         * ⭐ অপেক্ষার অনুরোধ আগের কাগজের হলে তুলে নিয়ে নতুন করে — লাইভ, ADI RCV-0090 (১৩–১৪ অক্টোবর ২০২৬; [[AnEditedWaitingPaperIsAskedAgainTest]])।
         * ⛔ আগে অপেক্ষার অনুরোধটাই ফিরত: ৩৯,৯৯০-এ চাওয়া সই, অথচ খসড়া তখন ২৪,৯১৩ নতুন বিবরণে — সইকারী পুরনো অঙ্ক দেখে নতুন কাগজে সই
         * দিতেন। ⓘ এখন পুরনোটা "বাতিল" ([[ApprovalEngine::withdrawStale()]]), নতুনটা নতুন অঙ্কে আর পুরনোটার কথা বলে; পুরনোয় সই আর পড়ে না।
         */
        if ($latest?->status === Approval::PENDING && $this->isStale($latest, $hash)) {
            $this->approvals->withdrawStale($latest);
            $superseded = $latest;
        }

        // ⓘ সংশোধনে ফেরত — লেখক আবার পাঠালেন: নতুন অনুরোধ, পুরনোটার কথাসহ ([[ApprovalEngine::sendBack()]])
        if ($latest?->status === Approval::RETURNED) {
            $superseded = $latest;
        }

        if ($latest?->status === Approval::REJECTED && ! $this->changedSince($voucher, $latest)) {
            return $latest;
        }

        /*
         * এখানে `request()` নিজেই তিনটা সিদ্ধান্ত নেয়:
         *   প্রবাহ ঘোষিত নেই / অঙ্ক সীমার নিচে → null → এগোও
         *   আগের একটা অনুরোধ এখনো pending      → সেটাই ফেরে, নতুন নয়
         *   নাহলে                              → নতুন অনুরোধ
         */
        return $this->approvals->request(
            document: $voucher,
            module: self::MODULE,
            action: $action,
            amount: (string) $voucher->amount,

            /*
             * ⓘ ছাপটা এখানেও যায় — নাহলে পরে মেলানোর কিছু থাকত না।
             * ⚠️ অর্থাৎ উপরের `stillCovers()` সবসময় *"হাঁ, ঢাকে"* বলত
             * (`state_hash` খালি মানে পুরনো আচরণ), আর দাবিটা সবুজ
             * থাকত — কারণ সে কখনো দেখতেই যেত না।
             */
            stateHash: $hash,

            /*
             * ⭐ শর্ত মাপা হয় গোটা ভাউচার দিয়ে।
             *
             * ⛔ এটা না দিলে `payload` মাপা হত, আর সেটা এখানে খালি —
             * অর্থাৎ হিসাবের কোনো কাজে শর্ত বসালে প্রবাহটা **কখনো
             * ধরত না**, আর অনুমোদন নীরবে উঠে যেত।
             *
             * ⓘ নিয়মটা [[ApprovalEngine::fieldsOf()]]-এ, এই ফাইলে নয় —
             * দুই যমজে দুই নিয়ম হলে পার্থক্যটা নীরব হত।
             */
            matchOn: ApprovalEngine::fieldsOf($voucher),

            // ⓘ কারণসহ [[DocumentApproval::stopping()]]-এ — ঘরটা এতদিন খালি পড়ে ছিল।
            payload: $superseded === null ? null : [
                'supersedes' => (int) $superseded->id,
                'was_amount' => $superseded->amount === null ? null : (string) $superseded->amount,
            ],

            /*
             * ⚠️ কে চাইছেন — `auth()->id()`-র উপর ছেড়ে দেওয়া যায় না।
             *
             * `ApprovalEngine::request()` কিছু না পেলে `auth()->id()`
             * নেয়, আর `approvals.requested_by` **null নিতে পারে না**।
             * অর্থাৎ লগইন করা কেউ না থাকলে সারিটা বসতেই পারত না, আর
             * পুরো পোস্টিং একটা ডাটাবেস ত্রুটিতে ভেঙে পড়ত।
             *
             * ── কখন লগইন করা কেউ থাকে না ────────────────────────────
             * কমান্ড লাইন, সিডার, কিউ ওয়ার্কার, ইমপোর্ট — সব জায়গায়।
             * ওগুলোতেও ভাউচার পোস্ট হয়, আর তখন "কে চাইলেন" প্রশ্নের
             * উত্তর একটাই সৎ উত্তর: **যিনি কাগজটা লিখেছিলেন**।
             *
             * ⓘ টেস্টে ধরা পড়েছে (৩ সেপ্টেম্বর ২০২৬), কিন্তু ফাঁদটা
             * টেস্টের নয় — লাইভেও রাতের কোনো কাজ খরচ পোস্ট করতে গেলে
             * ঠিক এখানেই ভাঙত।
             */
            userId: auth()->id() ?? $voucher->created_by,
        );
    }

    /**
     * কোন ছকে সই চাওয়া হয় — `null` মানে এই ভাউচারে সই লাগেই না (নিজের বাক্সে নগদ রসিদ)।
     *
     * ⓘ [[stopping()]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬) — "পোস্ট"-এর আগের সারাংশও ([[VoucherOverview]]) একই প্রশ্ন
     * করে, আর দুই জায়গায় দুই উত্তর হলে সারাংশ "সই লাগবে না" বলত অথচ পোস্ট আটকে যেত।
     */
    public function actionFor(Voucher $voucher): ?string
    {
        if ($voucher->type === Voucher::RECEIPT && $this->landsInCash($voucher)) {
            return null;
        }

        /*
         * ⭐ যে কাগজের বিপরীতে ভাউচার, সেটা আগেই শেষ সই পেয়েছে — আবার সই নয় (কর্মীর খরচের দাবি, মালিকের আদেশ ৭ অক্টোবর
         * ২০২৬; [[SignedBeforeItIsPaid]])। ⓘ সই একবার, কাগজে; খসড়া ভাউচার কেবল টাকা দেওয়ার নির্দেশ।
         */
        if ($this->paperAlreadySigned($voucher)) {
            return null;
        }

        return match (true) {
            // ⓘ ২০ সেপ্টেম্বর: ক্রয়ের কাউন্টারের পরিশোধও কাউন্টারের নিজের ছকে
            $voucher->origin === Voucher::ORIGIN_COUNTER && $voucher->type === Voucher::PAYMENT => self::COUNTER_PAYMENT,

            $voucher->origin === Voucher::ORIGIN_COUNTER => self::COUNTER_DEPOSIT,

            default => (string) $voucher->type,
        };
    }

    private function paperAlreadySigned(Voucher $voucher): bool
    {
        $type = (string) ($voucher->against_type ?? '');

        if ($type === '' || (int) ($voucher->against_id ?? 0) <= 0) {
            return false;
        }

        // ⓘ তালিকা একসাথে ভাগ করার সময় কাগজগুলো আগেই তোলা ([[primeSignedPapers()]]) — সারি ধরে প্রশ্ন নয়
        $known = $this->signedPapers[$type.':'.(int) $voucher->against_id] ?? null;

        if ($known !== null) {
            return $known;
        }

        $class = app(DrillResolver::class)->map()[$type] ?? null;

        if ($class === null || ! is_subclass_of($class, \App\Core\Contracts\SignedBeforeItIsPaid::class)) {
            return false;
        }

        $paper = $class::query()->find((int) $voucher->against_id);

        return $paper instanceof \App\Core\Contracts\SignedBeforeItIsPaid && $paper->signedBeforeItIsPaid();
    }

    /**
     * ⭐ লেখার আগেই — এই কাগজ পোস্টে সইয়ের অপেক্ষায় যাবে কি না (ফোনের preview আর ওয়েবের simulate-এর `needs_signature`; a4-এর
     * "সইয়ের অপেক্ষায় হবে" চিপ, ২৫ অক্টোবর ২০২৬)।
     *
     * ⓘ পোস্টের নিজের প্রশ্ন, একই ক্রমে: [[actionFor()]] (নিজের বাক্সে নগদ রসিদ, আগে সই-পাওয়া কাগজ, কাউন্টার) তারপর
     * [[ApprovalEngine::requires()]] — অঙ্ক সেটাই যা লেখক পোস্টের সময় ভাউচারে থাকবে: সারির ডেবিটের যোগ (লেখার সময় সেবা `amount`
     * তাই বসায়; পরিশোধে মোট + চার্জ)। ⛔ আলাদা হিসাব করলে চিপ এক কথা বলত আর পোস্ট আরেক কাজ করত। কিছুই লেখে না।
     *
     * @param  array<string, mixed>  $header  যাচাই-করা মাথা (`type` সহ)
     * @param  list<array<string, mixed>>  $lines  [[VoucherWriter::linesFor()]]-এর সারি
     */
    public function wouldWait(array $header, array $lines): bool
    {
        $accounts = \App\Modules\Accounts\Models\Account::query()
            ->whereKey(array_map(fn (array $l) => (int) ($l['account_id'] ?? 0), $lines))->get()->keyBy('id');

        $voucher = (new Voucher)->forceFill(array_intersect_key($header, array_flip((new Voucher)->getFillable())));
        $voucher->setAttribute('type', (string) ($header['type'] ?? ''));
        $voucher->setRelation('lines', collect($lines)->map(fn (array $l) => (new VoucherLine)->forceFill([
            'account_id' => (int) ($l['account_id'] ?? 0),
            'debit' => (string) ($l['debit'] ?? '0'),
            'credit' => (string) ($l['credit'] ?? '0'),
        ])->setRelation('account', $accounts->get((int) ($l['account_id'] ?? 0)))));
        $voucher->setAttribute('amount', $voucher->totals()['debit']);

        // ⭐ কম-বেশিতে মালিকের সই — ভাউচারের নিজের ছক বন্ধ থাকলেও ([[differenceStopping()]]-এর একই প্রশ্ন; cb-ma-5)
        [$kind, $difference] = $this->differenceOf($voucher);

        if (\App\Core\Support\Settlement::differenceNeedsOwner($kind, $difference)) {
            return true;
        }

        $action = $this->actionFor($voucher);

        return $action !== null && $this->approvals->requires(self::MODULE, $action, (string) $voucher->amount,
            class_basename(Voucher::class), ApprovalEngine::fieldsOf($voucher));
    }

    /**
     * ⭐ "পোস্ট" চাপলে সই কী বলবে — কিছু না লিখে (৪ অক্টোবর ২০২৬; [[VoucherOverview]])।
     *
     * ⓘ [[stopping()]]-এর একই ধাপ, একই ক্রমে; কেবল শেষের `request()`-এর বদলে [[ApprovalEngine::requires()]] — কারণ
     * `request()` অনুরোধ লেখে, আর সারাংশ দেখতে গিয়ে সইয়ের সারিতে কাগজ চলে যাওয়া চলে না।
     *
     * ⓘ ২৪ অক্টোবর ২০২৬ থেকে উত্তর পাঁচ রকম ([[AWAITING]] … [[REJECTED]]) — আগে বাসি অনুরোধ আর "অনুরোধই হয়নি" দুটোই 'awaiting'
     * বলত, অথচ তালিকার মোট ওদের খসড়ায় গোনে; এখন সবাই [[decide()]] পড়ে, তাই পাতা আর তালিকা এক কথা বলে।
     *
     * @return 'signed'|'rejected'|'awaiting'|'stale'|'needs'|null  `null` = সই লাগে না
     */
    public function waitFor(Voucher $voucher): ?string
    {
        $action = $this->actionFor($voucher);

        if ($action === null) {
            return null;
        }

        $latest = $this->approvals->latestFor($voucher, $action);
        $hash = $latest?->state_hash === null ? null : app(DocumentFingerprint::class)->of($voucher);

        return $this->decide($voucher, $action, $latest, $hash, true);
    }

    /**
     * ⭐ অনেক ভাউচারের সইয়ের অবস্থা একসাথে — [[waitFor()]]-এর একই উত্তর, সারি ধরে প্রশ্ন ছাড়া (তালিকা, ফোন, তালিকার মোট)।
     *
     * ⓘ একসাথে তোলা: সারি আর খাত ([[landsInCash()]]-এর জন্য), বিপরীতের কাগজ (প্রতিটা ধরনে এক প্রশ্ন), শেষ অনুরোধগুলো (এক
     * প্রশ্ন), আর যার ছাপ লাগে তার ছাপ ([[DocumentFingerprint::ofMany()]])। ডাকার জন ৫০০-র মতো করে দেয় ([[ListSummary::CHUNK]])।
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @param  bool  $withNeeds  false হলে "অনুরোধ হয়নি"-র ছক দেখা হয় না — needs আর null দুটোই খসড়া, তাই মোটের কাজে লাগে না
     * @return array<int, ?string>  ভাউচারের আইডি => অবস্থা
     */
    public function signingOf(Collection $vouchers, bool $withNeeds = true): array
    {
        if ($vouchers->isEmpty()) {
            return [];
        }

        $vouchers->loadMissing('lines.account');
        $this->primeSignedPapers($vouchers);

        try {
            $actions = [];

            foreach ($vouchers as $voucher) {
                $actions[(int) $voucher->id] = $this->actionFor($voucher);
            }

            $latest = $this->latestOf(array_map('intval', $vouchers->modelKeys()));
            $hashed = $vouchers->filter(function (Voucher $v) use ($actions, $latest) {
                $action = $actions[(int) $v->id];

                return $action !== null && ($latest[(int) $v->id][$action] ?? null)?->state_hash !== null;
            });
            $hashes = app(DocumentFingerprint::class)->ofMany($hashed);

            $out = [];

            foreach ($vouchers as $voucher) {
                $id = (int) $voucher->id;
                $action = $actions[$id];
                $out[$id] = $action === null
                    ? null
                    : $this->decide($voucher, $action, $latest[$id][$action] ?? null, $hashes[$id] ?? null, $withNeeds);
            }

            return $out;
        } finally {
            $this->signedPapers = null;
        }
    }

    /**
     * ⭐ PENDING অনুরোধের খসড়াগুলো — টাটকা আর বাসি আলাদা ([[decide()]]); কোম্পানির প্রতিটা "সইয়ের অপেক্ষায়" গোনা এটাই পড়ে
     * (তালিকা, ফোন, ড্যাশবোর্ড, পোস্টিং মনিটর, খরচের পাতা, বিমার দাবি, মাস-শেষ; fe, ২৪ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে প্রত্যেকে নিজে `pending()` গুনত — বাসি অনুরোধও "অপেক্ষা", অথচ পরের পোস্টে সেটা তুলে নতুন চাওয়া হয় ([[stopping()]])।
     * ⓘ আইডি কেবল — শাখার দেয়াল ডাকার জনের নিজের কোয়েরিতে (কেউ দেয়ালসহ গোনে, কেউ সব শাখা); প্রার্থী ৫০০ করে, একসাথে ভাগ।
     *
     * @param  string|null  $action  কেবল এই কাজের অনুরোধ (খরচ, রসিদ…); null = সব
     * @return array{awaiting: list<int>, stale: list<int>}
     */
    public function waitingIds(?string $action = null): array
    {
        $candidates = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('module', self::MODULE)
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->where('status', Approval::PENDING)
            ->pluck('approvable_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $out = [self::AWAITING => [], self::STALE => []];

        foreach (array_chunk($candidates, ListSummary::CHUNK) as $ids) {
            $drafts = Voucher::query()->withoutGlobalScope('user-branch')->whereKey($ids)->where('status', \App\Core\Support\DocumentStatus::DRAFT)->get();

            foreach ($this->signingOf($drafts, false) as $id => $signing) {
                if (isset($out[$signing])) {
                    $out[$signing][] = $id;
                }
            }
        }

        return ['awaiting' => $out[self::AWAITING], 'stale' => $out[self::STALE]];
    }

    public function groups(): array
    {
        return [ListSummary::PENDING, ListSummary::DRAFT, ListSummary::REJECTED];
    }

    /** তালিকার মোটের দল ([[ClassifiesDrafts]]) — [[signingOf()]]-এর উত্তর থেকে, [[GROUP_OF]] দিয়ে */
    public function standing(Collection $drafts): array
    {
        return array_map(fn (?string $signing) => self::GROUP_OF[$signing ?? ''] ?? ListSummary::DRAFT, $this->signingOf($drafts, false));
    }

    /**
     * ⭐ এক ভাউচারের সইয়ের অবস্থা — [[waitFor()]] আর [[signingOf()]] দুজনেই এটা ডাকে, তাই দুই পথ কখনো আলাদা কথা বলে না।
     *
     * @param  string|null  $hash  ভাউচারের এখনকার ছাপ — শেষ অনুরোধে ছাপ থাকলে দিতে হয়
     */
    private function decide(Voucher $voucher, string $action, ?Approval $latest, ?string $hash, bool $withNeeds): ?string
    {
        if ($latest?->status === Approval::APPROVED && $latest->stillCovers((string) $voucher->amount, $hash)) {
            return self::SIGNED;
        }

        if ($latest?->status === Approval::REJECTED && ! $this->changedSince($voucher, $latest, $hash)) {
            return self::REJECTED;
        }

        // ⛔ বাসি অনুরোধ অপেক্ষা নয় — পরের পোস্টে [[stopping()]] সেটা তুলে নতুন চায় (RCV-0090, ১৩–১৪ অক্টোবর ২০২৬)
        if ($latest?->status === Approval::PENDING) {
            return $this->isStale($latest, $hash) ? self::STALE : self::AWAITING;
        }

        if (! $withNeeds) {
            return null;
        }

        return $this->approvals->requires(self::MODULE, $action, (string) $voucher->amount, class_basename(Voucher::class),
            ApprovalEngine::fieldsOf($voucher)) ? self::NEEDS : null;
    }

    /** অপেক্ষার অনুরোধটা কি আগের কাগজের — ছাপ না মিললে; ছাপ নেই এমন পুরনো অনুরোধ বাসি নয় (আগের আচরণ) */
    private function isStale(Approval $pending, ?string $hash): bool
    {
        return $pending->state_hash !== null && $hash !== null && ! hash_equals((string) $pending->state_hash, $hash);
    }

    /**
     * শেষ অনুরোধ — ভাউচার আর কাজ ধরে, এক প্রশ্নে ([[ApprovalEngine::latestFor()]]-এর একই নিয়ম: সবচেয়ে বড় আইডি)।
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, Approval>>
     */
    private function latestOf(array $ids): array
    {
        $out = [];

        foreach (Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', $ids)->orderByDesc('id')->get() as $approval) {
            $out[(int) $approval->approvable_id][(string) $approval->action] ??= $approval;
        }

        return $out;
    }

    /**
     * বিপরীতের কাগজ শেষ সই পেয়েছে কি — প্রতিটা ধরনের কাগজ এক প্রশ্নে, [[paperAlreadySigned()]]-এর একই নিয়মে।
     *
     * @param  Collection<int, Voucher>  $vouchers
     */
    private function primeSignedPapers(Collection $vouchers): void
    {
        $map = app(DrillResolver::class)->map();
        $this->signedPapers = [];

        $against = $vouchers->filter(fn (Voucher $v) => (string) ($v->against_type ?? '') !== '' && (int) ($v->against_id ?? 0) > 0)
            ->groupBy(fn (Voucher $v) => (string) $v->against_type);

        foreach ($against as $type => $group) {
            $class = $map[$type] ?? null;

            if ($class === null || ! is_subclass_of($class, SignedBeforeItIsPaid::class)) {
                continue;
            }

            $papers = $class::query()->whereKey($group->map(fn (Voucher $v) => (int) $v->against_id)->unique()->values()->all())
                ->get()->keyBy(fn ($paper) => (int) $paper->getKey());

            foreach ($group as $voucher) {
                $paper = $papers[(int) $voucher->against_id] ?? null;
                $this->signedPapers[$type.':'.(int) $voucher->against_id] = $paper instanceof SignedBeforeItIsPaid && $paper->signedBeforeItIsPaid();
            }
        }
    }

    /**
     * সিদ্ধান্তের পর কাগজটা বদলেছে কি?
     *
     * `decided_at` না থাকলে (তাত্ত্বিকভাবে সম্ভব) ধরে নেওয়া হয় বদলায়নি
     * — অর্থাৎ **আটকে থাকাই ডিফল্ট**। উল্টোটা ধরলে একটা অসম্পূর্ণ সারি
     * প্রত্যাখ্যানটাকে নীরবে অকেজো করে দিত।
     */
    private function changedSince(Voucher $voucher, Approval $approval, ?string $hash = null): bool
    {
        /*
         * ⭐ প্রশ্নটা ছাপকে, ঘড়িকে নয় — নিয়মটা
         * [[DocumentApproval::changedSince()]]-এর অবিকল এক।
         *
         * ⛔ `updated_at > decided_at` সেকেন্ডে মাপে, তাই একই সেকেন্ডে
         * ফেরত আর সংশোধন হলে দ্বিতীয় অনুরোধটা নীরবে গিলে ফেলা হত।
         *
         * ⚠️ দুই জায়গায় দুই নিয়ম হলে পার্থক্যটা নীরব হত: ভাউচার
         * আবার পাঠানো যেত, বাকি সব কাগজ যেত না — বা উল্টোটা।
         */
        if ($approval->state_hash !== null) {
            // ⓘ ছাপ আগে নেওয়া থাকলে সেটাই ([[signingOf()]] একসাথে নেয়) — একই কাগজের একই ছাপ
            return ! hash_equals(
                (string) $approval->state_hash,
                $hash ?? app(DocumentFingerprint::class)->of($voucher),
            );
        }

        if ($approval->decided_at === null || $voucher->updated_at === null) {
            return false;
        }

        return $voucher->updated_at->greaterThan($approval->decided_at);
    }

    /**
     * টাকাটা কি নগদে নামছে?
     *
     * ⓘ সারিগুলো দেখা হয়, হেডারের `money_account_id` নয় — ওটা বসে
     * পোস্ট করার **সময়**, আর অনুমোদনের প্রশ্নটা তারও আগে ওঠে।
     * ⚠️ হেডার দেখলে উত্তরটা সবসময় "না" আসত, আর ছাড়টা কোনোদিন
     * খাটত না।
     */
    private function landsInCash(Voucher $voucher): bool
    {
        /*
         * ⭐ নগদ কেবল **ঢুকছে** — গ৩, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬।
         *
         * ⛔ আগে কোনো একটা সারি নগদে পড়লেই ছাড় মিলত। তাই রসিদের নামে "নিজের বাক্স থেকে সরবরাহকারীকে" লিখলে
         * আসলে টাকা বেরোত, অথচ সই লাগত না; এক বাক্স থেকে আরেক বাক্সেও তাই।
         *
         * ⓘ এখন কোনো নগদ সারিতে ক্রেডিট নয় (টাকা কেবল ঢোকে), আর প্রতিটা বাক্সই এই মানুষটার
         * নিজের ([[CashTill::mayUse()]] — পোস্টের পাহারার সাথে একই নিয়ম)।
         */
        // ⓘ খাত একবারে — সারি প্রতি একটা প্রশ্ন নয়; লোকাল পরিবেশে অলস-লোড বন্ধ, তাই এখানে রসিদ পোস্টই ৫০০ দিত (cb, ২৩ অক্টোবর ২০২৬)
        $voucher->loadMissing('lines.account');
        $cash = $voucher->lines->filter(fn (VoucherLine $line) => $line->account !== null && $line->account->isCash());

        if ($cash->isEmpty()) {
            return false;
        }

        $userId = auth()->id() === null ? null : (int) auth()->id();

        return $cash->every(fn (VoucherLine $line) => bccomp((string) $line->credit, '0', 4) === 0
            && CashTill::mayUse($userId, (int) $line->account_id));
    }
}
