<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * বিক্রয় উদ্ধৃতির ইনপুট।
 *
 * ⚠️ exists নিয়মে company_id বসাতেই হবে — গ্লোবাল স্কোপ ভ্যালিডেটরের কাঁচা
 * কোয়েরিতে চলে না ([[SalesOrderRequest]]-এ একই কারণ), নাহলে অন্য কোম্পানির
 * গ্রাহক, দর তালিকা বা শর্তের id নীরবে গৃহীত হত।
 */
class SalesQuotationRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            // ⛔ গ্রাহক বাছতেই হবে — সম্ভাব্য ক্রেতা (prospect) CRM-এর কাজ, পরে
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['required', 'date', 'after_or_equal:trx_date'],
            'price_list_id' => ['nullable', 'integer',
                Rule::exists('mdm_price_lists', 'id')->where('company_id', $companyId)],
            'payment_term_id' => ['nullable', 'integer',
                Rule::exists('mdm_payment_terms', 'id')->where('company_id', $companyId)],
            'delivery_terms' => ['nullable', 'string', 'max:500'],
            'header_discount' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            // ⛔ দর শূন্য নয় — মালিক, ২৩ সেপ্টেম্বর ২০২৬: *"sales price chara entry nibe na"*
            'lines.*.rate' => ['required', 'numeric', 'gt:0'],
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
            'customer_id', 'trx_date', 'valid_until', 'price_list_id', 'payment_term_id',
            'delivery_terms', 'header_discount', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        // ফাঁকা সারি বাদ — ফর্মে সবসময় একটা খালি লাইন থাকে
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null),
        ));
    }
}
