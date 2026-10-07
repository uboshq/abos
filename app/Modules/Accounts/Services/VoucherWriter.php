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
 * <p>ওয়েবের [[VoucherController::store()]] আর [[VoucherController::post()]]-এর ধাপ হুবহু, একই ক্রমে — ফোনের সিঙ্ক
 * ([[VoucherSync]]) আর ফোনের দরজা ([[VoucherApiController]]) এখান দিয়েই যায়। ⚠️ সইয়ের যাচাই ([[VoucherApproval::stopping()]])
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
            $this->vouchers->replaceBillShares($voucher, $data['bill_shares'] ?? [], $data['alloc_basis'] ?? 'qty');

            if ($attachment !== null) {
                app(AttachmentEngine::class)->store($attachment, 'accounts', Voucher::class, $voucher->id);
            }

            if ($asDraft) {
                return [$voucher, false];
            }

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
     * খসড়া পাকা করা — [[VoucherController::post()]]-এর হুবহু: লেনদেন নম্বর কেবল খসড়ায়, তারপর সই, তারপর সেবা।
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
