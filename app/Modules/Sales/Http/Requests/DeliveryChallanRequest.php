<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ডেলিভারি চালানের ইনপুট। */
class DeliveryChallanRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'sales_order_id' => ['nullable', 'integer',
                Rule::exists('sal_orders', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date'],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // অর্ডারের লাইনটা কোন অর্ডারের তা সেবা স্তর মিলিয়ে দেখে
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.delivered_qty' => ['required', 'numeric', 'gt:0'],
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
            'customer_id', 'warehouse_id', 'sales_order_id',
            'trx_date', 'vehicle_no', 'driver_name', 'narration',
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
