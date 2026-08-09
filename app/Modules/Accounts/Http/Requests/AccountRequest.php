<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use App\Modules\Accounts\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * খাতের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * এখানে শুধু আকারের যাচাই: ধরনটা তালিকার একটা কি না, সংখ্যা সংখ্যা কি না।
 * "এই বাবার নিচে বসানো যায় কি না", "এন্ট্রি থাকলে ধরন বদলানো যায় কি না"
 * — ওগুলো AccountService-এ, কারণ ওগুলো ব্যবসার নিয়ম, আর ইমপোর্ট বা
 * প্রমিত ছক বসানোর সময়ও ওগুলো মানতে হয়, যেখানে এই ক্লাসটা চলে না।
 */
class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\.\/]*$/'],

            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],

            'parent_id' => ['nullable', 'integer'],

            // বাবা থাকলে ধরন বাবার থেকেই আসে, তাই তখন এটা ঐচ্ছিক
            'type' => ['nullable', Rule::in(Account::TYPES)],
            'nature' => ['nullable', Rule::in([Account::DEBIT, Account::CREDIT])],

            'is_group' => ['nullable', 'boolean'],
            'is_cash' => ['nullable', 'boolean'],
            'is_bank' => ['nullable', 'boolean'],

            'opening_balance' => ['nullable', 'numeric'],
            /*
             * তারিখ লাগে কেবল অশূন্য খোলা ব্যালেন্সে — ক্যাশ টিলের ফর্মে
             * একই ভুল ছিল, একই কারণে।
             *
             * `required_with` ঘরটা খালি কিনা দেখে না, শুধু পাঠানো হয়েছে
             * কিনা দেখে। ঘরে ডিফল্ট "0" বসানো থাকলে প্রতিবারই তারিখ
             * চাইত, যদিও কেউ কোনো খোলা ব্যালেন্স দেননি।
             */
            'opening_date' => ['nullable', 'date', Rule::requiredIf(
                fn () => bccomp((string) ($this->input('opening_balance') ?: '0'), '0', 4) !== 0,
            )],

            'account_number' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:120'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, আর তখন "মিথ্যা" আর
        // "দেওয়া হয়নি" আলাদা করা যায় না — ফলে একটা ফিল্ড লুকানো থাকলে
        // সম্পাদনায় সেটা নিজে থেকে মিথ্যা হয়ে যেত।
        $this->merge([
            'is_group' => $this->boolean('is_group'),
            'is_cash' => $this->boolean('is_cash'),
            'is_bank' => $this->boolean('is_bank'),
        ]);
    }

    /**
     * নগদ আর ব্যাংক একসাথে হয় না, আর গ্রুপ কোনোটাই নয়।
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('is_cash') && $this->boolean('is_bank')) {
                    $validator->errors()->add('is_cash', __('accounts::validation.cash_or_bank_not_both'));
                }

                if ($this->boolean('is_group') && ($this->boolean('is_cash') || $this->boolean('is_bank'))) {
                    // গ্রুপে টাকা বসে না, তাই "এটা নগদের খাত" বলার কোনো
                    // মানে নেই — আর ক্যাশ বই তখন একটা মাথা দেখাত যাতে
                    // কোনো লেনদেন নেই।
                    $validator->errors()->add('is_group', __('accounts::validation.group_is_not_money'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('accounts::field.code'),
            'name_en' => __('accounts::field.name_en'),
            'name_bn' => __('accounts::field.name_bn'),
            'parent_id' => __('accounts::field.parent'),
            'type' => __('accounts::field.type'),
            'opening_balance' => __('accounts::field.opening_balance'),
            'opening_date' => __('accounts::field.opening_date'),
        ];
    }
}
