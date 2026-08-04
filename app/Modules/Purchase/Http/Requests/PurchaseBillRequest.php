<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ক্রয় বিলের ইনপুট।
 */
class PurchaseBillRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:trx_date'],
            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // চালানের লাইনটা এই কোম্পানির কি না তা সেবা স্তর দেখে —
            // সন্তান-টেবিলে company_id নেই, বাবার আছে
            'lines.*.purchase_receipt_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'supplier_id', 'trx_date', 'due_on', 'supplier_bill_no', 'narration',
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
