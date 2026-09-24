<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ভাই-কোম্পানির টাকার ফরম।
 *
 * ── ⛔ যেটা এখানে যাচাই হয় না, আর সেটা ইচ্ছাকৃত ─────────────────────
 * ⚠️ "কোম্পানি দুইটা কি সত্যিই আমার?" — এই প্রশ্নটার উত্তর এখানে
 * দেওয়া হয় না। ⓘ ওটা [[InterCompanyService::record()]]-এ, কারণ সেবাটা
 * কেবল এই ফরম থেকে ডাকা হয় না; কনসোল বা ভবিষ্যতের API থেকেও ডাকা যায়।
 *
 * ⭐ দেয়ালের যাচাই একটাই জায়গায় থাকা দরকার, আর সেটা **সবচেয়ে ভিতরের**
 * জায়গা — নাহলে একদিন কেউ একটা দরজা বানাবে যেটা ফরম পেরোয় না।
 */
class InterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /*
             * ⓘ `exists` দেওয়া হয়েছে কেবল "আইডিটা আদৌ একটা কোম্পানি কি
             * না" বলতে। ⛔ এটা **সদস্যপদের যাচাই নয়** — সেটা সেবায়,
             * আর দুইটা গুলিয়ে ফেললে এখানকার সবুজ দেখে কেউ ভাবত দেয়াল
             * পাহারা দেওয়া হয়ে গেছে।
             */
            'counter_company_id' => ['required', 'integer', 'exists:companies,id'],

            'trx_date' => ['required', 'date'],

            /*
             * ⚠️ `numeric` আর `min:0.0001` — `min:0` নয়।
             * ⓘ শূন্য টাকার একটা লেনদেন দুই খাতায় দুইটা খালি ভাউচার
             * বসাত, আর সেগুলো পরে কেউ মেলাতে গিয়ে সময় নষ্ট করত।
             */
            'amount' => ['required', 'numeric', 'min:0.0001'],

            /*
             * ⓘ কারণটা বাধ্যতামূলক। ছয় মাস পরে "ADI → TCL ৫০,০০০"
             * সারিটা দেখে কেউ বলতে পারবে না কেন, আর তখন দুই পক্ষের
             * হিসাবরক্ষক দুইরকম ব্যাখ্যা দেন।
             */
            'purpose' => ['required', 'string', 'max:500'],

            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
