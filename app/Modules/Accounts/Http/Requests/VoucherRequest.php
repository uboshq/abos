<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::in(Voucher::TYPES)],
            'trx_date' => ['required', 'date'],
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
