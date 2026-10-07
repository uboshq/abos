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
             */
            if ($attachment !== null) {
                app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::class, $voucher->id);
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
            $this->vouchers->update($voucher, $data, $lines, byHand: true);
            // ⓘ সম্পাদনাতেও একই — নাহলে টিক তুলে নিলে সারিটা থেকে যেত, আর ঐ মালের দামে একটা খরচ বসে থাকত যেটা কেউ আর চায় না
            $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

            if ($attachment !== null) {
                app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::class, $voucher->id);
            }

            if ($asDraft) {
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

        return $this->vouchers->twoLineEntry(
            $type,
            (int) ($input['from_account_id'] ?? 0),
            (int) ($input['to_account_id'] ?? 0),
            (string) ($input['amount'] ?? '0'),
            $input['narration'] ?? null,
            ($input['charge_amount'] ?? '') !== '' ? (string) $input['charge_amount'] : null,
        );
    }
}
