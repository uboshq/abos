<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Core\Support\CompanyContext;
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
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],

            'unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],
            'tax_id' => ['nullable', 'integer',
                Rule::exists('mdm_taxes', 'id')->where('company_id', $companyId)],

            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],

            'is_active' => ['nullable', 'boolean'],
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
