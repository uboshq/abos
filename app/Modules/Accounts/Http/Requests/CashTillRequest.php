<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * নগদ কাউন্টারের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * "কাউন্টারে টাকা থাকলে বন্ধ করা যাবে না" এখানে নয়, CashTillService-এ:
 * ওটা ব্যবসার নিয়ম, আর ব্যালেন্স দেখতে লেজারে যেতে হয়।
 */
class CashTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // খালি রাখলে সিরিজ থেকে বসে — CashTillService::create() দেখুন
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\.]*$/'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],

            /*
             * ⛔ দুইটাই চলতি কোম্পানির ভেতর থেকে — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ── ⚠️ কী ভাঙা ছিল ─────────────────────────────────────────
             * `holder_id` দেখত `exists:users,id` — অর্থাৎ **যেকোনো
             * কোম্পানির যে কেউ** নগদ টিলের দায়িত্বে বসতে পারতেন। আর
             * `branch_id` কিছুই দেখত না।
             *
             * ── ⓘ এটা টেন্যান্ট-ফাঁস নয়, তার চেয়ে নীরব কিছু ───────────
             * `BelongsToCompany` সারিটায় প্রসঙ্গ থেকে `company_id` বসায়,
             * তাই টিলটা নিজের কোম্পানিতেই থাকে — কেউ অন্য কোম্পানির
             * টাকা দেখতে পান না।
             *
             * ⛔ ভাঙে অন্য জায়গায়: **টাকাটা এমন শাখায় বসে যা এই
             * কোম্পানির নয়**। ⚠️ তখন ওই শাখার নগদ মিলানো কোনোদিন মেলে
             * না, আর কোনো পর্দা লাল হয় না — কেবল একটা সংখ্যা চিরকাল
             * একটু ভুল থাকে।
             *
             * ⭐ ছাঁচটা রিপোতে আগে থেকেই আছে (`EmployeeController`,
             * `WarehouseController`) — নীতির অভাব নয়, পৌঁছানোর অভাব।
             */
            'holder_id' => ['nullable', 'integer',
                Rule::exists('company_user', 'user_id')
                    ->where('company_id', CompanyContext::id())
                    ->where('is_active', true)],
            'branch_id' => ['nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', CompanyContext::id())],

            // ঋণাত্মক সীমার কোনো অর্থ নেই; শূন্য মানে সীমাহীন
            'limit_amount' => ['nullable', 'numeric', 'min:0'],

            'opening_balance' => ['nullable', 'numeric', 'min:0'],

            /*
             * তারিখ লাগে কেবল যখন সত্যিই একটা খোলা ব্যালেন্স আছে।
             *
             * ── কী ভেঙেছিল ─────────────────────────────────────────
             * নিয়মটা ছিল `required_with:opening_balance`, আর সেটা ঘরটা
             * **খালি কিনা** দেখে না — শুধু **পাঠানো হয়েছে কিনা** দেখে।
             * ফর্মে ঘরটায় ডিফল্ট "0" বসানো থাকে, তাই প্রতিবারই পাঠানো
             * হত, আর প্রতিবারই তারিখ চাইত।
             *
             * ফল: যিনি শুধু নাম আর শাখা দিয়ে একটা ক্যাশ কাউন্টার
             * বানাতে চান — খোলা ব্যালেন্স নিয়ে যিনি ভাবেনইনি — তিনি
             * এমন একটা ঘরের জন্য আটকে যেতেন যেটা তিনি ছোঁনওনি।
             *
             * শূন্য মানে "কোনো খোলা ব্যালেন্স নেই", তাই তারিখেরও দরকার
             * নেই। তারিখ চাওয়া হয় কেবল অশূন্য অঙ্কে।
             */
            'opening_date' => ['nullable', 'date', Rule::requiredIf(
                fn () => bccomp((string) ($this->input('opening_balance') ?: '0'), '0', 4) !== 0,
            )],

            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_primary' => $this->boolean('is_primary')]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('accounts::field.code'),
            'name_en' => __('accounts::field.name_en'),
            'name_bn' => __('accounts::field.name_bn'),
            'holder_id' => __('accounts::field.holder'),
            'limit_amount' => __('accounts::field.limit'),
        ];
    }
}
