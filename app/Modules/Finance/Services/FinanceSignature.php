<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ অর্থের প্রতিটা টাকা নড়ায় সই — অডিট গ১, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * হিসাবের ভাউচারের পর্দায় সই লাগত, অথচ একই টাকা অর্থের পর্দা দিয়ে নড়লে লাগত না: হাতধার দেওয়া-ফেরত,
 * জমা খোলা-কিস্তি-মুনাফা-ভাঙা, ভাড়ার জামানত-মাস-বাড়ানো-ফেরত, চলতি ঋণের খোলা বকেয়া, লাভকে মূলধনে নেওয়া —
 * সবগুলো ভাউচার বানিয়ে সাথে সাথে খাতায় বসাত। মালিকের নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক — সই লাগে*
 * (২৭ সেপ্টেম্বর ২০২৬; কেবল POS-এর নগদ ছাড়)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ভাউচার খসড়া হয়, তারপর কোম্পানির ছক জিজ্ঞেস করা হয় ([[DocumentApproval::stopping()]])। ছক না থাকলে
 * আগের মতো সাথে সাথে খাতায় — সেটা নকশা, কোম্পানি নিজে ছক বসায়। ছক থাকলে খসড়া থাকে, আর শেষ সই পড়লে
 * [[FinishTheFinancePaperOnTheLastSignature]] খাতায় বসায়; "না" হলে বাতিল।
 *
 * ⓘ কাজের নামগুলো `module.php`-র `approvals` আর `moves_money`-তে একই বানানে — নতুন কোম্পানিতে ছকগুলো
 * নিজে বসে ([[MoneyFlowDefaults]])।
 */
final class FinanceSignature
{
    public const MODULE = 'finance';

    /** হাতধার দেওয়া আর ফেরত */
    public const HAND_LOAN = 'hand_loan';

    /** জমা — খোলা, কিস্তি, মুনাফা তোলা, ভাঙা */
    public const DEPOSIT = 'deposit';

    /** ভাড়া — জামানত দেওয়া, বাড়ানো, মাসের সমন্বয়, ফেরত */
    public const RENTAL = 'rental';

    /** চলতি ঋণের খোলা বকেয়া খাতায় তোলা */
    public const BANK_FACILITY = 'bank_facility';

    /** বছর শেষে না-তোলা লাভ মূলধনে */
    public const CAPITALISE = 'capitalise';

    /** বীমা দাবি — লিখিত অনুমোদন, টাকা আসা, বন্ধ বা নাকচ (পুনঃঅডিট, ৯ অক্টোবর ২০২৬; [[InsuranceClaimService]]) */
    public const INSURANCE_CLAIM = 'insurance_claim';

    /** শেষ সই-এর শ্রোতা যে কাজগুলো সামলায় — উত্তোলন আর মুনাফা ঘোষণার নিজের পথ আছে */
    public const ACTIONS = [self::HAND_LOAN, self::DEPOSIT, self::RENTAL, self::BANK_FACILITY, self::CAPITALISE, self::INSURANCE_CLAIM];

    public function __construct(
        private readonly DocumentApproval $approval,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * ছক থাকলে খসড়া রেখে সই চাওয়া, না থাকলে সাথে সাথে খাতায়।
     *
     * @return bool `true` মানে সই-এর অপেক্ষায় — খাতা নড়েনি
     */
    public function postOrHold(Voucher $voucher, string $action, string $amount): bool
    {
        if ($this->approval->stopping($voucher, self::MODULE, $action, $amount) !== null) {
            return true;
        }

        $this->vouchers->post($voucher);

        return false;
    }

    /**
     * ⛔ টাকার খাত সত্যিই টাকার খাত — নগদ, ব্যাংক বা MFS, পোস্টযোগ্য আর চালু (অডিট ম২৩, ছ৫)।
     *
     * ⓘ আগে কেবল "দল নয়" দেখা হত, তাই হাতধার, জমা আর উত্তোলন বিক্রয় বা খরচের খাত থেকেও "দেওয়া" যেত —
     * নগদ কমত না, অথচ ধার বা জমা বাড়ত। ভাড়ার পর্দা ২ অক্টোবর থেকে এই নিয়ম মানে
     * ([[RentalContractService::moneyAccountId()]]); এখন সবাই এক জায়গা থেকে।
     */
    public function moneyAccount(mixed $id, string $field = 'money_account_id'): Account
    {
        $account = $id === null || $id === '' ? null
            : Account::query()->money()->postable()->active()->find($id);

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => __('finance::validation.not_a_money_account'),
            ]);
        }

        return $account;
    }
}
