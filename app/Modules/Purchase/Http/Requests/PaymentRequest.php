<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * পরিশোধের ইনপুট।
 *
 * লাইনগুলো পণ্যের নয়, বিলের — তাই ফাঁকা-সারি ছাঁকনিও আলাদা।
 */
class PaymentRequest extends FormRequest
{
    /**
     * ⭐ টাকার ঘর লাখ-কমায় আসে ("1,20,000.00", নকশার মিল-তালিকা #৯) — কেবল কমা ছাঁটা, আর কিছু নয়।
     *
     * ⓘ অক্ষর বা অন্য চিহ্ন থাকলে লেখাটা যেমন তেমন থাকে, আর `numeric` নিয়ম আগের মতোই থামায়।
     */
    protected function prepareForValidation(): void
    {
        foreach (['amount', 'charge_amount'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && str_contains($value, ',')) {
                $this->merge([$field => str_replace(',', '', trim($value))]);
            }
        }
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
                // ⛔ হেডারে বাছা শাখার বাইরের সরবরাহকারীকে পরিশোধ নয় — পর্দার তালিকার একই ছাঁকনি ([[Supplier::scopeInViewedBranch()]])
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! \App\Modules\Supplier\Models\Supplier::query()->inViewedBranch()->whereKey((int) $value)->exists()) {
                        $fail(__('validation.branch_out_of_reach'));
                    }
                }],
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            // ⭐ পদ্ধতির ঘর — তিন পর্দার একই নিয়ম ([[MoneyMethod::rules()]]; মালিক, ২১ অক্টোবর ২০২৬)
            ...\App\Core\Support\MoneyMethod::rules(),
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['nullable', 'array', 'max:500'],
            'lines.*.purchase_bill_id' => ['nullable', 'integer',
                Rule::exists('pur_bills', 'id')->where('company_id', $companyId)],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'supplier_id', 'account_id', 'trx_date', 'amount',
            'instrument', 'instrument_no', 'instrument_date', 'narration',
            'from_bank', 'transfer_mode_id', 'value_date', 'deposit_slip_no', 'charge_amount',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['purchase_bill_id'] ?? null)
                && (float) ($line['amount'] ?? 0) > 0,
        ));
    }
}
