<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সরবরাহকারীর ব্যাংক তথ্য — দুই হাতে (নকশা দফা #৬, মালিক অনুমোদিত, ২১ অক্টোবর ২০২৬; SOX/ITGC maker-checker)।
 *
 * ⛔ সরবরাহকারীকে পরিশোধ-জালিয়াতির সবচেয়ে চেনা পথ: একজন সরবরাহকারীর হিসাব নম্বর নিজের নম্বরে বদলান, আর পরের ব্যাংক
 * পরিশোধ সেখানেই যায়। ⭐ তাই:
 *   - [[request()]] কেবল একটা `pending` সারি লেখে; চালু তথ্য ([[signedFor()]]) বদলায় না।
 *   - [[sign()]] দেন **অন্য একজন** — `supplier.bank.approve` চাবি আর সরবরাহকারীর শাখায় নাগাল লাগে, আর যিনি চেয়েছেন তিনি
 *     কখনো নন, **মালিকও নন** (মালিকের ব্যতিক্রম, ২১ অক্টোবর ২০২৬: বেতন, বেতন-কাঠামো আর ব্যাংক তথ্যের বদলে সবসময় দ্বিতীয়
 *     মানুষ, অনুমোদন বন্ধ থাকা কোম্পানিতেও)। ⓘ তাই [[MakerChecker]]-এর super admin ছাড় এখানে খাটে না।
 *   - সই পড়লে পুরনো `signed` সারি `superseded` হয়, নতুনটা চালু — এক লেনদেনে, সরবরাহকারীর সারিতে তালা দিয়ে।
 *   - [[reject()]] অন্য একজন; [[withdraw()]] যিনি চেয়েছেন। পুরনো চালু তথ্য দুই ক্ষেত্রেই থাকে।
 *   - সই-না-পাওয়া তথ্যে ব্যাংকে পরিশোধ হয় না — পরিশোধের পর্দা [[assertBankPayable()]] ডাকে।
 *
 * ── ⓘ কেন অনুমোদনের ছকের (`approval_flows`) উপর দাঁড়িয়ে নয় ─────────────
 * এই শাখার ভিত্তিতে [[ApprovalEngine]] ছক না পেলে `null` ফেরায় — মানে "এগিয়ে যাও"; আর UB-র মতো ছক-বন্ধ কোম্পানিতে
 * এই সই তবু লাগবে। ইঞ্জিনের "দ্বিতীয় সই" (`second_hand`) a6/hr-law-sign শাখায় আছে, এখানে এখনো নয়; ঐ শাখা main-এ
 * এলে এই সেবা `supplier.bank_change`-কে `second_hand` ঘোষণা করে ইঞ্জিনে চলে যেতে পারে। ততদিন নিয়মটা এখানে, কড়া দিকে।
 *
 * ⓘ প্রতিটা বদল নিরীক্ষায় ([[IsAudited]] — সারি তৈরি আর অবস্থা বদল, আগে/পরে) আর নামসহ কাজ (`auditAction`)।
 */
final class SupplierBank
{
    public const APPROVE_KEY = 'supplier.bank.approve';

    public const VIEW_KEY = 'supplier.bank.view';

    /**
     * চালু, সই-পাওয়া ব্যাংক তথ্য — না থাকলে `null`। ⚠️ কেবল `pending` থাকলেও `null`।
     */
    public function signedFor(Supplier $supplier): ?SupplierBankDetail
    {
        return SupplierBankDetail::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierBankDetail::SIGNED)
            ->latest('signed_at')
            ->latest('id')
            ->first();
    }

    /** সইয়ের অপেক্ষায় থাকা বদল — না থাকলে `null` */
    public function pendingFor(Supplier $supplier): ?SupplierBankDetail
    {
        return SupplierBankDetail::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierBankDetail::PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * ⭐ ব্যাংকে পরিশোধের আগে — চালু সই-পাওয়া তথ্য ফেরায়, না থাকলে থামায়।
     *
     * ⓘ পরিশোধের পর্দা (cb) এটা ডাকে; বার্তা `supplier::bank.unsigned_blocks_payment`, ঘর `payee_bank`।
     *
     * @throws ValidationException
     */
    public function assertBankPayable(Supplier $supplier): SupplierBankDetail
    {
        $signed = $this->signedFor($supplier);

        if ($signed === null) {
            throw ValidationException::withMessages([
                'payee_bank' => __('supplier::bank.unsigned_blocks_payment'),
            ]);
        }

        return $signed;
    }

    /**
     * নতুন ব্যাংক তথ্য চাওয়া — `pending`, চালু তথ্য অবিকল থাকে।
     *
     * ⓘ আগের অপেক্ষার বদল থাকলে সেটা `withdrawn` হয় (একই সরবরাহকারীর দুইটা অপেক্ষার বদল সইকারীকে বিভ্রান্ত করত)।
     *
     * @param  array{bank_name:string, branch_name?:?string, account_name:string, account_no:string, routing_no?:?string, reason?:?string}  $data
     */
    public function request(Supplier $supplier, array $data, User $by): SupplierBankDetail
    {
        $account = preg_replace('/[\s-]+/', '', (string) ($data['account_no'] ?? '')) ?? '';

        if (! preg_match('/^[0-9A-Za-z]{6,34}$/', $account)) {
            throw ValidationException::withMessages(['account_no' => __('supplier::bank.account_no_invalid')]);
        }

        return DB::transaction(function () use ($supplier, $data, $by, $account): SupplierBankDetail {
            Supplier::query()->whereKey($supplier->id)->lockForUpdate()->first();

            SupplierBankDetail::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', SupplierBankDetail::PENDING)
                ->lockForUpdate()
                ->get()
                ->each(function (SupplierBankDetail $older) use ($by): void {
                    $older->update([
                        'status' => SupplierBankDetail::WITHDRAWN,
                        'decided_by' => $by->id,
                        'decided_at' => now(),
                        'decision_note' => 'replaced',
                    ]);
                    $older->auditAction('bank_replaced');
                });

            $detail = SupplierBankDetail::create([
                'company_id' => $supplier->company_id,
                'supplier_id' => $supplier->id,
                'bank_name' => trim((string) $data['bank_name']),
                'branch_name' => filled($data['branch_name'] ?? null) ? trim((string) $data['branch_name']) : null,
                'account_name' => trim((string) $data['account_name']),
                'account_no' => $account,
                'account_last4' => substr($account, -4),
                'routing_no' => filled($data['routing_no'] ?? null) ? trim((string) $data['routing_no']) : null,
                'status' => SupplierBankDetail::PENDING,
                'requested_by' => $by->id,
                'reason' => filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
            ]);

            $detail->auditAction('bank_requested', $detail->reason);

            return $detail;
        });
    }

    /**
     * দ্বিতীয় সই — অন্য একজন; সই পড়লে এটাই চালু, পুরনোটা `superseded`।
     *
     * @throws ValidationException
     */
    public function sign(SupplierBankDetail $detail, User $by): SupplierBankDetail
    {
        return DB::transaction(function () use ($detail, $by): SupplierBankDetail {
            Supplier::query()->whereKey($detail->supplier_id)->lockForUpdate()->first();
            $locked = $this->lockPending($detail);
            $this->assertSecondHand($locked, $by);

            SupplierBankDetail::query()
                ->where('supplier_id', $locked->supplier_id)
                ->where('status', SupplierBankDetail::SIGNED)
                ->whereKeyNot($locked->id)
                ->get()
                ->each(function (SupplierBankDetail $old): void {
                    $old->update(['status' => SupplierBankDetail::SUPERSEDED]);
                });

            $locked->update([
                'status' => SupplierBankDetail::SIGNED,
                'signed_by' => $by->id,
                'signed_at' => now(),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);
            $locked->auditAction('bank_signed');

            return $locked->fresh();
        });
    }

    /**
     * না — অন্য একজন, কারণসহ। পুরনো চালু তথ্য থাকে।
     *
     * @throws ValidationException
     */
    public function reject(SupplierBankDetail $detail, User $by, string $note): SupplierBankDetail
    {
        return DB::transaction(function () use ($detail, $by, $note): SupplierBankDetail {
            $locked = $this->lockPending($detail);
            $this->assertSecondHand($locked, $by);

            $locked->update([
                'status' => SupplierBankDetail::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $locked->auditAction('bank_rejected', $note);

            return $locked->fresh();
        });
    }

    /**
     * ফিরিয়ে নেওয়া — কেবল যিনি চেয়েছেন। পুরনো চালু তথ্য থাকে।
     *
     * @throws ValidationException
     */
    public function withdraw(SupplierBankDetail $detail, User $by): SupplierBankDetail
    {
        return DB::transaction(function () use ($detail, $by): SupplierBankDetail {
            $locked = $this->lockPending($detail);

            if ((int) $locked->requested_by !== (int) $by->id) {
                throw ValidationException::withMessages(['bank' => __('supplier::bank.only_maker_withdraws')]);
            }

            $locked->update([
                'status' => SupplierBankDetail::WITHDRAWN,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);
            $locked->auditAction('bank_withdrawn');

            return $locked->fresh();
        });
    }

    /**
     * ইনি কি সই দিতে পারেন — চাবি, শাখার নাগাল, আর যিনি চেয়েছেন তিনি নন (মালিকও নন)।
     */
    public function canSign(SupplierBankDetail $detail, User $by): bool
    {
        return $detail->isPending()
            && (int) $detail->requested_by !== (int) $by->id
            && $by->can(self::APPROVE_KEY)
            && $detail->supplier !== null
            && $by->can('view', $detail->supplier);
    }

    /**
     * পর্দায় হিসাব নম্বর — `supplier.bank.view` থাকলে পুরো, নাহলে ••••4521।
     */
    public function accountShown(SupplierBankDetail $detail, ?User $viewer): string
    {
        return $viewer !== null && $viewer->can(self::VIEW_KEY) ? $detail->account_no : $detail->maskedAccount();
    }

    private function lockPending(SupplierBankDetail $detail): SupplierBankDetail
    {
        $locked = SupplierBankDetail::query()->whereKey($detail->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $locked->isPending()) {
            throw ValidationException::withMessages(['bank' => __('supplier::bank.not_pending')]);
        }

        return $locked;
    }

    private function assertSecondHand(SupplierBankDetail $detail, User $by): void
    {
        // ⛔ যিনি চেয়েছেন তিনি কখনো নন — মালিকও নন (super admin ছাড় নেই)
        if ((int) $detail->requested_by === (int) $by->id) {
            throw ValidationException::withMessages(['bank' => __('supplier::bank.maker_cannot_sign')]);
        }

        if (! $this->canSign($detail, $by)) {
            throw ValidationException::withMessages(['bank' => __('supplier::bank.no_sign_key')]);
        }
    }
}
