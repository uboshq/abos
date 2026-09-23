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
     * না।
     *
     * ── ⭐ আর ওটা ছাড়ই থাকবে — মালিকের সিদ্ধান্ত, ২২ সেপ্টেম্বর ২০২৬ ──
     * প্রশ্ন ছিল: কাউন্টারের নগদ বিক্রিতেও আদেশ→নিশ্চিত, দুইটা বাড়তি
     * ধাপ বসবে কি না। ⭐ উত্তর: **না — কাউন্টার আলাদা থাক।**
     *
     * ⚠️ কারণ দশ টাকার হাতে-হাতে বিক্রিতে ঐ দুইটা ধাপ বসালে বিক্রয়কর্মী
     * প্রতিবার একটা ভুয়া আদেশ বানিয়ে নিশ্চিত করতেন — কেবল ফর্ম পার
     * করতে। ⓘ তখন খাতা নিখুঁত দেখাত, সত্যি হত না। যে নিয়ম মানুষ ফাঁকি
     * দিতে বাধ্য হয়, সেটা নিয়ম নয়।
     *
     * ⛔ **ছাড়টা এখন বলবৎ**, কেবল এই টীকায় নয় —
     * [[TheCounterKeepsItsOwnDoorTest]]। ⚠️ নাহলে কেউ ছয় মাস পরে "সব
     * চালানে আদেশ লাগবে" নিয়মটা **সম্পূর্ণ** করতে গিয়ে সার্ভিসেও
     * পাহারাটা বসাতেন, আর সেটা দেখতে সারাই-এর মতোই লাগত — ⓘ মেপে
     * দেখা: ঐ বদলে [[AChallanIsWrittenAgainstAnOrderTest]]-এর চারটা
     * দাবিই সবুজ থাকে, লাল হয় কেবল কাউন্টারের দুইটা।
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
