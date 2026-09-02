<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use App\Core\Services\PartyRegistry;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ভাউচারের ইনপুট যাচাই — অলঙ্ঘনীয় শর্ত ৪।
 *
 * দুই আকারের ফর্ম এক ক্লাসে: সহজ ফর্মে (আদায়, পরিশোধ, খরচ, কন্ট্রা)
 * দুইটা খাত ও একটা অঙ্ক; জাবেদায় যত খুশি সারি। আলাদা দুইটা ক্লাস করলে
 * তারিখ, বিবরণ ও চেকের নিয়মগুলো দুইবার লিখতে হত।
 */
class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isJournal(): bool
    {
        return $this->input('type') === Voucher::JOURNAL;
    }

    /**
     * পর্দার একটামাত্র "পক্ষ" ঘরটাকে ধরন ও নামে ভাগ করা।
     *
     * ── কেন পর্দায় একটা ঘর, অথচ খতিয়ানে দুইটা কলাম ────────────────
     * দুইটা আলাদা ঘর দিলে একটা ভরে অন্যটা খালি রাখা যেত, আর খতিয়ানে
     * একটা আধা-পক্ষ বসত — যাকে বকেয়ার রিপোর্ট কোনোদিন খুঁজে পেত না।
     * একটা তালিকা থেকে বাছলে ওই ভুলটা করাই যায় না।
     *
     * ── কেন এখানে, কন্ট্রোলারে নয় ──────────────────────────────────
     * কন্ট্রোলার জাবেদার সারিগুলো কাঁচা ইনপুট থেকে নেয়। এখানে ভাগ
     * করলে যাচাই ও সংরক্ষণ **দুইটাই** একই ভাগ করা মান দেখে, তাই
     * মাঝখানে ফাঁক থাকে না।
     */
    protected function prepareForValidation(): void
    {
        $lines = (array) $this->input('lines', []);

        if ($lines === []) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line) || ! array_key_exists('party', $line)) {
                continue;
            }

            $picked = trim((string) $line['party']);
            unset($lines[$index]['party']);

            if ($picked === '' || ! str_contains($picked, ':')) {
                continue;
            }

            [$type, $id] = explode(':', $picked, 2);

            $lines[$index]['party_type'] = trim($type);
            $lines[$index]['party_id'] = (int) $id;
        }

        $this->merge(['lines' => $lines]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::in(Voucher::TYPES)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer'],

            'party_type' => ['nullable', 'string', 'max:32'],
            'party_id' => ['nullable', 'integer'],

            'instrument' => ['nullable', Rule::in(['cash', 'cheque', 'mfs', 'transfer', 'card'])],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'instrument_date' => ['nullable', 'date'],
        ];

        if ($this->isJournal()) {
            return $rules + [
                'lines' => ['required', 'array', 'min:2'],
                'lines.*.account_id' => ['nullable', 'integer'],
                'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.narration' => ['nullable', 'string', 'max:500'],

                /*
                 * সারি ধরে পক্ষ — এক ভাউচারে দুই পক্ষ বসানোর জন্য।
                 *
                 * ── কেন লাগে ─────────────────────────────────────────
                 * পরিবেশকের রোজকার ঘটনা: ডিলার টাকাটা কোম্পানিকে
                 * সরাসরি দিলেন। তখন এক এন্ট্রিতে **ডেবিট সরবরাহকারী,
                 * ক্রেডিট ডিলার** — দুইটা আলাদা পক্ষ, একই ভাউচারে।
                 * মাথার একটামাত্র পক্ষ দিয়ে ওটা লেখাই যেত না।
                 *
                 * ── কেন যাচাইটা এখানে ─────────────────────────────────
                 * `VoucherService` ও `VoucherLine` লাইনের পক্ষ আগে
                 * থেকেই বোঝে, আর কন্ট্রোলার জাবেদার সারিগুলো **কাঁচা
                 * ইনপুট** থেকে নেয় (`$request->input('lines')`)। অর্থাৎ
                 * ঘরটা পর্দায় না থাকলেও যে কেউ অনুরোধে
                 * `lines[0][party_type]=whatever` পাঠিয়ে খতিয়ানে এমন
                 * একটা পক্ষ বসিয়ে দিতে পারত যা কোনো রিপোর্ট চেনে না —
                 * আর বকেয়াটা তখন কোথাও দেখা যেত না। নিয়ম ৪: প্রতিটা
                 * ইনপুটে ভ্যালিডেশন।
                 */
                'lines.*.party_type' => ['nullable', 'string', 'max:32',
                    Rule::in(app(PartyRegistry::class)->types())],
                'lines.*.party_id' => ['nullable', 'integer'],
            ];
        }

        return $rules + [
            'from_account_id' => ['required', 'integer', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * জাবেদার সারিগুলো নিয়ে দুইটা কথা, যা এক-একটা সারি দেখে বলা যায় না।
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isJournal()) {
                    return;
                }

                $debit = '0';
                $credit = '0';
                $filled = 0;

                foreach ((array) $this->input('lines', []) as $index => $line) {
                    $d = (string) ($line['debit'] ?? 0);
                    $c = (string) ($line['credit'] ?? 0);

                    $d = $d === '' ? '0' : $d;
                    $c = $c === '' ? '0' : $c;

                    $hasMoney = bccomp($d, '0', 4) > 0 || bccomp($c, '0', 4) > 0;

                    if ($hasMoney && blank($line['account_id'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.account_id",
                            __('accounts::validation.line_needs_account'));
                    }

                    // একই সারিতে দুই দিকেই টাকা মানে আসলে দুইটা সারি —
                    // মিলিয়ে লিখলে লেজারে ওই খাতের প্রকৃত চলাচল হারায়
                    if (bccomp($d, '0', 4) > 0 && bccomp($c, '0', 4) > 0) {
                        $validator->errors()->add("lines.{$index}.debit",
                            __('accounts::validation.line_both_sides'));
                    }

                    $this->checkParty($validator, $index, $line);

                    if ($hasMoney) {
                        $filled++;
                        $debit = bcadd($debit, $d, 4);
                        $credit = bcadd($credit, $c, 4);
                    }
                }

                if ($filled < 2) {
                    $validator->errors()->add('lines', __('accounts::validation.journal_needs_two_lines'));

                    return;
                }

                if (bccomp($debit, $credit, 4) !== 0) {
                    $validator->errors()->add('lines', __('accounts::validation.not_balanced', [
                        'debit' => Money::format($debit),
                        'credit' => Money::format($credit),
                    ]));
                }
            },
        ];
    }

    /**
     * সারির পক্ষটা সত্যিই আছে কি না, আর অর্ধেক লেখা নয় তো।
     *
     * ── কেন "অর্ধেক" আলাদা করে দেখা হয় ──────────────────────────────
     * ধরন দিয়ে নাম না দিলে (বা উল্টোটা) খতিয়ানে একটা আধা-পক্ষ বসত।
     * বকেয়ার রিপোর্ট `party_type` **আর** `party_id` দুইটা মিলিয়ে
     * খোঁজে, তাই ওই সারিটা কোনো ডিলারের নামের নিচে আসত না — অথচ
     * ভাউচারটা দেখতে ঠিকই থাকত, আর টাকাটা কার সেটা আর জানা যেত না।
     *
     * @param  array<string, mixed>  $line
     */
    private function checkParty(Validator $validator, int|string $index, array $line): void
    {
        $type = trim((string) ($line['party_type'] ?? ''));
        $id = (int) ($line['party_id'] ?? 0);

        if ($type === '' && $id === 0) {
            return;
        }

        if ($type === '' || $id === 0) {
            $validator->errors()->add("lines.{$index}.party_id",
                __('accounts::validation.party_half_written'));

            return;
        }

        if (! app(PartyRegistry::class)->exists($type, $id)) {
            $validator->errors()->add("lines.{$index}.party_id",
                __('accounts::validation.party_unknown'));
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'trx_date' => __('accounts::field.date'),
            'from_account_id' => __('accounts::field.from_account'),
            'to_account_id' => __('accounts::field.to_account'),
            'amount' => __('accounts::field.amount'),
            'narration' => __('core.table.narration'),
        ];
    }
}
