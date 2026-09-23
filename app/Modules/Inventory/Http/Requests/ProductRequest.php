<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * পণ্যের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * দামের নিয়ম ও কোডের অনন্যতা এখানে নয়, ProductService-এ: ইমপোর্ট ও
 * API একই সার্ভিস দিয়ে ঢোকে, আর সেখানে এই ক্লাসটা চলে না।
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    /**
     * ক্রয়মূল্য দেখার অনুমতি না থাকলে অঙ্কটা এখানেই ঝরে যায়।
     *
     * ── কেন কেবল লুকানো যথেষ্ট নয় ───────────────────────────────────
     * ফর্ম থেকে ঘরটা তুলে দিলে পর্দায় আর দেখা যায় না — কিন্তু একটা
     * হাতে বানানো POST-এ `purchase_price` পাঠিয়ে দিলে সেটা দিব্যি
     * সংরক্ষিত হত। **যে ঘর কেউ দেখতে পান না, সেটা তিনি বদলাতেও
     * পারবেন না** — নাহলে পাহারাটা কেবল পর্দার সাজ।
     *
     * ── কেন ব্যতিক্রম নয়, ছেঁটে ফেলা ────────────────────────────────
     * ভুল বার্তা দিলে ফর্মটা আটকে যেত, আর ব্যবহারকারী বুঝতেন না তিনি
     * কী ভুল করেছেন — তিনি তো ঘরটা দেখেনইনি। ঘরটা অনুপস্থিত থাকলে
     * [[ProductService::update()]] সেটা ছোঁয় না, আর আগের দরটা যেমন
     * ছিল তেমনই থাকে।
     */
    protected function prepareForValidation(): void
    {
        if (! FieldSecurity::visible(
            Product::class, 'purchase_price')) {
            $this->request->remove('purchase_price');
        }
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:64'],
            /*
             * ব্র্যান্ড ও শ্রেণি এখন সারির চাবি, লেখা নয়।
             *
             * `exists` কোম্পানি ধরে যাচাই করে — নাহলে অন্য কোম্পানির
             * একটা ব্র্যান্ডের id পাঠিয়ে দিলে সেটা বসে যেত, আর তখন
             * দুই কোম্পানির তালিকা একে অন্যের সাথে জড়িয়ে যেত।
             */
            'brand_id' => ['nullable', 'integer',
                Rule::exists('mdm_brands', 'id')->where('company_id', $companyId)],
            'category_id' => ['nullable', 'integer',
                Rule::exists('mdm_product_categories', 'id')->where('company_id', $companyId)],

            /*
             * ⭐ একক বাধ্যতামূলক — মালিকের সিদ্ধান্ত, ১৯ সেপ্টেম্বর ২০২৬।
             * ⓘ প্যাকের টেবিল base ছাড়া দাঁড়ায় না ("১ কার্টন = ২৪ **কী**?"),
             * আর লাইভে ৬টা পণ্য একক ছাড়াই বসে ছিল। (ইমপোর্ট আর সিডার এই
             * দরজা দিয়ে যায় না; তাদের জন্য `abos:packs-backfill`।)
             */
            'unit_id' => ['required', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * প্যাকের টেবিল — "১ কার্টন = ১২ বক্স"। এখানে কেবল আকার; মাপ,
             * শিকল, চক্র আর বারকোড যাচাই [[ProductPackService]]-এ, কারণ
             * সেগুলো সারিগুলো একসাথে দেখে তবে বলা যায়।
             */
            /*
             * ⭐ লট ধরা হবে কি না — মালিকের নিয়ম, ২৩ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ *"লট ছাড়া মাল ঢুকবেও না, বেরোবেও না"*। ⚠️ ঘরটা আগে
             * থেকেই ছিল, কিন্তু কোনো পর্দা বা যাচাই ওটা ছুঁত না — তাই
             * সুইচটা বাস্তবে ছিলই না।
             */
            'track_batch' => ['nullable', 'boolean'],

            'pack_table' => ['nullable', 'boolean'],
            'packs' => ['nullable', 'array', 'max:20'],
            'packs.*' => ['array'],
            'packs.*.unit_id' => ['nullable', 'integer'],
            'packs.*.per_qty' => ['nullable', 'numeric'],
            'packs.*.per_unit_id' => ['nullable', 'integer'],
            'packs.*.barcode' => ['nullable', 'string', 'max:64'],
            'pack_defaults' => ['nullable', 'array'],
            'pack_defaults.*' => ['nullable', 'integer'],
            'tax_id' => ['nullable', 'integer',
                Rule::exists('mdm_taxes', 'id')->where('company_id', $companyId)],

            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],

            'is_active' => ['nullable', 'boolean'],

            // নামের নকল ধরা পড়লে ব্যবহারকারী টিক দিয়ে জেনেশুনে এগোন —
            // গ্রাহক/সরবরাহকারীর মতোই। রেকর্ডের কলাম নয়, সার্ভিস মুছে ফেলে।
            'allow_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('inventory::field.code'),
            'name_en' => __('inventory::field.name_en'),
            'barcode' => __('inventory::field.barcode'),
            'purchase_price' => __('inventory::field.purchase_price'),
            'sale_price' => __('inventory::field.sale_price'),
            'reorder_level' => __('inventory::field.reorder_level'),
        ];
    }
}
