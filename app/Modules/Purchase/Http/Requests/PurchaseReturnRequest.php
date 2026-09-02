<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ক্রয় ফেরতের ইনপুট।
 */
class PurchaseReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'purchase_bill_id' => ['nullable', 'integer',
                Rule::exists('pur_bills', 'id')->where('company_id', $companyId)],
            'reason_code_id' => ['nullable', 'integer',
                Rule::exists('mdm_reason_codes', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // বিলের লাইনটা এই কোম্পানির কি না তা সেবা স্তর দেখে —
            // সন্তান-টেবিলে company_id নেই, বাবার আছে
            'lines.*.purchase_bill_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],

            // কোন প্যাকে ফেরত যাচ্ছে — খালি মানে পণ্যের নিজের একক
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'supplier_id', 'warehouse_id', 'purchase_bill_id', 'reason_code_id',
            'trx_date', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null)
                && (float) ($line['qty'] ?? 0) > 0,
        ));
    }
}
