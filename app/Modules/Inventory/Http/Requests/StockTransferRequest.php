<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * স্টক স্থানান্তরের ইনপুট।
 *
 * "উৎস ≠ গন্তব্য" এখানে নয়, সেবা স্তরে — ওটা ব্যবসার নিয়ম, আর নিয়মটা
 * একই জায়গায় থাকা দরকার যেখানে স্টক নড়ে।
 */
class StockTransferRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'from_warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'to_warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],

            /*
             * ⭐ কোন প্যাকে লেখা — খালি মানে পণ্যের নিজের একক (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⓘ১৬;
             * [[ATransferLineKeepsItsPackTest]])। ⛔ নিয়মে ঘরটা ছিল না, তাই `validated()` এককটা ফেলে দিত — "২ কার্টন" লিখলে
             * সেবা ([[StockTransferService::replaceLines()]], যে প্যাক বোঝে) পেত "২ পিস"। ক্রয়ের ফর্মগুলোর একই নিয়ম।
             */
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'from_warehouse_id', 'to_warehouse_id', 'trx_date', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        // ⓘ পরিমাণ লেখা হিসেবেই তুলনা, float নয় — টাকা আর পরিমাণ কখনো float নয় ([[MoneyIsNeverAFloatTest]]; ⓘ১৬)
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null)
                && is_numeric($line['qty'] ?? null)
                && bccomp((string) $line['qty'], '0', 4) > 0,
        ));
    }
}
