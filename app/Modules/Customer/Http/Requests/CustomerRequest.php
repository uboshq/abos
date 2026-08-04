<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * গ্রাহকের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪ ("প্রতিটা ইনপুটে ভ্যালিডেশন")।
 *
 * অনুমোদন Policy-তে, তাই authorize() এখানে সবসময় true: কন্ট্রোলার
 * authorizeResource দিয়ে আগেই আটকে দেয়। দুই জায়গায় দুই রকম নিয়ম
 * থাকলে কোনটা আসল সেটা খুঁজতে হত।
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // কোড ঐচ্ছিক — না দিলে নম্বর সিরিজ থেকে আসবে। অনন্যতা
            // CustomerService যাচাই করে, কারণ সেখানে মুছে ফেলা রেকর্ডও
            // ধরা হয় আর এখানে সেটা করলে নিয়মটা দুই জায়গায় থাকত।
            'code' => ['nullable', 'string', 'max:32'],

            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],

            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            'customer_type' => ['nullable', 'string', 'max:32'],

            // ঋণাত্মক সীমার কোনো অর্থ নেই; শূন্য মানে সীমাহীন।
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],

            'branch_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('customer::field.code'),
            'name_en' => __('customer::field.name_en'),
            'name_bn' => __('customer::field.name_bn'),
            'phone' => __('customer::field.phone'),
            'credit_limit' => __('customer::field.credit_limit'),
        ];
    }
}
