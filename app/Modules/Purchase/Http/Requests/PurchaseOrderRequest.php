<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ক্রয় আদেশের ইনপুট।
 *
 * exists নিয়মে company_id বসাতেই হবে: গ্লোবাল স্কোপ ভ্যালিডেটরের কাঁচা
 * কোয়েরিতে চলে না, তাই ওটা ছাড়া অন্য কোম্পানির সরবরাহকারীর id পাঠিয়ে
 * দেওয়া যেত আর সেটা নীরবে গৃহীত হত।
 */
class PurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:trx_date'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.ordered_qty' => ['required', 'numeric', 'gt:0'],

            // কোন প্যাকে লেখা — খালি মানে পণ্যের নিজের একক
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

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
            'supplier_id', 'warehouse_id', 'trx_date', 'expected_on', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        // ফাঁকা সারি বাদ — ফর্মে সবসময় একটা খালি লাইন থাকে, আর সেটা
        // জমা দিলে "পরিমাণ শূন্যের বেশি হতে হবে" বলে আটকাত
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null),
        ));
    }
}
