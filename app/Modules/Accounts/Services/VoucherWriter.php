<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Models\Approval;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ হাতে লেখা ভাউচারের এক পথ — লেখা, খসড়া রাখা, সই, "লেখক ≠ পাকাকারী", পাকা করা (মালিক, ৭ অক্টোবর ২০২৬: ফোনে সব
 * ভাউচার, ওয়েবের একই নিয়মে)।
 *
 * <p>ওয়েবের ভাউচারের পর্দা ([[VoucherController]] — store, update, post), ফোনের সিঙ্ক ([[VoucherSync]]) আর ফোনের দরজা
 * ([[VoucherApiController]]) — তিনটাই এখান দিয়ে যায়, একই ধাপে, একই ক্রমে: এক পথ, দুই পথ নয়। ⚠️ সইয়ের যাচাই ([[VoucherApproval::stopping()]])
 * সেবার ভেতরে নেই (সিডার আর ইমপোর্ট আটকাত) — তাই হাতে লেখার প্রতিটা দরজাকে এই ক্লাস দিয়েই যেতে হবে; সরাসরি
 * `VoucherService::post()` ডাকলে সই এড়ানো হয়।
 */
final class VoucherWriter
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly VoucherApproval $approvals,
    ) {}

    /**
     * লেখা আর (চাইলে) পাকা করা — এক লেনদেনে: পাকা না হলে খসড়াও থাকে না ([[VoucherController::store()]]-এর কারণ)।
     *
     * @param  array<string, mixed>  $data  অনুরোধের যাচাই করা ঘর, `type`-সহ
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: Voucher, 1: bool|'checker'}  দ্বিতীয়টা: false = যেমন চাওয়া হয়েছে; true = সইয়ের অপেক্ষায়;
     *                                              'checker' = লেখক নিজে পাকা করেন না, অন্যের অপেক্ষায়
     */
    public function store(array $data, array $lines, bool $asDraft, ?UploadedFile $attachment = null): array
    {
        return DB::transaction(function () use ($data, $lines, $asDraft, $attachment): array {
            // ⭐ হাতে লেখা — ধরনের ছাঁচ খাটে (ভাউচারের পরিকল্পনা, অংশ ৩ক)
            $voucher = $this->vouchers->create($data, $lines, byHand: true);

            /*
             * ⭐ কোন চালানের ঘাড়ে কতটা — খরচ ভাউচারের ট্যাগ।
             *
             * ⓘ একই লেনদেনে, ভাউচারের সাথেই। ⚠️ আলাদা করলে পোস্টিং
             * আটকালে ভাউচারটা ফিরে যেত কিন্তু ট্যাগগুলো পড়ে থাকত —
             * অনাথ সারি, যেগুলোর ভাউচারই নেই।
             */
            $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

            /*
             * সংযুক্তি — বিলের ছবি বা স্ক্যান।
             *
             * ⛔ ইঞ্জিনটা নিজের ব্যতিক্রম ছোড়ে (আকার, ধরন, ভাঙা ছবি),
             * আর সেটা এখানে ধরা হয় **না**: লেনদেনটা তখন ফিরে যায়, আর
             * ব্যবহারকারী কারণটা দেখেন। ⚠️ চুপচাপ গিলে ফেললে ভাউচারটা
             * সেভ হত, ছবিটা হত না, আর কেউ জানত না।
             *
             * ⛔ ধরনের নাম `'voucher'` ([[Voucher::drillSourceType()]]), ক্লাসের নাম নয় — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর
             * ২০২৬। ⓘ কাগজের তালিকা, সইয়ের পাতা আর নামানোর দরজা সবাই `'voucher'` খোঁজে; ক্লাসের নামে রাখা বিল কোথাও দেখা যেত
             * না, আর নামাতে গেলে ৪০৪। (আগের সারিগুলো ক্লাসের নামেই রইল — ওগুলো সরানো মালিকের প্রশ্ন।)
             */
            if ($attachment !== null) {
                app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::drillSourceType(), $voucher->id);
            }

            if ($asDraft) {
                return [$voucher, false];
            }

            /*
             * অনুমোদন লাগলে খসড়াই থাকে, আর অনুরোধটা এখানেই যায়।
             *
             * ---- কেন এখানেও, শুধু post() রুটে নয় (৩ সেপ্টেম্বর ২০২৬) ----
             * উপরের নিয়ম অনুযায়ী "সেভ করলেই পোস্ট" -- অর্থাৎ খরচ লেখার
             * **স্বাভাবিক পথটা এই লাইনটাই**, `post()` রুট নয় (ওটায়
             * যাওয়া হয় কেবল খসড়া পরে বসাতে)। এখানে শর্তটা না বসালে
             * অনুমোদনের ছক বসানো থাকা সত্ত্বেও রোজকার খরচগুলো নীরবে
             * সরাসরি খতিয়ানে বসে যেত, আর ছকটা কেবল একটা কম-ব্যবহৃত
             * দরজাতেই কাজ করত -- সবচেয়ে খারাপ ধরনের আধা-পাহারা।
             */
            if ($this->approvals->stopping($voucher) !== null) {
                return [$voucher, true];
            }

            // ⭐ লেখক ≠ পাকাকারী (অংশ ৩গ)
            if ($this->vouchers->writerMayNotPost($voucher)) {
                return [$voucher, 'checker'];
            }

            $this->vouchers->post($voucher, byHand: true);

            return [$voucher, false];
        });
    }

    /**
     * খসড়া বদলে আবার লেখা — লেখার একই শর্তে: সই আটকালে অপেক্ষায়, লেখক নিজে হলে অন্যের অপেক্ষায় ('checker'), নইলে পাকা।
     * ⓘ সম্পাদনার পরেও একই শর্ত — নাহলে একবার খসড়া রেখে তারপর সম্পাদনা করে পোস্ট করলেই পাহারাটা এড়ানো যেত।
     * ⓘ এক লেনদেনে, [[store()]]-এর মতো — পাকা না হলে বদলও ফিরে যায় (আগে ওয়েবে বদলটা থেকে যেত, অথচ ভুলবার্তা আসত)।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return bool|'checker'
     */
    public function update(Voucher $voucher, array $data, array $lines, bool $asDraft, ?UploadedFile $attachment = null): bool|string
    {
        return DB::transaction(function () use ($voucher, $data, $lines, $asDraft, $attachment): bool|string {
            // ⛔ অন্য কাগজের সইয়ে বাঁধা খসড়া এখান থেকে বদলায় না — সই যা দেখেছে খাতায় তাই ([[VoucherService::assertNotSignedElsewhere()]])
            $this->vouchers->assertNotSignedElsewhere($voucher);

            /*
             * ⭐ যিনি বদলাচ্ছেন তিনি নিজেই এই ভাউচারের অপেক্ষার সইয়ের সইকারী — মালিকের আদেশ, ১৪ অক্টোবর ২০২৬ (কাজের ভাগ)।
             * ⓘ মালিক বদলে সই দিতে পারেন; অন্য সইকারী কেবল বিবরণ ঠিক করতে পারেন। দুই পথেই অনুরোধটা থাকে, ছাপ বদলানো কাগজের,
             * প্রতিটা বদল নিরীক্ষায় আর লেখক খবর পান ([[signerEdits()]])। লেখকের নিজের বদলে আগের মতো পুরনো অনুরোধ উঠে নতুন করে চাওয়া হয়।
             */
            $signing = $this->signersOwnRequest($voucher);

            if ($signing !== null) {
                return $this->signerEdits($voucher, $signing, $data, $lines, $asDraft, $attachment);
            }

            $this->vouchers->update($voucher, $data, $lines, byHand: true);
            // ⛔ যিনি সারি বদলালেন তিনিও লেখক — নিজে পাকা নন ([[VoucherService::writerMayNotPost()]]; [[WhoeverRewroteAVoucherDoesNotPostItTest]])
            app(\App\Core\Engines\Audit\AuditEngine::class)->recordAction($voucher, VoucherService::EDITED_BY_HAND);
            // ⓘ সম্পাদনাতেও একই — নাহলে টিক তুলে নিলে সারিটা থেকে যেত, আর ঐ মালের দামে একটা খরচ বসে থাকত যেটা কেউ আর চায় না
            $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

            if ($attachment !== null) {
                app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::drillSourceType(), $voucher->id); // ⛔ 'voucher', ক্লাসের নাম নয় (উপরে store()-এ কারণ)
            }

            if ($asDraft) {
                /*
                 * ⓘ খসড়া হিসেবে রাখা হলেও — সইয়ের অপেক্ষা চলছিল তো নতুন কাগজে নতুন করে চাওয়া, পুরনো অনুরোধ তুলে
                 * ([[VoucherApproval::stopping()]]; লাইভ RCV-0090, ১৪ অক্টোবর ২০২৬)। অপেক্ষা না থাকলে আগের মতো কিছুই চাওয়া হয় না।
                 */
                $fresh = $voucher->fresh();
                $action = $this->approvals->actionFor($fresh);
                $waiting = $action !== null && \App\Models\Approval::query()->where('approvable_type', Voucher::class)
                    ->where('approvable_id', $fresh->id)->where('action', $action)->where('status', \App\Models\Approval::PENDING)->exists();

                if ($waiting) {
                    $this->approvals->stopping($fresh);
                }

                return false;
            }

            $fresh = $voucher->fresh();

            if ($this->approvals->stopping($fresh) !== null) {
                return true;
            }

            if ($this->vouchers->writerMayNotPost($fresh)) {
                return 'checker';
            }

            $this->vouchers->post($fresh, byHand: true);

            return false;
        });
    }

    /** এই ভাউচারের অপেক্ষার সই, যদি যিনি বদলাচ্ছেন তিনিই তার সইকারী */
    private function signersOwnRequest(Voucher $voucher): ?Approval
    {
        $user = auth()->user();
        $action = $this->approvals->actionFor($voucher);

        if (! $user instanceof \App\Models\User || $action === null) {
            return null;
        }

        $pending = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)
            ->where('action', $action)->where('status', Approval::PENDING)->latest('id')->first();

        return $pending !== null && app(\App\Core\Engines\Approval\ApprovalEngine::class)->canDecide($pending, $user) ? $pending : null;
    }

    /**
     * ⭐ সইকারীর নিজের বদল — মালিক যেকোনো ঘর; অন্য সইকারী কেবল বিবরণ (মাথার আর সারির) আর লেনদেন নম্বর
     * ([[ASignerSendsBackOrCorrectsOnlyTheTextTest]])।
     *
     * ⛔ অঙ্ক, খাত, পক্ষ, তারিখ, সারি — যে বদলালে খাতার মানে বদলায় — মালিক ছাড়া কেউ সইয়ের হাতে বদলাতে পারেন না: সইকারী নিজে
     * কাগজ বানিয়ে নিজে সই দিলে কাজের ভাগ থাকে না। তিনি "সংশোধনে ফেরত" পাঠান ([[ApprovalEngine::sendBack()]])।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    private function signerEdits(Voucher $voucher, Approval $signing, array $data, array $lines, bool $asDraft, ?UploadedFile $attachment): bool
    {
        $user = auth()->user();
        $owner = app(\App\Core\Services\Ownership::class)->isOwnerIn($user, (int) $voucher->company_id);

        $before = $this->picture(Voucher::query()->withoutGlobalScope('user-branch')->with('lines')->findOrFail($voucher->id));

        $this->vouchers->update($voucher, $data, $lines, byHand: true);
        $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

        if ($attachment !== null) {
            app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::drillSourceType(), $voucher->id); // ⛔ 'voucher', ক্লাসের নাম নয় (উপরে store()-এ কারণ)
        }

        $fresh = Voucher::query()->withoutGlobalScope('user-branch')->with('lines')->findOrFail($voucher->id);
        $after = $this->picture($fresh);

        if (! $owner && $before['material'] !== $after['material']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => __('accounts::validation.signer_may_only_correct_text')]);
        }

        $changes = [];

        foreach (array_unique([...array_keys($before['all']), ...array_keys($after['all'])]) as $field) {
            $old = $before['all'][$field] ?? null;
            $new = $after['all'][$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        if ($changes === []) {
            return ! $asDraft;
        }

        app(\App\Core\Engines\Audit\AuditEngine::class)->record($fresh, $owner ? 'edited_by_signer' : 'corrected_by_signer', $changes,
            __('approval::message.signer_changed', ['name' => $user->name, 'fields' => implode(', ', array_keys($changes))]));

        app(\App\Core\Engines\Approval\ApprovalEngine::class)->adoptSignerEdit(
            $signing, $user, app(\App\Core\Engines\Approval\DocumentFingerprint::class)->of($fresh), (string) $fresh->amount,
            __('approval::message.signer_changed', ['name' => $user->name, 'fields' => implode(', ', array_keys($changes))]),
        );

        // ⓘ সইয়ের অপেক্ষা চলছে — সইকারী নিজে সই দেবেন
        return ! $asDraft;
    }

    /**
     * ভাউচারের ছবি: `material` — যা বদলালে খাতার মানে বদলায় (বিবরণ আর লেনদেন নম্বর বাদে, সারির id ছাড়া); `all` — নিরীক্ষার জন্য সব।
     *
     * @return array{material: array<string, mixed>, all: array<string, string|null>}
     */
    private function picture(Voucher $voucher): array
    {
        $text = ['narration', 'instrument_no'];
        $skip = ['id', 'updated_at', 'created_at', 'status', 'public_id', 'row_hash', 'prev_hash'];

        $head = [];

        foreach ($voucher->getAttributes() as $key => $value) {
            if (! in_array($key, $skip, true)) {
                $head[$key] = $value === null ? null : (string) $value;
            }
        }

        $lines = $voucher->lines->map(fn ($l) => [
            'account_id' => (int) $l->account_id,
            'debit' => bcadd((string) $l->debit, '0', 4),
            'credit' => bcadd((string) $l->credit, '0', 4),
            'party_type' => $l->party_type,
            'party_id' => $l->party_id === null ? null : (int) $l->party_id,
            'cost_center_id' => $l->cost_center_id === null ? null : (int) $l->cost_center_id,
        ])->sortBy(fn (array $l) => json_encode($l))->values()->all();

        $notes = $voucher->lines->map(fn ($l) => (string) ($l->narration ?? ''))->sort()->values()->all();

        $material = array_diff_key($head, array_flip($text));
        $material['~lines'] = $lines;

        $all = $head;
        $all['lines'] = json_encode($lines, JSON_UNESCAPED_UNICODE);
        $all['line_narrations'] = json_encode($notes, JSON_UNESCAPED_UNICODE);

        return ['material' => $material, 'all' => $all];
    }

    /**
     * খসড়া পাকা করা — লেনদেন নম্বর কেবল খসড়ায়, তারপর সই, তারপর সেবা।
     *
     * @return Approval|null  সই আটকালে সেই সই (পাকা হয়নি); null = পাকা হয়েছে
     */
    public function post(Voucher $voucher, ?string $instrumentNo = null): ?Approval
    {
        /*
         * ⛔ সারিতে তালা দিয়ে আবার পড়া, এক লেনদেনে ([[EveryMoneyActionLocksItsRowTest]]) — একই খসড়া দুই হাতে একসাথে পাকা
         * করতে চাপলে দুজনেই "খসড়া" দেখতেন। ⓘ পাকা না হলে লেনদেন নম্বরের বদলও ফিরে যায় — ওয়েবের চেয়ে এক ধাপ শক্ত।
         */
        return DB::transaction(function () use ($voucher, $instrumentNo): ?Approval {
            $this->lockFresh($voucher);

            // ⛔ অন্য কাগজের সইয়ে বাঁধা খসড়া এখান থেকে পাকা হয় না — সেই কাগজের নিজের সই এড়ানো যেত ([[VoucherService::assertNotSignedElsewhere()]])
            $this->vouchers->assertNotSignedElsewhere($voucher, posting: true);

            if ($voucher->isDraft() && filled($instrumentNo)) {
                $voucher->forceFill(['instrument_no' => trim((string) $instrumentNo)])->save();
            }


            /*
             * অনুমোদন লাগে কি না — পোস্টের **আগে**, খতিয়ানে কিছু লেখার আগে।
             *
             * ── কেন এখানে, সার্ভিসের ভিতরে নয় ───────────────────────────
             * `VoucherService::post()` ডাকা হয় সিডার, ইমপোর্ট আর অন্য
             * সার্ভিস থেকেও — ওখানে বসালে ডেমো ডেটা বসানোই আটকে যেত, আর
             * ইমপোর্ট করা দুই হাজার সারি অনুমোদনের অপেক্ষায় ঝুলে থাকত।
             * অনুমোদন **মানুষের সিদ্ধান্তের** উপর বসে, যন্ত্রের উপর নয়,
             * আর মানুষ আসে এই দরজা দিয়ে।
             *
             * ⚠️ নিচের `post()` আর তার ক্রম অস্পৃশ্য — এটা কেবল একটা শর্ত
             * তার আগে, যা `null` হলে সবকিছু আজকের মতোই চলে।
             */
            // ⛔ অন্য কাগজের ভাউচার — সইয়ের অনুরোধের আগেই থামে, অকারণ সই চাওয়া নয় ([[VoucherService::refuseIfOwnedElsewhere()]])
            $this->vouchers->refuseIfOwnedElsewhere($voucher);

            $stopping = $this->approvals->stopping($voucher);

            if ($stopping !== null) {
                return $stopping;
            }

            $this->vouchers->post($voucher, byHand: true);

            return null;
        });
    }

    /**
     * সহজ ফর্মের দুই খাত থেকে দুই (চার্জসহ তিন) সারি; জাবেদায় সারিগুলো যেমন এসেছে — [[VoucherController::linesFrom()]]।
     *
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function linesFor(string $type, array $input): array
    {
        if ($type === Voucher::JOURNAL) {
            return array_values((array) ($input['lines'] ?? []));
        }

        $lines = $this->vouchers->twoLineEntry(
            $type,
            (int) ($input['from_account_id'] ?? 0),
            (int) ($input['to_account_id'] ?? 0),
            (string) ($input['amount'] ?? '0'),
            $input['narration'] ?? null,
            ($input['charge_amount'] ?? '') !== '' ? (string) $input['charge_amount'] : null,
        );

        // ⭐ খরচে উৎসে কর্তন খাতায় — খরচ মোটে, টাকা নিটে, কর্তন সরকারের দায়ে (#১, ২০ অক্টোবর ২০২৬; [[WithheldTax]])
        if ($type === Voucher::EXPENSE) {
            return app(WithheldTax::class)->onExpense($lines, (int) ($input['to_account_id'] ?? 0), $input);
        }

        // ⭐ পরিশোধেও — পক্ষের খাত মোটে, টাকা নিটে, কর্তন ২১২১/২১২২-এ (খরচের একই পথ; fe, ২৪ অক্টোবর ২০২৬, নকশা পাতা ৪)
        if ($type === Voucher::PAYMENT) {
            return app(WithheldTax::class)->onPayment($lines, (int) ($input['to_account_id'] ?? 0), $input);
        }

        // ⭐ রসিদে পক্ষ যা কেটে রাখলেন — ১১৩৮ VDS, ১১৩৫ AIT; পক্ষের খাত কমে পুরোটায় (আদায়ের দফা ৫-এর নিয়ম, [[WithheldAtSource]])
        if ($type === Voucher::RECEIPT) {
            return app(WithheldTax::class)->onReceipt($lines, (int) ($input['from_account_id'] ?? 0), $input);
        }

        return $lines;
    }
}
