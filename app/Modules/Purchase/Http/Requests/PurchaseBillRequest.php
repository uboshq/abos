<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ক্রয় বিলের ইনপুট।
 */
class PurchaseBillRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            /*
             * গুদাম ঐচ্ছিক, আর সেটা ইচ্ছাকৃত।
             *
             * যে বিলের প্রতিটা লাইনের পেছনে চালান আছে সেটা কোনো মাল
             * ঢোকায় না — মাল আগেই ঢুকেছে, গুদামও তখনই বলা হয়েছে।
             * বাধ্যতামূলক করলে ব্যবহারকারী এমন একটা ঘর ভরতেন যেটার
             * কোনো প্রভাব নেই, আর একদিন ভুল গুদাম বেছে ভাবতেন মাল
             * সেখানে গেছে। খালি রাখলে প্রধান গুদাম ধরা হয়।
             */
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],

            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date', 'after_or_equal:trx_date'],
            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            // চালানের লাইনটা এই কোম্পানির কি না তা সেবা স্তর দেখে —
            // সন্তান-টেবিলে company_id নেই, বাবার আছে
            'lines.*.purchase_receipt_line_id' => ['nullable', 'integer'],

            /*
             * চালান ছাড়া সরাসরি আদেশের বিপরীতে বিল।
             *
             * দুইটা জোড়া পরস্পর-বিকল্প, তাই দুইটাই ঐচ্ছিক — একটা সারি
             * হয় চালান ধরে আসে, নয় আদেশ ধরে, নয় কোনোটাই।
             */
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],

            // "১০ কার্টন কিনলে ১ কার্টন ফ্রি" — শূন্য চলে, ঋণাত্মক নয়
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],

            /*
             * লট, মেয়াদ ও ছাপা দাম — লট ধরা পণ্যে নম্বরটা বাধ্যতামূলক,
             * কিন্তু সেটা এখানে বলা যায় না: কোন পণ্য লট ধরে চলে তা
             * জানতে পণ্যটা দেখতে হয়। সেবা স্তর নিজে আটকায়, আর বার্তায়
             * পণ্যের নাম থাকে — "কোন সারিতে" প্রশ্নের উত্তরসহ।
             */
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.mrp' => ['nullable', 'numeric', 'min:0'],

            // কোন প্যাকে লেখা — খালি মানে পণ্যের নিজের একক
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            'lines.*.rate' => ['required', 'numeric', 'min:0'],

            /*
             * নতুন বিক্রয়মূল্য — ঐচ্ছিক, আর খালি মানে "দাম বদলাব না"।
             *
             * শূন্যও বৈধ, কারণ শূন্য দামে দেওয়ার সিদ্ধান্ত ডিপো নিতেই
             * পারে। কিন্তু খালি ঘর আর শূন্য এক নয়: nullable রাখা হয়েছে
             * ঠিক এই কারণে, নইলে যে লাইনে কেউ দাম লেখেননি সেটাও পণ্যের
             * দাম শূন্য করে দিত।
             */
            'lines.*.sales_price' => ['nullable', 'numeric', 'min:0'],
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
            'supplier_id', 'trx_date', 'due_on', 'supplier_bill_no', 'narration',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null),
        ));
    }
}
