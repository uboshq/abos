<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * সরবরাহকারীর ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * "বাংলা নাম বাধ্যতামূলক কি না" ও "BIN লাগবে কি না" এখানে নয়,
 * SupplierService-এ: ওগুলো সেটিংসের উপর নির্ভর করে, আর ইমপোর্ট বা
 * সিডার থেকে তৈরি করলেও নিয়মটা খাটতে হবে — যেখানে এই ক্লাসটা চলে না।
 */
class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // অনুমতি রুটের মিডলওয়্যারে (AuthorizesResource) — দুই জায়গায়
        // একই যাচাই থাকলে একটা বদলালে অন্যটা চুপচাপ পুরনো থেকে যায়
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // কোড ঐচ্ছিক — না দিলে নম্বর সিরিজ (SUP) থেকে আসে।
            // অনন্যতা SupplierService দেখে, কারণ মুছে ফেলা সারিও গুনতে হয়।
            'code' => ['nullable', 'string', 'max:32'],

            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],

            'phone' => ['nullable', 'string', 'max:32'],
            /*
             * নকল হলেও এগোনোর ইচ্ছা।
             *
             * নামের মিল আটকানো হয় না, কেবল দেখানো হয় — তাই এই ঘরটা
             * দরকার, নাহলে "রহিম স্টোর" নামে দ্বিতীয় দোকানটা কোনোদিন
             * খোলাই যেত না। টিকটা সার্ভিস পর্যন্ত না পৌঁছালে পাহারাটা
             * পাহারা থাকত না, দেয়াল হয়ে যেত।
             */
            'allow_duplicate' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],

            'contact_person' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:32'],

            /*
             * সম্পর্কগুলো exists দিয়ে দেখা, আর exists-এ company_id-ও —
             * গ্লোবাল স্কোপ Eloquent-এ কাজ করে, ভ্যালিডেটরের কাঁচা
             * কোয়েরিতে নয়। ওটা ছাড়া অন্য কোম্পানির শর্তের id পাঠিয়ে
             * দেওয়া যেত।
             */
            'party_type_id' => [
                'nullable', 'integer',
                Rule::exists('mdm_party_types', 'id')->where('company_id', $this->companyId()),
            ],
            'payment_term_id' => [
                'nullable', 'integer',
                Rule::exists('mdm_payment_terms', 'id')->where('company_id', $this->companyId()),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $this->companyId()),
            ],

            'bin' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],

            // ঋণাত্মক সীমার কোনো অর্থ নেই; শূন্য মানে সীমা বলা নেই
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],

            /*
             * ফর্মে এই ঘরটা নেই — সক্রিয়/নিষ্ক্রিয় করা আলাদা কাজ, আলাদা
             * বোতাম, আলাদা অনুমতি। নিয়মটা তবু আছে, কারণ ইমপোর্ট ও API
             * একই Request দিয়েই আসবে, আর সেখানে ঘরটা পাঠানো যায়।
             */
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('supplier::field.code'),
            'name_en' => __('supplier::field.name_en'),
            'name_bn' => __('supplier::field.name_bn'),
            'phone' => __('supplier::field.phone'),
            'email' => __('supplier::field.email'),
            'bin' => __('supplier::field.bin'),
            'tin' => __('supplier::field.tin'),
            'party_type_id' => __('supplier::field.party_type'),
            'payment_term_id' => __('supplier::field.payment_term'),
            'branch_id' => __('supplier::field.branch'),
            'credit_limit' => __('supplier::field.credit_limit'),
            'credit_days' => __('supplier::field.credit_days'),
            'opening_balance' => __('supplier::field.opening_balance'),
            'opening_date' => __('supplier::field.opening_date'),
        ];
    }

    private function companyId(): ?int
    {
        return CompanyContext::id();
    }
}
