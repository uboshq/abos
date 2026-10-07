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
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null)
                && (float) ($line['qty'] ?? 0) > 0,
        ));
    }
}
