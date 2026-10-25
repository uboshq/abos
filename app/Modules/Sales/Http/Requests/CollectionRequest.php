<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use App\Core\Support\MoneyMethod;
use App\Modules\Sales\Services\CollectionInput;
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
            'customer_id' => ['bail', 'required', 'integer',
                /*
                 * ⛔ মুছে ফেলা গ্রাহকে আদায় নয় — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
                 * ⓘ `exists` কাঁচা কোয়েরি: SoftDeletes আর ডিলারের দেয়াল সেখানে চলে না, তাই দুটোই হাতে।
                 * ⓘ নিষ্ক্রিয় গ্রাহক আটকানো হয় না — বন্ধ ডিলারও পুরনো বাকি শোধ করেন।
                 */
                Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
                /*
                 * ⭐ নাগালের একটাই নিয়ম, ওয়েব আর ফোনে (fe, ২২ অক্টোবর ২০২৬): গ্রাহকের শাখা মানুষের নাগালে থাকলে তবেই আদায়
                 * ([[Customer::scopeInViewedBranch()]] — ফোনের [[CollectionInput::fromPhone()]] আর [[CollectionSync]]-এর একই ছাঁকনি)।
                 * ⓘ "পক্ষ শাখা পেরোয়" (0d9cf4c8b) এখন কেবল সব-শাখা মানুষের জন্য — তাঁর কাছে ছাঁকনি কিছু বাদ দেয় না, আর টাকা বসে
                 * গ্রাহকের শাখায় (দফা ১৪)। শাখায় বাঁধা মানুষের কাছে অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক একই কথা পায়
                 * (`sales::sync.unknown_customer`, ফোনের সেই বাক্য) — কে আছেন জানা যায় না।
                 */
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! \App\Modules\Customer\Models\Customer::query()->withoutGlobalScopes()->whereKey((int) $value)->inViewedBranch()->exists()) {
                        $fail(__('sales::sync.unknown_customer'));
                    }
                },
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(\App\Core\Services\DealerScope::class)->allowsCustomer((int) $value)) {
                        $fail(__('customer::binding.dealer_out_of_reach'));
                    }
                }],
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', PlainNumber::RULE, 'gt:0'],
            /*
             * ⭐ পদ্ধতির ঘর cb-র নিয়মে ([[MoneyMethod::rules()]]) — নগদ · MFS · ব্যাংক ট্রান্সফার · নগদ জমা · চেক; চার্জের ঘর `bank_charge`।
             * ⓘ পুরনো ফর্ম/ফোনের মুক্ত লেখা (যেমন "bKash") আর চলে না এই দরজায় — নকশা ১০০-এর পর্দা কেবল পাঁচটা পাঠায়।
             */
            ...MoneyMethod::rules('bank_charge'),
            'bank_charge' => ['nullable', 'numeric', PlainNumber::RULE, 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],

            // ⭐ দফা ৪, ৫ — গ্রাহক যা কেটে রাখলেন, আর কম পড়া অংশ ছাড় বা রাউন্ডিং
            'vds' => ['nullable', 'numeric', PlainNumber::RULE, 'min:0'],
            'ait' => ['nullable', 'numeric', PlainNumber::RULE, 'min:0'],
            'difference' => ['nullable', 'array'],
            'difference.kind' => ['nullable', 'in:discount,rounding'],
            'difference.amount' => ['nullable', 'numeric', PlainNumber::RULE, 'min:0'],

            // ⭐ দফা ৩ — খোলা বিল ও ক্রেডিট; `id` খোলা কাগজের public_id (পুরনো ফর্মে বিলের সংখ্যা), ক্রেডিটের অঙ্ক ঋণাত্মক হতে পারে
            'lines' => ['nullable', 'array', 'max:500'],
            // ⓘ নতুন পর্দা পাঠায় — সব টিক তুললে "কোনো বিলে নয়", নিজে থেকে পুরনো আগে নয় ([[CollectionInput::$autoLines]])
            'lines_given' => ['nullable', 'in:1'],
            'lines.*.kind' => ['nullable', 'in:bill,debit_note,credit_note,credit'],
            'lines.*.id' => ['nullable', 'string', 'max:64'],
            'lines.*.sales_invoice_id' => ['nullable', 'integer',
                Rule::exists('sal_invoices', 'id')->where('company_id', $companyId)],
            'lines.*.amount' => ['nullable', 'numeric', PlainNumber::RULE],

            // ⭐ দফা ১৫ — স্লিপ, অ্যাডভাইস বা স্ক্রিনশট (৫ MB; ধরন AttachmentEngine দেখে)
            'slip' => ['nullable', 'file', 'max:5120'],
        ];
    }

    /** ⓘ না-থাকা (বা মোছা) গ্রাহক আর নাগালের বাইরের গ্রাহক একই কথা পান — ফোনের সেই বাক্য */
    public function messages(): array
    {
        return ['customer_id.exists' => __('sales::sync.unknown_customer')];
    }

    /** ⭐ নকশা ১০০-এর আবেদন — সেবার একটাই দরজা ([[CollectionService::createFrom()]]) */
    public function collectionInput(): CollectionInput
    {
        $data = $this->safe()->except(['slip']);

        return CollectionInput::fromRequest($data);
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
