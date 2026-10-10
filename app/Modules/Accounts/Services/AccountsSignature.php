<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Approval\DocumentApproval;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ হিসাবের যে কাগজগুলো ভাউচার ছাড়াই খাতায় টাকা নাড়ায়, তাদের সই — গ১, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ ভাউচারের পর্দায় সই লাগত, কিন্তু একই টাকা অন্য পর্দা দিয়ে নড়লে লাগত না — ক্রেডিট/ডেবিট নোট, চেক পাশ ও ফেরত,
 * খোলা জেরসহ নতুন ক্যাশবাক্স, আন্তঃকোম্পানি, স্থায়ী সম্পদ। মালিকের নিয়ম "যেকোনো টাকা, যেকোনো অঙ্কে সই" ওখানে ভাঙত।
 *
 * ⓘ ছাঁচ Finance-এর [[FinanceSignature]]-এর হুবহু: কাগজটা খসড়া থাকে, সই চাওয়া হয়, শেষ সই পড়লে
 * [[FinishTheAccountsPaperOnTheLastSignature]] কাজটা শেষ করে; ফেরত দিলে কাগজটা খসড়াই থাকে।
 *
 * ⓘ কোম্পানির ছকে এই কাজের সই বন্ধ থাকলে (UB-তে সব বন্ধ) [[DocumentApproval::stopping()]] `null` ফেরায়, আর কাজটা
 * আগের মতোই সাথে সাথে হয় — এই ফাইল কারও আজকের কাজ থামায় না।
 */
final class AccountsSignature
{
    public const MODULE = 'accounts';

    /** ক্রেডিট বা ডেবিট নোট পাকা করা */
    public const NOTE = 'note';

    /** চেক পাশ — টাকা ব্যাংকে ওঠে */
    public const CHEQUE_CLEAR = 'cheque_clear';

    /** চেক ফেরত — পক্ষের খাতায় টাকা ফেরে */
    public const CHEQUE_BOUNCE = 'cheque_bounce';

    /** আন্তঃকোম্পানি লেনদেন — দুই কোম্পানির খাতায় একসাথে */
    public const INTER_COMPANY = 'inter_company';

    /** খোলা জেরসহ নতুন ক্যাশবাক্স — জেরটা খাতায় ওঠা */
    public const TILL_OPENING = 'till_opening';

    /** স্থায়ী সম্পদ নিবন্ধন — টাকার উৎস থাকলে (নগদ, ব্যাংক, দেনা, ব্যক্তি) */
    public const FIXED_ASSET_REGISTER = 'fixed_asset_register';

    /** স্থায়ী সম্পদ বিক্রি বা বাতিল */
    public const FIXED_ASSET_DISPOSE = 'fixed_asset_dispose';

    // ⭐ ক্যাশবাক্সের দায়িত্ব হস্তান্তর — টাকার দায় বদলায় (Accounts-Finance অডিট ম৮; [[TillHandoverService]])
    public const TILL_HANDOVER = 'till_handover';

    /*
     * ⛔ ঋণের টাকা — অডিট (সমন্বয়ক, ১০ অক্টোবর ২০২৬): তোলা, কিস্তি, শোধ আর সুদ সই ছাড়াই খাতায় বসত ([[LoanService]])।
     * ⓘ প্রতিটা নিজের কাজ, কারণ প্রতিটার ঝুঁকি আলাদা — তোলায় টাকা আসে, কিস্তি আর শোধে বেরোয়, সুদে দায় বাড়ে।
     */
    public const LOAN_DRAW = 'loan_draw';

    public const LOAN_INSTALMENT = 'loan_instalment';

    public const LOAN_REPAY = 'loan_repay';

    public const LOAN_INTEREST = 'loan_interest';

    /** শেষ সই পড়লে যে কাজগুলো এই মডিউল নিজে শেষ করে */
    public const ACTIONS = [
        self::NOTE, self::CHEQUE_CLEAR, self::CHEQUE_BOUNCE, self::INTER_COMPANY, self::TILL_OPENING,
        self::FIXED_ASSET_REGISTER, self::FIXED_ASSET_DISPOSE, self::TILL_HANDOVER,
        self::LOAN_DRAW, self::LOAN_INSTALMENT, self::LOAN_REPAY, self::LOAN_INTEREST,
    ];

    public function __construct(private readonly DocumentApproval $approval) {}

    /**
     * কাজটা কি সইয়ের জন্য থামবে? `true` — থামল (অনুরোধ বসেছে বা আগেরটা এখনো ঝুলে); `false` — এগোও।
     */
    /**
     * @param  array<string, mixed>  $payload  সই চাওয়ার মুহূর্তের তথ্য — শেষ সইয়ে কাজটা এগুলো দিয়েই শেষ হয়
     */
    public function holds(Model $paper, string $action, string $amount, ?string $reason = null, array $payload = []): bool
    {
        return $this->approval->stopping($paper, self::MODULE, $action, $amount, $reason, payload: $payload) !== null;
    }
}
