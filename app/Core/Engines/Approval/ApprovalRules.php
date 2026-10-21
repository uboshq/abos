<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\SummarisesAnApproval;
use App\Core\Module\ModuleRegistry;
use App\Models\Approval;

/**
 * ⭐ মডিউলের `approval_rules` — কাজ ধরে সইকারীর চাবি আর ইনবক্সের সারির লেখা (a6, ২১ অক্টোবর ২০২৬; §৮ ⚠️৫, fe-র উত্তর ২ ও ৪)।
 *
 * ⓘ কোর কাজের নাম জানে না — মডিউল বলে: `'salary_change' => ['signer_key' => 'hr.salary.view', 'summary' => …::class]`।
 *  · `signer_key` — এই কাজের ছকের ধাপে এমন কাউকে (মানুষ বা রোল) বসানো যায় না যাঁর হাতে চাবিটা নেই
 *    ([[ApprovalFlowService::create()]]/`update()`); ⛔ নাহলে সইকারী অঙ্ক না দেখেই সই দিতেন।
 *  · `summary` — [[SummarisesAnApproval]]; ইনবক্সের সারিতে কী বদলাচ্ছে ([[ApprovalFacts::of()]])।
 */
final class ApprovalRules
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * ⛔ এই কাজ একসাথে সইয়ে নয় — প্রতিটা অনুরোধ নিজের পাতায়, নিজের কারণ আর প্রমাণ দেখে, নিজের সইয়ে (fe, ২১ অক্টোবর ২০২৬; a6)।
     *
     * ⓘ মডিউল বলে `'no_bulk' => true` ([[BulkApproval]] পড়ে)। `moves_money`-র বাইরের কাজের জন্য — সেখানে থাকলে [[MoneyFlowDefaults]]
     * প্রতিটা কোম্পানিতে একটা ছক বসাত; এটা কেবল একসাথে সই বন্ধ করে, কোনো ছক বসায় না।
     */
    public function noBulk(string $module, string $action): bool
    {
        return ($this->modules->get($module)?->approvalRules[$action]['no_bulk'] ?? false) === true;
    }

    public function signerKey(string $module, string $action): ?string
    {
        $key = $this->modules->get($module)?->approvalRules[$action]['signer_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * ⛔ এই কাজের অনুরোধের অঙ্ক কোন চাবির পেছনে — থাকলে অঙ্কটা `approvals.amount`-এ **খোলা বসে না** (a6, ২১ অক্টোবর ২০২৬; fe-র
     * উত্তর, বেতনের রান)।
     *
     * ⓘ এক নিয়ম, প্রতিটা দরজা: ইনবক্স, সইয়ের পাতা, একসাথে সইয়ের তালিকা, ফোনের কার্ড, ঘণ্টা আর চিঠির খবর ([[ApprovalEngine::noticeData()]]),
     * অনুমোদনের রিপোর্ট আর তার ফাইল (কাঁচা SQL), ইনবক্সের খোঁজা — সবাই `amount` পড়ে, আর সেটা খালি। অঙ্কটা থাকে `payload`-এ
     * এনক্রিপ্ট করা ([[Approval::SEALED_AMOUNT]]); ইঞ্জিনের হিসাব (সই কোন অঙ্ক ঢাকে, কর্তৃত্বের সীমা) পড়ে [[Approval::signedAmount()]]।
     * চাবি থাকা মানুষ অঙ্কটা দেখেন মডিউলের সারাংশে ([[summaryOf()]]), যেখানে ঢাকনা [[FieldSecurity]] দিয়ে।
     */
    public function amountKey(string $module, string $action): ?string
    {
        $key = $this->modules->get($module)?->approvalRules[$action]['amount_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * ⛔ মালিকের একমাত্র ব্যতিক্রম — "সই বন্ধ মানে বন্ধ", কেবল এই কাজগুলো ছাড়া (মালিক, ২১ অক্টোবর ২০২৬; a6)।
     *
     * ⓘ বেতনের রান নিশ্চিত, বেতনের কাঠামো বদল আর কর্মীর ব্যাংকের ঠিকানা বদল — যে কোম্পানিতে সব ছক বন্ধ (UB), সেখানেও আরেকজনের
     * "সই দিন" লাগে। মডিউল বলে কোন চাবি থাকলে সেই আরেকজন সই দিতে পারেন (`second_hand`); সাথে কাজটার `signer_key` থাকলে সেটাও
     * — নাহলে অঙ্ক না দেখে সই। ⓘ কোম্পানির নিজের চালু ছক থাকলে সেটাই খাটে ([[ApprovalEngine::flows()]]); এটা কেবল ফাঁকটা ভরে।
     *
     * @return list<string> খালি = এই কাজে এমন কোনো নিয়ম নেই
     */
    public function secondHand(string $module, string $action): array
    {
        $rule = $this->modules->get($module)?->approvalRules[$action] ?? [];
        $keys = $rule['second_hand'] ?? [];

        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $signer = $this->signerKey($module, $action);

        return array_values(array_unique(array_map('strval', $signer === null ? $keys : [...$keys, $signer])));
    }

    /**
     * ⓘ যে কাজগুলোতে নিয়মটা আছে — সব মডিউল ধরে।
     *
     * @return list<array{module: string, action: string, keys: list<string>}>
     */
    public function secondHandActions(): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach (array_keys($module->approvalRules) as $action) {
                $keys = $this->secondHand($module->code, (string) $action);

                if ($keys !== []) {
                    $out[] = ['module' => $module->code, 'action' => (string) $action, 'keys' => $keys];
                }
            }
        }

        return $out;
    }

    public function summaryOf(Approval $approval): ?string
    {
        $class = $this->modules->get((string) $approval->module)?->approvalRules[(string) $approval->action]['summary'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, SummarisesAnApproval::class)) {
            return null;
        }

        return app($class)->summary($approval);
    }
}
