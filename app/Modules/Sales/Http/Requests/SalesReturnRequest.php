<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * বিক্রয় ফেরতের ইনপুট।
 */
class SalesReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'sales_invoice_id' => ['nullable', 'integer',
                Rule::exists('sal_invoices', 'id')->where('company_id', $companyId)],
            /*
             * ⭐ কারণ বাধ্যতামূলক — NEXUS §২৪। প্রসঙ্গ, চালু থাকা আর নোটের
             * নিয়ম [[SalesReturnReasonGuard]]-এ; এখানে কেবল পর্দার প্রথম বাধা।
             */
            'reason_code_id' => ['required', 'integer',
                Rule::exists('mdm_reason_codes', 'id')->where('company_id', $companyId)],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.sales_invoice_line_id' => ['nullable', 'integer'],
            /*
             * ⭐ দামি পরিমাণ শূন্য চলে, যদি ফ্রি পরিমাণ থাকে — মালিকের সিদ্ধান্ত,
             * ২৭ সেপ্টেম্বর ২০২৬: ফ্রি/উপহারের মাল ফেরত আসে ফ্রি ভাণ্ডারে,
             * শূন্য দামে। ⓘ দুটোই শূন্য হলে সারিটা নিচে `lineData()`-তে বাদ।
             */
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],

            // কোন প্যাকে ফেরত এসেছে — খালি মানে পণ্যের নিজের একক
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],

            // নষ্ট মাল আবার বিক্রি হয়ে যাবে না — টিকটা এখানেই আসে
            'lines.*.to_hold' => ['nullable', 'boolean'],

            // লাইনের নিজের কারণ, নোট আর লট — খালি কারণ মানে হেডারেরটাই
            'lines.*.reason_code_id' => ['nullable', 'integer',
                Rule::exists('mdm_reason_codes', 'id')->where('company_id', $companyId)],
            'lines.*.reason_note' => ['nullable', 'string', 'max:500'],
            'lines.*.batch_id' => ['nullable', 'integer',
                Rule::exists('inv_batches', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'customer_id', 'warehouse_id', 'sales_invoice_id', 'reason_code_id', 'reason_note',
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
                && ((float) ($line['qty'] ?? 0) > 0 || (float) ($line['free_qty'] ?? 0) > 0),
        ));
    }
}
