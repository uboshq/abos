<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ডেলিভারি চালানের ইনপুট। */
class DeliveryChallanRequest extends FormRequest
{
    /**
     * ⭐ চালান হয় আদেশের গায়ে — মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬।
     *
     * *"kono direct challan kata zabena, order ref. e challan hobe tar
     * por invoice hobe"* — অর্থাৎ পথটা একমুখী: আদেশ → চালান → বিল।
     *
     * ── ⓘ কেন নিয়মটা ন্যায্য ────────────────────────────────────────
     * আদেশ ছাড়া চালান মানে **কেউ চায়নি এমন মাল বেরিয়ে গেছে**, আর তখন
     * "কে বলেছিল পাঠাতে" প্রশ্নের কোনো কাগজ থাকে না। ⚠️ বিলটা চালানের
     * গায়ে বসে, তাই ভিত্তিহীন চালান মানে ভিত্তিহীন বিল — আর গ্রাহক
     * সেটা অস্বীকার করলে আমাদের হাতে কিছুই নেই।
     *
     * ── ⚠️ `authorize()`-এ, `rules()`-এ নয় ─────────────────────────
     * ⓘ [[SalesInvoiceRequest]]-এ ঠিক একই ছাঁচ, আর কারণটাও এক:
     * `authorize()` যাচাইয়ের **আগে** চলে, তাই উত্তরটা সবসময় একই —
     * বন্ধ দরজা, ঘর ধরে ধরে ভুলের তালিকা নয়। ⛔ `required` দিলে
     * পর্দাটা বলত *"আদেশ নির্বাচন করুন"*, যেন ঘরটা ভরলেই পথটা খোলে —
     * অথচ এই ফর্মে আদেশ ছাড়া কোনো পথই নেই।
     *
     * ── ⭐ সরাসরি বিক্রয় এই দরজা দিয়ে আসে না ───────────────────────
     * ⓘ তার নিজের রুট, আর সে [[DeliveryChallanService]]-কে সরাসরি
     * ডাকে — কাউন্টারে মাল হাতে হাতে যায়, সেখানে আগে থেকে আদেশ থাকে
     * না। ⚠️ ঐ ভিতরের চালানটা থাকবে কি না, সেটা মালিকের কাছে এখনো
     * ঝুলে থাকা প্রশ্ন; এই পরিবর্তন তাতে হাত দেয় না।
     */
    public function authorize(): bool
    {
        return $this->isMethod('PUT') || filled($this->input('sales_order_id'));
    }

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
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            /*
             * বহরের গাড়ি হলে id, বাইরের গাড়ি হলে শুধু লেখা নম্বর।
             *
             * দুইটাই ঐচ্ছিক, কারণ ভাড়ার ট্রাকও মাল নিয়ে যায় — বাধ্য
             * করলে মানুষ যেকোনো একটা গাড়ি বেছে নিত শুধু ফর্ম পার করতে,
             * আর কাগজে ভুল নম্বর ছাপত।
             */
            'vehicle_id' => ['nullable', 'integer',
                Rule::exists('mdm_vehicles', 'id')->where('company_id', $companyId)],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // অর্ডারের লাইনটা কোন অর্ডারের তা সেবা স্তর মিলিয়ে দেখে
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.delivered_qty' => ['required', 'numeric', 'gt:0'],

            // কোন প্যাকে লেখা — খালি মানে পণ্যের নিজের একক
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

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
            'trx_date', 'vehicle_id', 'vehicle_no', 'driver_name', 'narration',
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
