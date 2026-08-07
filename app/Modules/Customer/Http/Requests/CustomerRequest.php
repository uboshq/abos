<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // দোকানের নাম নয়, যিনি চালান তাঁর নাম
            'owner_name' => ['nullable', 'string', 'max:191'],

            /*
             * পয়েন্ট ঐচ্ছিক — নতুন দোকান বসানোর সময় এলাকা ভাগ এখনো ঠিক
             * না-ও হতে পারে, আর তখন গ্রাহককে আটকে রাখার মানে নেই। তালিকায়
             * ফাঁকা ঘর দেখেই বোঝা যাবে কোনগুলো এখনো বসানো বাকি।
             */
            'location_id' => ['nullable', 'integer',
                Rule::exists('mdm_locations', 'id')->where('company_id', CompanyContext::id())],

            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            /*
             * ধরন এখন মাস্টার তালিকার একটা সারি।
             *
             * exists-এ company_id-ও, কারণ গ্লোবাল স্কোপ Eloquent-এ কাজ
             * করে, ভ্যালিডেটরের কাঁচা কোয়েরিতে নয় — ওটা ছাড়া অন্য
             * কোম্পানির ধরনের id পাঠিয়ে দেওয়া যেত।
             */
            'party_type_id' => [
                'nullable', 'integer',
                Rule::exists('mdm_party_types', 'id')->where('company_id', CompanyContext::id()),
            ],

            // পুরনো মুক্ত লেখাটা এখনো নেওয়া হয়, কিন্তু ফর্মে ঘরটা নেই:
            // মাইগ্রেশনে যে সারিগুলোর নাম মেলেনি সেগুলোর তথ্য যেন
            // ইমপোর্ট বা API দিয়ে ফেরানো যায়
            'customer_type' => ['nullable', 'string', 'max:32'],

            // ঋণাত্মক সীমার কোনো অর্থ নেই; শূন্য মানে সীমাহীন।
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],

            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', CompanyContext::id()),
            ],
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
