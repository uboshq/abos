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

    /** শেষ সই পড়লে যে কাজগুলো এই মডিউল নিজে শেষ করে */
    public const ACTIONS = [self::NOTE, self::CHEQUE_CLEAR, self::CHEQUE_BOUNCE];

    public function __construct(private readonly DocumentApproval $approval) {}

    /**
     * কাজটা কি সইয়ের জন্য থামবে? `true` — থামল (অনুরোধ বসেছে বা আগেরটা এখনো ঝুলে); `false` — এগোও।
     */
    public function holds(Model $paper, string $action, string $amount, ?string $reason = null): bool
    {
        return $this->approval->stopping($paper, self::MODULE, $action, $amount, $reason) !== null;
    }
}
