<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** বিক্রয় বিলের ইনপুট। */
class SalesInvoiceRequest extends FormRequest
{
    /**
     * বিল শূন্য থেকে জন্মায় না — দরজাটা যাচাইয়েরও আগে।
     *
     * ⛔ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬: *"r kono vabei bill
     * generate hobe na"* — দুইটাই পথ, আদেশ আর সরাসরি বিক্রয়।
     *
     * ── ⚠️ কেন এখানে, কন্ট্রোলারে নয় ────────────────────────────────
     * প্রথমে তালাটা কন্ট্রোলারের `store()`-এ বসানো হয়েছিল, আর সেটা
     * **কোনোদিন চলত না**: ফর্ম-রিকোয়েস্টের যাচাই কন্ট্রোলারের আগে
     * চলে, তাই একটা আধা-ভরা POST যাচাইয়ে আটকে যেত আর তালাটা পর্যন্ত
     * পৌঁছাতই না। ⓘ ধরা পড়েছে পাহারাটা লাল হওয়ায়।
     *
     * ⭐ `authorize()` সবার আগে চলে, তাই উত্তরটা সবসময় একই — ৪০৪,
     * যাচাইয়ের ভুলের তালিকা নয়।
     *
     * ⓘ সরাসরি বিক্রয় এই দরজা দিয়ে আসে না — তার নিজের রুট, আর সে
     * [[SalesInvoiceService]]-কে সরাসরি ডাকে।
     */
    public function authorize(): bool
    {
        return $this->isMethod('PUT') || filled($this->input('delivery_challan_id'));
    }

    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date', 'after_or_equal:trx_date'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // চালানের লাইনটা এই কোম্পানির কি না তা সেবা স্তর দেখে —
            // সন্তান-টেবিলে company_id নেই, বাবার আছে
            'lines.*.delivery_challan_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],

            /*
             * কোন প্যাকে লেখা হয়েছে — খালি মানে পণ্যের নিজের একক।
             *
             * নিয়মটা না থাকলে validated() ঘরটা নীরবে ফেলে দিত: পর্দায়
             * "বাক্স" বাছা যেত, ফর্ম জমাও হত, অথচ সার্ভারে কিছুই
             * পৌঁছাত না আর মাল যেত পিস হিসেবে।
             */
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * ⛔ দর শূন্য নয় — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
             *
             * তাঁর কথা: *"sales price chara entry nibe na"*।
             *
             * ── ⚠️ শূন্য দরে বিক্রির ক্ষতিটা নীরব ─────────────────────
             * ⓘ মাল গুদাম থেকে নামে, খরচ খাতায় বসে, কিন্তু আয় শূন্য —
             * ⛔ অর্থাৎ প্রতিটা শূন্য-দরের সারি খাতায় **সরাসরি লোকসান**
             * লেখে, আর কোনো পর্দা লাল হয় না।
             *
             * ⓘ ফ্রি বা উপহারের মাল এতে আটকায় না: ওগুলোর নিজের ঘর ও
             * নিজের টেবিল আছে (`free_qty`, উপহারের সারি), আর সেখানে দর
             * চাওয়াই হয় না।
             */
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
            'customer_id', 'warehouse_id', 'trx_date', 'due_on', 'narration',
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
