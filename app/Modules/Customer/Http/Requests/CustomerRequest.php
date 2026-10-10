<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\UserDataScope;
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

            /*
             * বিক্রয়ের পথ — নিজের কোম্পানির, সক্রিয়। ⓘ গ্রাহকের নিজের পুরনো
             * পথ বন্ধ হয়ে গেলেও রাখা যায়, নাহলে তাঁর অন্য কোনো ঘর আর সম্পাদনা করা যেত না।
             */
            'channel_id' => [
                'nullable', 'integer',
                Rule::exists('mdm_sales_channels', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->where('is_active', true);
                        $current = $this->route('customer')?->channel_id;
                        if ($current !== null) {
                            $q->orWhere('id', $current);
                        }
                    }),
            ],

            // পুরনো মুক্ত লেখাটা এখনো নেওয়া হয়, কিন্তু ফর্মে ঘরটা নেই:
            // মাইগ্রেশনে যে সারিগুলোর নাম মেলেনি সেগুলোর তথ্য যেন
            // ইমপোর্ট বা API দিয়ে ফেরানো যায়
            'customer_type' => ['nullable', 'string', 'max:32'],

            // ঋণাত্মক সীমার কোনো অর্থ নেই; শূন্য মানে বাকি নেই — কেবল নগদ (মালিক, ১ অক্টোবর ২০২৬)।
            // ⛔ decimal — "1e5" `numeric` পেরিয়ে bcmath-এ ভাঙত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৭)
            'credit_limit' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999999'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            /*
             * ⓘ ঋণাত্মক চলে — গ্রাহকের আগাম জমা (অগ্রিম) শুরুর বাকি হিসেবেই আসে। সীমাটা ঘরের মাপের ভিতরে
             * (`decimal(18,4)`); বসানোর চাবি সেবা দেখে ([[CustomerService::assertMayOpenABalance()]])।
             */
            'opening_balance' => ['nullable', 'decimal:0,4', 'min:-999999999999', 'max:999999999999'],
            'opening_date' => ['nullable', 'date'],

            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', CompanyContext::id()),
                // ⛔ নিজের নাগালের শাখাতেই — পুনঃঅডিট ৯ অক্টোবর ২০২৬ (গ্রাহক ১১); আগে কেবল কোম্পানি দেখা হত
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(DataScope::class)->allows($this->user(), UserDataScope::BRANCH, (int) $value)) {
                        $fail(__('customer::validation.branch_out_of_reach'));
                    }
                },
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * ⭐ খোলা ব্যালেন্সের দিক — মালিক, ৬ অক্টোবর ২০২৬: *"আন্তর্জাতিক মানে সাজাও"*।
     *
     * ⓘ ফর্মে অঙ্কটা সব সময় ধনাত্মক, পাশে দিক: "গ্রাহক দেবে (Dr)" বা "গ্রাহক পাবে (Cr)" — ঋণাত্মক অঙ্ক
     * লিখে আগাম বোঝানো ভুলপ্রবণ ছিল। ⚠️ সেবা আগের মতোই চিহ্ন দেখে (Cr = ঋণাত্মক), তাই এখানেই অঙ্কটা
     * চিহ্নে বদলায়; দিক না পাঠালে (ইমপোর্ট, API) অঙ্ক যেমন এসেছে তেমনই থাকে।
     */
    protected function prepareForValidation(): void
    {
        $side = $this->input('opening_side');
        $amount = trim((string) $this->input('opening_balance', ''));

        // ⛔ দিকটা গ্রাহকের কলাম নয় — রেখে দিলে `Customer::create()` mass-assignment-এ ভাঙত (৫০০)
        $this->request->remove('opening_side');
        $this->query->remove('opening_side');

        if (! in_array($side, ['dr', 'cr'], true) || $amount === '' || ! is_numeric($amount)) {
            return;
        }

        $plain = ltrim($amount, '-+');

        $this->merge(['opening_balance' => $side === 'cr' && ! Money::isZero($plain) ? '-'.$plain : $plain]);
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
