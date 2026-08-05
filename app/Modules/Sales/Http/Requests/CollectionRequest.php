<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * আদায়ের ইনপুট।
 *
 * লাইনগুলো পণ্যের নয়, বিলের — তাই ফাঁকা-সারি ছাঁকনিও আলাদা।
 */
class CollectionRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'instrument' => ['nullable', 'string', 'max:32'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'instrument_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['nullable', 'array'],
            'lines.*.sales_invoice_id' => ['nullable', 'integer',
                Rule::exists('sal_invoices', 'id')->where('company_id', $companyId)],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'customer_id', 'account_id', 'trx_date', 'amount',
            'instrument', 'instrument_no', 'instrument_date', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['sales_invoice_id'] ?? null)
                && (float) ($line['amount'] ?? 0) > 0,
        ));
    }
}
