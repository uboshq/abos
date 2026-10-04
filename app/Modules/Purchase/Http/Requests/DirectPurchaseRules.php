<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Requests;

use App\Modules\Inventory\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * সরাসরি ক্রয়ের যাচাইয়ের তালিকা — এক জায়গায়, জমা আর তার আগের সারাংশ দুইজনেরই (৪ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে তালিকাটা [[DirectPurchaseController::store()]]-এর ভিতরে ছিল; "নিশ্চিত করুন"-এর আগের সারাংশ
 * ([[DirectPurchaseOverviewController]]) একই নিয়ম চায়। ⛔ দুই তালিকা হলে একদিন একটায় নতুন ঘর বসত আর আরেকটায় না,
 * আর সারাংশ এমন বিল দেখাত যা জমায় আটকে যায়। ⭐ তাই একটাই তালিকা — বিক্রির দিকে [[DirectSaleRules]]-এর মতো।
 * ⓘ তালিকাটা নিয়ামক থেকে হুবহু কেটে আনা (হাতে লেখা নয়); কেবল `$request->input(…)` → `$input[…]`।
 */
final class DirectPurchaseRules
{
    /**
     * @param  array<string, mixed>  $input  অনুরোধের ঘরগুলো (`$request->all()`) — কিছু নিয়ম অন্য ঘর দেখে
     * @return array<string, mixed>
     */
    public static function store(int $companyId, array $input): array
    {
        return [
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * ⭐ যেদিন মাল এল — বিলের তারিখ থেকে আলাদা।
             *
             * ⓘ ঐচ্ছিক, আর খালি হলে সেবা `trx_date` ধরে — অর্থাৎ আজকের
             * প্রতিটা ডাক অবিকল আগের মতো।
             *
             * ⚠️ `before_or_equal:today` এখানেও: ভবিষ্যতে মাল আসা যায় না,
             * আর তারিখটা মজুদের চলাচল ঠিক করে।
             */
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * ⭐ ক্রয় চালানের নম্বর — পর্দা এটা ভরে পাঠায়।
             *
             * ⓘ নামটা `bill_no`, কিন্তু সেবায় যায় `document_no` হয়ে
             * (নিচে `store()`-এ)। ⚠️ কারণ যাচাইয়ের ভুল-বার্তা ঘরের নামেই
             * খোঁজা হয়, আর পর্দার ঘরটার নাম `bill_no` — দুই নাম এক না
             * রাখলে ডুপ্লিকেট নম্বরের বার্তাটা কোনোদিন দেখা যেত না।
             */
            'bill_no' => ['nullable', 'string', 'max:32'],

            /*
             * ⭐ পরিশোধের শর্ত — পর্দার `Payment Terms`।
             *
             * ⚠️ তালিকাটা `Rule::in()` দিয়ে বাঁধা, আর সেটা ইচ্ছাকৃত:
             * কলামটা ১৬ অক্ষরের, আর যেকোনো লেখা ঢুকতে দিলে একদিন
             * রিপোর্টে `"3 days Cr"` আর `"credit"` দুইটাই বসে থাকত, আর
             * *"COD-তে কত কিনলাম"* প্রশ্নের উত্তর গোনাই যেত না।
             *
             * ⓘ দিনসংখ্যাটা এখানে আসে না — সেটা `due_on` তারিখে অনুবাদ
             * হয়ে যায়, আর তারিখটাই খাতায় থাকে।
             */
            'payment_term' => ['nullable', 'string',
                Rule::in(['cash', 'credit', 'month_end', 'fixed'])],

            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'due_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * ── আমদানি চালান — পাঁচটাই ঐচ্ছিক ────────────────────────
             *
             * দেশের ভিতরের ক্রয়ে পাঁচটাই খালি, আর সেটাই স্বাভাবিক।
             * ⓘ কিন্তু NEXUS/ABOS এগারোটা শিল্পের জন্য, আর তার একটা
             * আমদানি-রপ্তানি — ওখানে ব্যাংক ও কাস্টমস **এই নম্বরগুলো
             * ধরেই** কাগজ খোঁজে।
             *
             * ⚠️ `be_date`-এ `before_or_equal:today` নেই, আর সেটা
             * ইচ্ছাকৃত: খালাসের তারিখ কাগজে যা লেখা তা-ই, আর কাগজটা
             * হাতে আসে কয়েক দিন পরে। ⓘ ভবিষ্যতের তারিখ আটকানো আছে
             * `trx_date`-এ, কারণ ওটাই খতিয়ানে যায়।
             */
            'lc_no' => ['nullable', 'string', 'max:64'],
            'be_no' => ['nullable', 'string', 'max:64'],
            'be_date' => ['nullable', 'date'],
            'vessel' => ['nullable', 'string', 'max:120'],
            'port_of_entry' => ['nullable', 'string', 'max:120'],

            /*
             * হাতে হাতে দেওয়া টাকা — ঐচ্ছিক।
             *
             * টাকা দিলে কোন খাত থেকে গেল সেটা বলতেই হবে; নইলে পরিশোধটা
             * কোথা থেকে এল তা খাতায় লেখা থাকত না।
             */
            'paid_now' => ['nullable', 'numeric', 'min:0'],
            'paid_from_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
                Rule::requiredIf(fn () => (float) ($input['paid_now'] ?? 0) > 0)],

            /*
             * ── একাধিক জমা ───────────────────────────────────────────
             *
             * বাস্তবে এক বিলের টাকা এক পথে যায় না: কিছু নগদ, বাকিটা
             * চেকে বা bKash-এ। উপরের একক ঘরটা ধরে নিত **একটাই উপায়**,
             * তাই দ্বিতীয় পথটা কোথাও লেখাই হত না।
             *
             * ⓘ উপায়টা `mdm_payment_methods`-এর সারি, কোডের ধ্রুবক নয় —
             * ক্রেতা নিজের উপায় যোগ করতে পারবেন। ⚠️ কিন্তু নতুন সারির
             * `kind` অবশ্যই চেনা মানগুলোর একটা হতে হবে (cash · cheque ·
             * bank · mfs), কারণ পরিশোধের `instrument` ওই মানই নেয় —
             * নাহলে জমাটা নীরবে ব্যর্থ হত।
             *
             * ⚠️ পুরনো `paid_now` ঘরটা রয়ে গেল ইচ্ছে করেই: API, ইমপোর্ট
             * আর সিডার ওটাই পাঠায়। দুইটা একসাথে এলে `deposits` জেতে —
             * বিক্রয়ের দিকেও একই নিয়ম।
             */
            /*
             * ── যে গাড়িটা মাল নিয়ে এল ────────────────────────────────
             *
             * সবগুলোই ঐচ্ছিক: নিজের গাড়িতে মাল এলে ভাড়াও নেই, বাহকও
             * নেই। ⓘ কিন্তু ভাড়া লিখলে **কে আনল সেটা বলা দরকার** —
             * নাহলে টাকাটা কার খাতায় দেনা হবে তা কেউ জানে না, আর
             * পরিবহনকারীর হিসাব কোনোদিন মেলে না।
             *
             * ⚠️ `carrier_name` তবু আলাদা রাখা: একবারের ভাড়া গাড়িকে
             * পক্ষ বানানোর দরকার নেই, আর তখন নামটাই একমাত্র তথ্য।
             */
            'carrier_id' => ['nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
                Rule::requiredIf(fn () => (float) ($input['transport_cost'] ?? 0) > 0
                    && blank($input['carrier_name'] ?? null))],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],

            /*
             * গোটা বিলের ছাড় — টাকায় বা শতাংশে (মালিকের ছবি, সিদ্ধান্ত ক)।
             * ⓘ ভাগটা হয় [[DirectPurchaseService::spreadBillDiscount()]]-এ;
             * মোটের চেয়ে বড় ছাড়ও সেখানেই থামে, কারণ মোটটা সারি থেকে কষতে হয়।
             */
            'bill_discount' => ['nullable', 'numeric', 'min:0',
                Rule::when(($input['bill_discount_mode'] ?? null) === 'percent', ['max:100'])],
            'bill_discount_mode' => ['nullable', Rule::in(['amount', 'percent'])],

            'vehicle_no' => ['nullable', 'string', 'max:40'],
            'driver_name' => ['nullable', 'string', 'max:120'],

            'deposits' => ['nullable', 'array', 'max:20'],
            'deposits.*.amount' => ['required', 'numeric', 'gt:0'],
            'deposits.*.payment_method_id' => ['required', 'integer',
                Rule::exists('mdm_payment_methods', 'id')->where('company_id', $companyId)],
            'deposits.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'deposits.*.reference' => ['nullable', 'string', 'max:64'],
            'deposits.*.ref_date' => ['nullable', 'date'],
            'deposits.*.narration' => ['nullable', 'string', 'max:255'],

            /*
             * ⭐ ব্যাংক/বিকাশের চার্জ — ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ আগে এই দুইটা নিয়ম ছিল না, তাই `validate()` ঘর দুইটা নীরবে
             * ছেঁটে ফেলত: বিকাশে ৯০০ দিলে অ্যাপে কাটত ৯০৫, খাতায় কমত ৯০০ —
             * মাস শেষে ৫ টাকা করে অমিল, আর কোনো পর্দা লাল হত না।
             *
             * ⓘ মালিকের সিদ্ধান্ত: পরিশোধের চার্জ **কোম্পানির খরচ** (৫২১০
             * ব্যাংক চার্জ · ৫২১১ MFS চার্জ), সরবরাহকারীর দেনায় নয় — তাই
             * `charge_borne_by` ডিফল্ট `us`। নাম হুবহু বিক্রয়ের কাউন্টারের
             * ([[DirectSaleController]])।
             *
             * ⚠️ `lt` এখানেও, যদিও [[VoucherService::withCharge()]] নিজেও
             * থামায়: চার্জ অঙ্কের সমান বা বেশি হলে ভুলটা ফর্মের ঘরেই দেখা
             * যায়, বিল-মাল-খাতার কাজ শুরু হওয়ার আগে।
             */
            'deposits.*.charge_amount' => ['nullable', 'numeric', 'min:0', 'lt:deposits.*.amount'],
            'deposits.*.charge_borne_by' => ['nullable', Rule::in(['us', 'them'])],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            /*
             * ⛔ দর শূন্য নয় — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ কারণটা [[PurchaseBillRequest]]-এ বিস্তারিত লেখা: শূন্য দরে
             * মাল ঢুকলে মজুদের মূল্য কম বসে, আর ঐ মাল বিক্রি হলে পুরোটাই
             * মুনাফা বলে গোনা হয়। ⚠️ কোনো পর্দা লাল হয় না।
             *
             * ⛔ চারটা দরজাতেই এক নিয়ম — একটা খোলা রাখলে মাল ওদিক দিয়েই
             * ঢুকত, আর পাহারাটা থাকত নামমাত্র।
             */
            'lines.*.rate' => ['required', 'numeric', 'gt:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
            // ⛔ বিক্রয়দর ছাড়া নয় — মালিক, ৩ অক্টোবর ২০২৬: *"বিক্রয়দর … na dile cart e add hobe na"* ([[DirectPurchaseService::assertPriced()]])
            'lines.*.sales_price' => ['required', 'numeric', 'gt:0'],

            /*
             * দামের নীতি — মুক্ত লেখা নয়, তিনটার একটা।
             *
             * ⚠️ `Rule::in` ছাড়া যেকোনো শব্দ কলামে বসত, আর পরে পণ্য
             * বাছার সময় পর্দা সেটা নোঙর ধরে **কিছুই করত না** — নীরবে।
             */
            'lines.*.pricing_anchor' => ['nullable', 'string', Rule::in(['markup', 'margin', 'sales_price'])],
            'lines.*.pricing_pct' => ['nullable', 'numeric'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],

            /*
             * লট · মেয়াদ · ছাপা দাম — লট ধরা পণ্যে লট নম্বরটা
             * বাধ্যতামূলক, কিন্তু সেটা **এখানে** বলা যায় না: কোন পণ্য
             * লট ধরে তা জানতে পণ্যটা দেখতে হয়।
             *
             * ⓘ শর্তটা তাই সেবা স্তরে ([[BatchService::receive]]), আর
             * সেখানকার বার্তায় পণ্যের নামও থাকে — "কোন সারিতে" প্রশ্নের
             * উত্তরসহ। ⚠️ ক্রয় বিল ও চালানের request দুইটাও হুবহু এই
             * তিনটা নিয়ম ব্যবহার করে; এখানে না থাকায় সরাসরি ক্রয়ের
             * পর্দা দিয়ে লট ধরা পণ্য কেনাই যেত না।
             *
             * ⓘ প্যাকের ঘরটাও এখানে যোগ হলো — কলাম দুইটা
             * (`entered_qty`, `entered_unit_id`) আগে থেকেই আছে, আর বাকি
             * চারটা ক্রয়-সেবা ওগুলো ব্যবহার করে; কেবল এই পর্দাটা
             * কাউকে জিজ্ঞেস করত না।
             */
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * ⭐ ফ্রি পরিমাণের নিজের একক — মালিকের নকশার চতুর্থ ঘর
             * (`QTY. · UOM · FREE QTY · UOM`)।
             *
             * ⓘ না এলে সার্ভিস লাইনের `unit_id`-ই ধরে, অর্থাৎ আগের
             * আচরণ অবিকল। ⚠️ এলে সংখ্যাটা ওই এককেই মনে রাখা হয়
             * (`entered_free_qty` · `free_unit_id`) — নাহলে "১ কার্টন
             * ফ্রি" কাগজে "১২ পিস" হয়ে ফিরত।
             */
            'lines.*.free_unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * উপহার — মিল যা সাথে দিয়ে দিল।
             *
             * ⚠️ `lines`-এর মতো `required` নয়। বেশিরভাগ চালানে কোনো
             * উপহার থাকে না, আর বাধ্যতামূলক করলে প্রতিটা সাধারণ ক্রয়
             * আটকে যেত।
             *
             * ⓘ `against_product_id` ঐচ্ছিক: মিল একটা ক্যালেন্ডার বা
             * ছাতাও পাঠাতে পারে যা কোনো নির্দিষ্ট পণ্যের সাথে নয়।
             * বাধ্যতামূলক করলে ক্যাশিয়ার যেকোনো একটা বেছে নিতেন, আর
             * তখন "কোন পণ্যের সাথে এল" প্রশ্নের উত্তরটা **ভুল** হত —
             * খালি থাকার চেয়েও খারাপ।
             */
            'gifts' => ['nullable', 'array'],
            'gifts.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.qty' => ['required', 'numeric', 'gt:0'],
            'gifts.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],
            'gifts.*.against_product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.remarks' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * লট ধরা পণ্যের প্রতিটা সারিতে লট নম্বর — কিছু লেখার **আগে**।
     *
     * ── কেন এখানে, সেবার পাহারা থাকা সত্ত্বেও ──────────────────────────
     * [[BatchService::receive]] নিজেও আটকায়, কিন্তু সে ডাক পায় বিল
     * নিশ্চিত হওয়ার সময় — আর ততক্ষণে বিলটা খসড়া হয়ে লেখা হয়ে গেছে
     * (সেবার প্রথম লেনদেন)। ⛔ ফলে প্রতিটা ব্যর্থ চেষ্টায় একটা করে
     * খসড়া বিল পড়ে থাকত, আর বার্তাটা বসত `lines` নামে — কোন সারি, তা
     * পর্দা বলতে পারত না।
     *
     * ⭐ এখানে সারি ধরে: `lines.{i}.batch_no` — পর্দা ঠিক ঐ সারির লট-ঘরের
     * নিচে বার্তাটা দেখায়, আর কিছুই লেখা হয় না।
     *
     * ⓘ মেয়াদ বাধ্যতামূলক নয়, অতীতের তারিখও আটকানো নয় — ঘরের নিয়ম
     * ([[BatchService::receive]]: "খালি চলে — সব লটে মেয়াদ থাকে না")
     * তা-ই বলে। নতুন নিয়ম এখানে বানানো হয়নি।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function demandLots(array $lines): void
    {
        $tracked = Product::query()
            ->whereIn('id', array_map(fn (array $line) => (int) $line['product_id'], $lines))
            ->where('track_batch', true)
            ->get()
            ->keyBy('id');

        $missing = [];

        foreach ($lines as $index => $line) {
            $product = $tracked->get((int) $line['product_id']);

            if ($product !== null && trim((string) ($line['batch_no'] ?? '')) === '') {
                $missing['lines.'.$index.'.batch_no'] = __('purchase::lot.needs_lot', [
                    'line' => (int) $index + 1,
                    'product' => $product->name(),
                ]);
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'deposits.*.charge_amount.lt' => __('accounts::validation.charge_eats_the_whole_amount'),
        ];
    }
}
