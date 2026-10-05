<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use Illuminate\Validation\Rule;

/**
 * সরাসরি বিক্রয়ের (কাউন্টার) যাচাইয়ের তালিকা — এক জায়গায়, ওয়েব আর অ্যাপ দুইজনেরই (৪ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে তালিকাটা [[DirectSaleController::store()]]-এর ভিতরে ছিল; অ্যাপের কাউন্টার (abos-2c, /api/v1/sales/direct)
 * একই নিয়ম চায়। ⛔ দুই জায়গায় দুই তালিকা হলে একদিন একটায় নতুন ঘর বসত আর আরেকটায় না — অ্যাপ দিয়ে একটা
 * যাচাই এড়িয়ে যাওয়া যেত। ⭐ তাই একটাই তালিকা; বিক্রির সব নিয়ম তার পরেও [[DirectSaleService::complete()]]-এ।
 * ⓘ তালিকাটা নিয়ামক থেকে হুবহু কেটে আনা (হাতে লেখা নয়); কেবল `$request` → `$input`, আর সেটিংস `app()` দিয়ে।
 */
final class DirectSaleRules
{
    /**
     * @param  array<string, mixed>  $input  অনুরোধের ঘরগুলো (`$request->all()`) — কিছু নিয়ম অন্য ঘর দেখে
     * @return array<string, mixed>
     */
    public static function store(int $companyId, array $input): array
    {
        return [
            /*
             * ⚠️ ক্রেতা বাধ্যতামূলক — `nullable` ছিল, আর সেটা বিপজ্জনক ছিল।
             *
             * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────────
             * *"Walk-in Customer default bose thakte parbe na, ete vul
             * hobe — karon eta POS na, eta depot/wholesale counter.
             * Obosoi dekhe nishchit hoye party select korte hobe."*
             *
             * ── কেন ডিফল্ট ক্রেতা এখানে ভুল ────────────────────────────
             * দোকানে খুচরা বিক্রিতে ক্রেতা কে তা জানার দরকার নেই — টাকা
             * হাতে আসে, কাগজ শেষ। **ডিপো বা পাইকারিতে উল্টো**: মাল বাকিতে
             * যায়, আর "কার নামে গেল" প্রশ্নটাই পুরো খাতার ভিত্তি।
             *
             * ঘরটা আগে থেকে ভরা থাকলে তাড়াহুড়োয় কেউ না দেখে এগিয়ে যেতেন,
             * আর **পুরো একটা চালান ভুল পার্টির নামে বসে যেত** — টাকা আদায়
             * হত অন্যজনের কাছ থেকে, বকেয়া দেখাত আরেকজনের।
             *
             * ⚠️ পর্দা থেকে ডিফল্ট তোলাই যথেষ্ট নয়। ঘরটা `nullable` থাকলে
             * **ক্রেতাহীন চালান সার্ভার মেনেই নিত** — আর তখন কাগজটা কারও
             * নামেই থাকত না, কোনো ভুলবার্তা ছাড়াই।
             */
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'do_no' => ['nullable', 'string', 'max:64'],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'driver_phone' => ['nullable', 'string', 'max:32'],
            'credit_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            /*
             * ⚠️ ধরনটা **তালিকার বাইরে থেকে আসতে পারে না**।
             *
             * ⛔ পর্দা যা পাঠায় তা বিশ্বাস করার কোনো কারণ নেই — কেউ
             * হাতে অনুরোধ বানালে খাতায় `xyz` লেখা একটা শর্ত বসে যেত,
             * আর প্রতিবেদনে ওটা কোনো ঘরে ফেলা যেত না।
             *
             * ⓘ `null` চলে: পুরনো পর্দা বা অন্য পথ থেকে আসা অনুরোধে
             * ঘরটা থাকে না, আর **না-জানা আর ভুল-জানা এক জিনিস নয়**।
             */
            'payment_term' => ['nullable', 'string',
                Rule::in(['cash', 'cod', 'credit', 'month_end', 'fixed'])],

            /*
             * মেয়াদের দ্বিতীয় মুখ — নির্দিষ্ট তারিখ (৩ সেপ্টেম্বর ২০২৬)।
             *
             * `after_or_equal:trx_date` — বিলের আগের তারিখে পরিশোধের
             * মেয়াদ শেষ হতে পারে না। ওটা বসতে দিলে বকেয়ার বয়সের
             * প্রতিবেদন প্রথম দিন থেকেই মেয়াদোত্তীর্ণ দেখাত।
             */
            'due_on' => ['nullable', 'date', 'after_or_equal:trx_date'],

            /*
             * বিলের নম্বর হাতে লেখা — মালিকের নির্দেশ।
             *
             * ⚠️ `unique` এখানে বসানো হয়নি, ইচ্ছাকৃতভাবে। যাচাই আর
             * সংরক্ষণের মাঝে এক মুহূর্তের ফাঁক থাকে, আর দুইটা কাউন্টার
             * একসাথে একই নম্বর লিখলে দুইটাই ওই যাচাই পাশ করে বেরিয়ে
             * যেত। আসল পাহারা ডাটাবেসের ইউনিক ইনডেক্সে —
             * [[SalesInvoiceService]] সেটা ট্রানজেকশনের ভেতরে ধরে।
             */
            'invoice_no' => ['nullable', 'string', 'max:32'],

            /*
             * ⭐ "খসড়া রাখুন" বোতামের চিহ্ন — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ `in:0,1` ইচ্ছাকৃত, `boolean` নয়। ⚠️ `boolean` `"true"`,
             * `"yes"`, `"on"` সবই মেনে নেয়, আর তখন পর্দার লুকানো ঘরটা
             * কী পাঠাচ্ছে তা নিয়ে দুইটা মত তৈরি হত। ⛔ একটাই মান
             * বোঝানো হয়, আর সেবা ঠিক ঐটাই দেখে।
             */
            'save_as_draft' => ['nullable', 'in:0,1'],

            // ⭐ "আবার করুন" — একই বিল জেনেশুনে আবার; টিকটা আসে কেবল দেয়াল থামালে ([[DirectSaleService::refuseARepeatBill()]])
            'confirm_duplicate' => ['nullable', 'in:0,1'],

            /*
             * ⭐ রাখা খসড়া — মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬: খসড়া পাকা হয়
             * একই পর্দায় ফিরে এসে।
             *
             * ⓘ `resume_invoice_id` কেবল আকার দেখে; কোন খসড়া, কার, এখনো
             * খোলা কি না — সব পাহারা সেবায় ([[DirectSaleService::parkedFor()]]),
             * লেনদেনের ভিতরে তালা দিয়ে। ⚠️ এখানে `exists` বসালে যাচাই আর
             * সংরক্ষণের মাঝের ফাঁকে অন্য কাউন্টার সেটা পাকা করে ফেলতে পারত।
             *
             * ⓘ `screen_state` পর্দার নিজের ছবি (JSON) — ৫০০ KB-এর ঊর্ধ্বসীমা,
             * যাতে কেউ হাতে বানানো অনুরোধে বিলের সারিতে মেগাবাইট ভরতে না পারে।
             */
            'resume_invoice_id' => ['nullable', 'integer', 'min:1'],

            /*
             * ⭐ উৎস (DO …) — ডিপোর যাচাই থেকে খোলা পর্দা (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬)। ⓘ এখানে কেবল আকার;
             * উৎস আছে কি না, কার, এখনো খোলার মতো কি না, আর অনুমোদিতের বেশি কি না — সব সেবায়, তালা দিয়ে
             * ([[DirectSaleService::guardSource()]])।
             */
            'source' => ['nullable', 'string', 'max:16', 'required_with:source_id'],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_with:source'],
            // ⭐ নিশ্চিত বিক্রি সম্পাদনা — [[SaleEditor]]
            'edit_invoice_id' => ['nullable', 'integer', 'min:1'],
            // ⓘ সংশোধনের কারণ — বাধ্যতামূলক, ফাঁকা হলে [[RevisionKeeper::keep()]] নিজের বাংলা বার্তায় থামায়
            'revision_reason' => ['nullable', 'string', 'max:500'],
            'screen_state' => ['nullable', 'string', 'max:500000'],

            // ⭐ বিক্রি নম্বর (S) — হাতে দেওয়া হলে সেটাই; অনন্যতা সেবায় ([[SaleNumber::begin()]])
            'challan_no' => ['nullable', 'string', 'max:32'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'expense_amount' => ['nullable', 'numeric', 'min:0'],
            /*
             * খরচটা কীসের — টাকার অঙ্কের পাশে বাধ্যতামূলক নয়, কিন্তু
             * থাকলে কাগজে ছাপা হয়।
             *
             * ── কেন অঙ্ক থাকলে কারণটাও চাওয়া হয় ──────────────────────
             * "খরচ ২০০" এক মাস পরে কারও কোনো কাজে আসে না। ওটা ভাড়া ছিল,
             * না হাম্মালি, না নাশতা — জানার একমাত্র সময় এখনই, যখন যিনি
             * টাকাটা দিয়েছেন তিনি সামনেই দাঁড়ানো।
             */
            /*
             * ⚠️ `required_with` ছিল, আর সেটা ভুল ছিল (মাপা ৩ সেপ্টেম্বর ২০২৬)।
             *
             * `required_with:expense_amount` চালু হয় ঘরটা **উপস্থিত ও খালি
             * নয়** হলে। খরচের ঘরে কেউ `0` লিখলে ওটা উপস্থিত আর খালি নয় —
             * তাই **শূন্য খরচেও কারণ চাওয়া হত**, আর ব্যবহারকারী আটকে যেতেন
             * এমন একটা ঘরে যেটা তাঁর দরকারই ছিল না।
             *
             * এখন শর্তটা **অঙ্কে**, উপস্থিতিতে নয়: টাকা গেলে কারণ লাগবে,
             * না গেলে নয়।
             */
            'expense_narration' => ['nullable', 'string', 'max:191',
                Rule::requiredIf(fn (): bool => (float) ($input['expense_amount'] ?? 0) > 0)],

            /*
             * পরিবহন — গাড়ি ও চালক আগে থেকেই ছিল, ভাড়াটা ছিল না।
             *
             * ভাড়াটা খরচের ঘরে ঢুকিয়ে দেওয়া যেত, কিন্তু তাতে "এই চালানে
             * পরিবহনে কত গেল" প্রশ্নের উত্তর আর আলাদা করে পাওয়া যেত না —
             * আর রুটপ্রতি খরচ বের করার একমাত্র উপায় ওটাই।
             */
            'carrier_name' => ['nullable', 'string', 'max:191'],
            /*
             * ⓘ বাহক বাছাই ঐচ্ছিক, আর সেটাই ঠিক: বহরের বাইরের একবারের
             * গাড়ির কোনো চলতি হিসাব থাকে না — টাকা ওই দিনই মেটে। তখন
             * নামটাই যথেষ্ট, আর ভাড়াটা সাধারণ প্রদেয়তে যায়।
             */
            'carrier_id' => ['nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            // ⓘ "পরিবহন লাগবে না (ক্রেতার নিজের)" — `in:0,1`, save_as_draft-এর একই কারণে ([[TransportRule]])
            'own_transport' => ['nullable', 'in:0,1'],

            /*
             * চালানটা কোথায় যাচ্ছে।
             *
             * গ্রাহকের ঠিকানা মাস্টারে আছে, কিন্তু মাল সবসময় সেখানে যায়
             * না — দোকান এক জায়গায়, গুদাম আরেক জায়গায়, আর মাঝে মাঝে
             * সরাসরি বাজারে। কাগজে ভুল ঠিকানা ছাপা মানে গাড়ি ভুল জায়গায়।
             */
            'ship_to' => ['nullable', 'string', 'max:191',
                Rule::requiredIf(fn (): bool => ($input['delivery_mode'] ?? null) === 'send_later')],
            'ship_date' => ['nullable', 'date',
                Rule::requiredIf(fn (): bool => ($input['delivery_mode'] ?? null) === 'send_later')],

            /*
             * ⭐ মাল কীভাবে যাবে আর গাড়ি ও ভাড়া — মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২; D365 Store Commerce / SAP-এর
             * কাউন্টারের ধাঁচ)। ⓘ সবগুলো ঐচ্ছিক — না পাঠালে আজকের আচরণ, হুবহু (ফোন আর পুরনো পর্দা পাঠায় না)।
             * "পরে পাঠানো হবে" হলে ঠিকানা আর তারিখ বাধ্যতামূলক (উপরে)।
             * ভাড়া কে দেবে — আন্তর্জাতিক freight terms: us = Prepaid (আমাদের খরচ), us_add_to_bill = Prepaid & Add
             * (খরচ, আর বিলে আদায়), customer = Collect (ক্রেতা চালককে দেন — খাতায় কিছু নয়), none = ভাড়া নেই।
             */
            'delivery_mode' => ['nullable', 'in:take_now,send_later,pickup_later'],
            'vehicle_owner' => ['nullable', 'in:own,hired,customer,none'],
            'fare_paid_by' => ['nullable', 'in:us,us_add_to_bill,customer,none'],

            /*
             * কাউন্টারে নেওয়া টাকার বিবরণ — অঙ্কটা আগে থেকেই ছিল।
             *
             * নগদ ছাড়া অন্য কিছুতে (চেক, বিকাশ) নম্বর ছাড়া টাকাটা আর
             * খুঁজে পাওয়া যায় না, আর ব্যাংকের কাগজের সাথে মেলানোও যায় না।
             */
            'deposit_method' => ['nullable', 'string', 'max:32'],
            'deposit_ref' => ['nullable', 'string', 'max:64'],
            /*
             * ── রাউন্ডিং — দুই দিকে যায়, কিন্তু সীমার ভেতরে ─────────────
             *
             * ── কেন `min:0` তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬) ──────────
             * রাউন্ডিং **দুই দিকেই** যায়: ৪,৩০০.৪০-কে ৪,৩০০ করতে −০.৪০,
             * আর ৪,২৯৯.৬০-কে ৪,৩০০ করতে +০.৪০। `min:0` থাকায় **অর্ধেক
             * কাজটা করাই যেত না**, আর বিক্রেতা বাধ্য হয়ে ছাড়ের ঘরে বসাতেন
             * — তখন ওটা রিপোর্টে ছাড় হিসেবে গোনা হত।
             *
             * ── আর সীমাটা কেন (মালিকের নির্দেশ) ─────────────────────────
             * ⚠️ সীমা ছাড়া "রাউন্ডিং" ঘরটা **ছাড়ের পিছনের দরজা**: ওখানে
             * ৪৩০ টাকাও বসানো যেত। ছাড়ের নিজের অনুমোদন, সীমা ও রিপোর্ট
             * আছে; রাউন্ডিংয়ের কিছুই নেই — **যে ছাড় রাউন্ডিং সেজে যায়,
             * সেটা কোনো রিপোর্টেই ধরা পড়ে না।**
             *
             * ⓘ সীমাটা কন্ট্রোল প্যানেলে (Sales → সীমা), শূন্য মানে সীমা নেই।
             * ⚠️ পর্দার বাধাটা যথেষ্ট নয় — যে কেউ সরাসরি অনুরোধ পাঠাতে
             * পারে, তাই আসল পাহারা এখানেই।
             */
            'rounding_amount' => ['nullable', 'numeric', function (string $attr, mixed $value, callable $fail) {
                $max = (float) app(SettingsService::class)->get('sales.rounding_max', 0);

                if ($max > 0 && abs((float) $value) > $max) {
                    $fail(__('sales::validation.rounding_over_limit', ['max' => $max]));
                }
            }],
            'deposit' => ['nullable', 'numeric', 'min:0'],

            /*
             * ── একাধিক জমা — প্রতিটার নিজের খাত ─────────────────────────
             *
             * ⭐ ইঞ্জিনটা নতুন নয়: POS-এ `payments[][...]` আগে থেকেই চলছে।
             * এখানে ঘরগুলো একটু আলাদা (তারিখ ও বিবরণ লাগে, ফেরত লাগে না),
             * তাই নামটাও আলাদা — কিন্তু ধরনটা এক।
             *
             * ⚠️ `gt:0` — শূন্য টাকার একটা জমার সারি মানে একটা **খালি
             * আদায়ের কাগজ** খাতায় বসে যাওয়া, যেটা পরে কেউ ব্যাখ্যা করতে
             * পারত না। খালি সারি পর্দাতেই বাদ যায়, আর সার্ভারও নেয় না।
             *
             * ⓘ `deposit` ঘরটা রয়ে গেছে, আর ইচ্ছে করেই: পুরনো পথে আসা
             * অনুরোধ (এবং POS-এর মতো অন্য পর্দা) আগের মতোই চলবে। দুইটা
             * একসাথে এলে `deposits`-ই সত্য, কারণ ওটাই বিস্তারিত।
             */
            'deposits' => ['nullable', 'array', 'max:20'],
            'deposits.*.amount' => ['required', 'numeric', 'gt:0'],
            'deposits.*.payment_method_id' => ['nullable', 'integer',
                Rule::exists('mdm_payment_methods', 'id')->where('company_id', $companyId)],
            /*
             * ⚠️ খাত **বাধ্যতামূলক**, আর এটাই এই কাজের আসল সংশোধন।
             *
             * খালি রাখলে `CollectionService` প্রধান টিলের নগদ খাত ধরে নেয়
             * — অর্থাৎ বিকাশের টাকা নীরবে নগদ হয়ে যেত। ⓘ পুরনো একঘরের
             * পথটায় ওই ডিফল্ট এখনো আছে (নগদ বিক্রয়ে ওটাই ঠিক), কিন্তু
             * যেখানে বিক্রেতা **উপায় বেছে দিচ্ছেন**, সেখানে অনুমান করা
             * মানে তাঁর বাছাইটা ফেলে দেওয়া।
             */
            'deposits.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'deposits.*.ref_date' => ['nullable', 'date'],
            'deposits.*.reference' => ['nullable', 'string', 'max:64'],
            'deposits.*.narration' => ['nullable', 'string', 'max:191'],

            /*
             * ⭐ আদায় ভাউচারের তিনটা ঘর — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ কেন এগুলো এখানে এল ─────────────────────────────────
             * *"জমা যোগ botam clic korle eirokom 100% same pop up open
             * hobe"* — অর্থাৎ কাউন্টারের জমা আদায় ভাউচারের মতোই পূর্ণ
             * হবে: কখন এল, কার হাত দিয়ে এল, আর নগদ হলে কোন নোটে।
             *
             * ⚠️ ঘর তিনটা `vouchers` টেবিলে **আগে থেকেই আছে**
             * (১৪ নভেম্বরের মাইগ্রেশন), তাই নতুন কিছু বসাতে হয়নি —
             * কেবল কাউন্টারের পথটা ওগুলো বহন করত না।
             *
             * ── ⛔ নাম তিন জায়গায় বসাতে হয়, আর একটাও বাদ পড়লে নীরব ───
             * যাচাই এখানে · সারি [[DirectSaleService::depositRows()]]-এ ·
             * ভাউচার [[DirectSaleService::counterVoucher()]]-এ। ⓘ তিনটাই
             * ঘর **হাতে বেছে** নেয়। ⚠️ abos-13 আজ ঠিক এই আকারে একটা
             * ভাঙা জোড় পেয়েছেন: `batch_id` `fillable`-এ ছিল, সেবা ওটা
             * চাইত ও যাচাই করত, তবু সারিতে বসত না — কারণ
             * `replaceLines()`-এর হাতে-বাছা তালিকায় নামটা ছিল না।
             */
            'deposits.*.moved_at' => ['nullable', 'date_format:H:i'],

            /*
             * ⓘ বাহক — কেবল এই কোম্পানির মানুষ।
             *
             * ⚠️ `exists:users,id` যথেষ্ট নয়: ব্যবহারকারীর টেবিলে কোনো
             * কোম্পানি-স্কোপ নেই (বহু-কোম্পানি পিভট ধরে), তাই ঢালাও
             * `exists` অন্য ক্রেতার মানুষকেও মেনে নিত।
             */
            'deposits.*.carried_by' => ['nullable', 'integer',
                Rule::exists('company_user', 'user_id')
                    ->where('company_id', CompanyContext::id())],

            /*
             * নোটের হিসাব — চাবি নোটের মান, মান কতটা।
             *
             * ⚠️ যোগফলটা এখানে মেলানো হয় না, আর সেটা ইচ্ছাকৃত: গোনা
             * টাকা আর লেখা টাকা আলাদা হতেই পারে (বিক্রেতা গুনতে ভুল
             * করেন, বা আংশিক গোনেন)। ⓘ পর্দা পার্থক্যটা **দেখায়**,
             * আটকায় না — ঠিক আদায় ভাউচারের মতোই।
             */
            'deposits.*.note_counts' => ['nullable', 'array'],
            'deposits.*.note_counts.*' => ['nullable', 'integer', 'min:0', 'max:100000'],

            /*
             * ⭐ ব্যাংক/মোবাইলের বিস্তারিত — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬:
             * *"counter e bank e taka nile ei porda asena tik koro"*।
             *
             * ⓘ হুবহু রসিদ ভাউচারের ঘর ও নিয়ম ([[VoucherRequest]]) — নাম তিন
             * জায়গায়: এখানে · [[DirectSaleService::depositRows()]] ·
             * [[DirectSaleService::counterVoucher()]]। ⚠️ একটা বাদ পড়লে ঘরটা
             * পর্দায় থাকে, ভাউচারে পৌঁছায় না, আর কেউ টের পায় না।
             */
            'deposits.*.transfer_mode_id' => ['nullable', 'integer',
                Rule::exists('mdm_transfer_modes', 'id')],
            'deposits.*.from_bank' => ['nullable', 'string', 'max:120'],
            'deposits.*.from_branch' => ['nullable', 'string', 'max:120'],
            'deposits.*.from_account_name' => ['nullable', 'string', 'max:120'],
            'deposits.*.from_account_no' => ['nullable', 'string', 'max:64'],
            'deposits.*.deposit_slip_no' => ['nullable', 'string', 'max:64'],
            'deposits.*.charge_amount' => ['nullable', 'numeric', 'min:0'],
            'deposits.*.charge_borne_by' => ['nullable', Rule::in(['us', 'them'])],
            'deposits.*.lands_on' => ['nullable', 'date'],
            'deposits.*.wallet' => ['nullable', 'string', 'max:32'],
            'deposits.*.wallet_medium' => ['nullable', 'string', 'max:32'],
            'deposits.*.counterparty_phone' => ['nullable', 'string', 'max:20'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
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
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // ⓘ উৎসের কোন সারি — না থাকলে সেবা পণ্য ধরে মেলায় (সারি মুছে আবার বসালে আইডি হারায়)
            'lines.*.source_line_id' => ['nullable', 'integer', 'min:1'],

            /*
             * ⭐ লট — মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬: বাছা বাধ্যতামূলক।
             *
             * ⚠️ এখানে `nullable`, আর সেটা ইচ্ছাকৃত — ⓘ "বাধ্যতামূলক" কেবল
             * **লট ধরা** পণ্যে, আর কোন পণ্য কোনটা তা এই নিয়ম জানে না।
             * ⛔ `required` লিখলে চাল-ডাল-সাবানের প্রতিটা সারিও লট চাইত।
             *
             * ⓘ আসল দেয়ালটা সেবায় ([[DirectSaleService]]), কারণ সেখানেই
             * পণ্যটা হাতে আসে। ⚠️ এখানকার নিয়মটা কেবল **আকার** দেখে:
             * সংখ্যা কি না, আর এই কোম্পানির লট কি না।
             */
            'lines.*.batch_id' => ['nullable', 'integer',
                Rule::exists('inv_batches', 'id')->where('company_id', $companyId)],

            'gifts' => ['nullable', 'array'],
            'gifts.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.against_product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.qty' => ['nullable', 'numeric', 'min:0'],
            'gifts.*.remarks' => ['nullable', 'string', 'max:191'],
        ];
    }
}
