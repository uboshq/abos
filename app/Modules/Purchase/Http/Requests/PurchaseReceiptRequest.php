<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * মাল বুঝে নেওয়ার ইনপুট।
 */
class PurchaseReceiptRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'purchase_order_id' => ['nullable', 'integer',
                Rule::exists('pur_orders', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date'],
            'supplier_challan_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // আদেশের লাইন — কোন আদেশের তা সেবা স্তর মিলিয়ে দেখে, কারণ
            // এখানে আদেশটা কোনটা তা নিয়ম লেখার সময় জানা যায় না
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.received_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'supplier_id', 'warehouse_id', 'purchase_order_id',
            'trx_date', 'supplier_challan_no', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null),
        ));
    }
}
