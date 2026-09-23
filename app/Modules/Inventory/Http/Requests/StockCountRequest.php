<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * মাল গোনার ইনপুট।
 *
 * ── ⚠️ যা এখানে যাচাই হয় না, ইচ্ছাকৃতভাবে ────────────────────────────
 * একই পণ্য দুইবার, বা লটের প্রয়োজন — দুইটাই [[StockCountService]]-এ।
 * ⓘ কারণ ওগুলো ব্যবসার নিয়ম, আর নিয়মটা সেখানেই থাকা দরকার যেখানে
 * স্টক নড়ে। ⛔ দুই জায়গায় লিখলে একদিন একটা বদলাত আর অন্যটা নয়।
 *
 * ── ⛔ আর গোনা শূন্য হতে পারে ─────────────────────────────────────────
 * ⚠️ `gt:0` নয়, `min:0` — কারণ *"তাকে কিছুই নেই"* একটা সম্পূর্ণ বৈধ
 * গণনা, আর সেটাই সবচেয়ে গুরুত্বপূর্ণ সারি। ⓘ শূন্য আটকালে ঘাটতিটা
 * কোনোদিন লেখাই যেত না।
 */
class StockCountRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'count_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.counted_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only(['warehouse_id', 'count_date', 'narration']);
    }

    /**
     * শুধু যে সারিগুলো সত্যিই ভরা হয়েছে।
     *
     * ── ⛔ আর এখানেই সবচেয়ে বিপজ্জনক নিয়মটা ─────────────────────────
     * ⚠️ **গোনা-হয়নি ≠ শূন্য**। ⓘ যে সারিতে সংখ্যা বসানো হয়নি সেটা
     * বাদ যায়, আর বাদ যাওয়া মানে *"এই পণ্যটা গোনা হয়নি"* — খাতার
     * সংখ্যা অক্ষত থাকে।
     *
     * ⛔ খালি ঘরকে শূন্য ধরলে একটা অর্ধেক-গোনা শিট সংরক্ষণ করামাত্র
     * বাকি পুরো গুদাম **শূন্য হয়ে যেত**, আর অনুমোদনের পর সেটা আর
     * ফেরানো যেত না।
     *
     * @return list<array<string, mixed>>
     */
    public function lineData(): array
    {
        return array_values(array_filter(
            $this->validated()['lines'] ?? [],
            fn (array $line) => filled($line['product_id'] ?? null)
                && ($line['counted_qty'] ?? '') !== ''
                && ($line['counted_qty'] ?? null) !== null,
        ));
    }
}
