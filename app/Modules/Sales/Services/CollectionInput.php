<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ টাকা নেওয়ার একটা আবেদন — ওয়েবের ফর্ম আর ফোনের পাঠানো, দুটোই এই এক আকারে (a5/collection-form চুক্তি, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ ভারসাম্যের নিয়ম: amount + vds + ait + difference = Σ প্রয়োগ করা সারি + অপ্রয়োগকৃত (অপ্রয়োগকৃত ≥ ০, গ্রাহকের খাতায় Cr
 * থাকে)। `amount` = গ্রাহক যা পাঠালেন (পর্দার "পাওয়া টাকা"); ব্যাংক চার্জ তার ভিতরেই — টাকার খাতে আসে amount − চার্জ, চার্জ
 * চার্জের খাতে, আর গ্রাহকের খাতায় পুরো amount (নকশা দফা ৯; আজকের [[CollectionService::confirm()]] আর cb-র চার্জের লাইন)।
 * ⚠️ a5-র খসড়ায় চার্জ amount-এর উপরে ধরা ছিল; a6 (২১ অক্টোবর ২০২৬) দফা ৯ আর আগের আচরণ মেনে ভিতরে করল।
 * ⓘ সারির `kind`: bill (sal_invoices) · debit_note / credit_note (acc_notes) · credit (আগের আদায়ের অপ্রয়োগকৃত, sal_collections)।
 * ক্রেডিটের সারি ঋণাত্মক বা ধনাত্মক — দুটোই চলে, ভেতরে পরম মান। সারি খালি = পুরনো আগে, নিজে থেকে।
 * ⓘ পদ্ধতি আর রেফারেন্স cb-র [[MoneyMethod]]-এর নামে: method → instrument, reference → instrument_no (জমায় deposit_slip_no)।
 */
final readonly class CollectionInput
{
    public const KINDS = ['bill', 'debit_note', 'credit_note', 'credit'];

    public const CREDIT_KINDS = ['credit_note', 'credit'];

    public const DIFFERENCE_KINDS = ['discount', 'rounding'];

    /**
     * @param  array{kind: string, amount: string}|null  $difference
     * @param  list<array{kind: string, id: int, amount: string}>  $lines
     */
    public function __construct(
        public int $customerId,
        public ?int $accountId,
        public string $trxDate,
        public string $amount,
        public ?string $method = null,
        public ?string $reference = null,
        public ?string $valueDate = null,
        public string $bankCharge = '0.0000',
        public string $vds = '0.0000',
        public string $ait = '0.0000',
        public ?array $difference = null,
        public array $lines = [],
        public ?string $narration = null,
        public ?int $transferModeId = null,
        public ?string $depositSlipNo = null,
        public ?string $fromBank = null,
        public ?string $instrumentDate = null,

        /*
         * ⓘ সারি না দিলে পুরনো বিল আগে, নিজে থেকে — কেবল যখন সারির ঘরটাই আসেনি (a4-এর চুক্তি, ২১ অক্টোবর ২০২৬): `lines: []` মানে
         * "কোনো বিলে নয়", সবটা অপ্রয়োগকৃত; `lines` না পাঠালে আগের আচরণ। ওয়েবের পর্দা `lines_given` পাঠায়, তাই সব টিক তুললে বিলে বসে না।
         */
        public bool $autoLines = false,
    ) {}

    /** ⓘ ফোনের পুরনো নাম → cb-র পদ্ধতির নাম ([[MoneyMethod::METHODS]]) — a4-এর ক্লায়েন্ট `bank_transfer`, `cash_deposit` লেখে */
    public static function methodName(mixed $value): ?string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        if ($text === '') {
            return null;
        }

        return match (mb_strtolower($text)) {
            'bank_transfer', 'transfer' => 'transfer',
            'cash_deposit', 'deposit' => 'deposit',
            'cash', 'mfs', 'cheque' => mb_strtolower($text),
            default => $text,
        };
    }

    /**
     * ওয়েবের যাচাই-করা অনুরোধ ([[\App\Modules\Sales\Http\Requests\CollectionRequest]]) থেকে — id-গুলো ভেতরের সংখ্যা।
     *
     * ⓘ পুরনো ফর্মের সারি (`sales_invoice_id`, `amount`) বিল হিসেবে চলে।
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $kind = (string) ($line['kind'] ?? 'bill');
            $kind = in_array($kind, self::KINDS, true) ? $kind : 'bill';
            $raw = trim((string) ($line['id'] ?? $line['sales_invoice_id'] ?? ''));
            $amount = self::abs($line['amount'] ?? '0');

            /*
             * ⓘ পর্দা খোলা কাগজের public_id পাঠায় ([[CollectionOpenItems]]); পুরনো ফর্ম ভেতরের সংখ্যা — দুটোই চলে। ⛔ অচেনা
             * public_id নীরবে বাদ নয়, কারণসহ ফেরত (অর্ধেক বণ্টন বসালে হিসাব গুলিয়ে যায়)।
             */
            $id = ctype_digit($raw) ? (int) $raw : ($raw === '' ? 0 : self::internalId($kind, $raw, (int) ($data['customer_id'] ?? 0)));

            if ($id === null) {
                throw ValidationException::withMessages(['lines' => __('sales::collection_rules.unknown_item')]);
            }

            if ($id <= 0 || bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $lines[] = ['kind' => $kind, 'id' => $id, 'amount' => $amount];
        }

        return new self(
            customerId: (int) ($data['customer_id'] ?? 0),
            accountId: filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null,
            trxDate: (string) ($data['trx_date'] ?? now()->toDateString()),
            amount: self::money($data['amount'] ?? '0'),
            method: self::methodName($data['instrument'] ?? $data['method'] ?? null),
            reference: filled($data['instrument_no'] ?? $data['reference'] ?? null) ? trim((string) ($data['instrument_no'] ?? $data['reference'])) : null,
            valueDate: filled($data['value_date'] ?? null) ? (string) $data['value_date'] : null,
            bankCharge: self::money($data['bank_charge'] ?? '0'),
            vds: self::money($data['vds'] ?? '0'),
            ait: self::money($data['ait'] ?? '0'),
            difference: self::difference($data['difference'] ?? null),
            lines: $lines,
            narration: filled($data['narration'] ?? null) ? (string) $data['narration'] : null,
            transferModeId: filled($data['transfer_mode_id'] ?? null) ? (int) $data['transfer_mode_id'] : null,
            depositSlipNo: filled($data['deposit_slip_no'] ?? null) ? trim((string) $data['deposit_slip_no']) : null,
            fromBank: filled($data['from_bank'] ?? null) ? (string) $data['from_bank'] : null,
            instrumentDate: filled($data['instrument_date'] ?? null) ? (string) $data['instrument_date'] : null,
            autoLines: ! array_key_exists('lines', $data) && blank($data['lines_given'] ?? null),
        );
    }

    /**
     * ফোনের সিঙ্কের আদায়-বদল থেকে — id-গুলো public_id, এখানে ভেতরের সংখ্যায় (a4-এর চুক্তি)।
     *
     * ⓘ নতুন ঘর ঐচ্ছিক: `lines: [{kind, id, amount}]` (a4-এর `{invoice, amount}` = bill; পুরনো `allocations: [{invoiceId, amount}]`
     * = bill), `difference: {kind, amount}`, `vds`, `ait`, `method`, `reference`, `value_date`, `bank_charge`। পুরনো ঘর
     * (`instrument`, `instrumentNo`, `trxDate`, `accountId`, `customerId`) আগের মতো।
     * ⛔ অচেনা কাগজ বা খাত = গোটা বদল ফেরত (অর্ধেক বণ্টন নীরবে বাদ দিলে হিসাব গুলিয়ে যায়)।
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPhone(array $payload): self
    {
        $customer = Customer::query()->inViewedBranch()
            ->where('public_id', (string) ($payload['customerId'] ?? $payload['customer_id'] ?? ''))
            ->first();

        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => __('sales::sync.unknown_customer')]);
        }

        $accountId = null;
        $accountKey = $payload['accountId'] ?? $payload['account_id'] ?? null;

        if (filled($accountKey)) {
            $account = Account::query()->postable()->where('public_id', (string) $accountKey)->first();

            if ($account === null) {
                throw ValidationException::withMessages(['account_id' => __('sales::sync.unknown_money_account')]);
            }

            $accountId = (int) $account->id;
        }

        $raw = [];

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $raw[] = isset($line['invoice']) && ! isset($line['kind'])
                    ? ['kind' => 'bill', 'id' => $line['invoice'], 'amount' => $line['amount'] ?? '0']
                    : ['kind' => $line['kind'] ?? 'bill', 'id' => $line['id'] ?? '', 'amount' => $line['amount'] ?? '0'];
            }
        }

        foreach ((array) ($payload['allocations'] ?? []) as $line) {
            if (is_array($line)) {
                $raw[] = ['kind' => 'bill', 'id' => $line['invoiceId'] ?? '', 'amount' => $line['amount'] ?? '0'];
            }
        }

        $lines = [];

        foreach ($raw as $line) {
            $kind = (string) $line['kind'];

            if (! in_array($kind, self::KINDS, true)) {
                throw ValidationException::withMessages(['lines' => __('sales::collection_rules.unknown_kind', ['kind' => $kind])]);
            }

            $id = self::internalId($kind, (string) $line['id'], (int) $customer->id);

            if ($id === null) {
                throw ValidationException::withMessages(['lines' => __('sales::sync.unknown_invoice')]);
            }

            $amount = self::abs($line['amount']);

            if (bccomp($amount, '0', 4) > 0) {
                $lines[] = ['kind' => $kind, 'id' => $id, 'amount' => $amount];
            }
        }

        $method = self::methodName($payload['method'] ?? $payload['instrument'] ?? null);
        $reference = $payload['reference'] ?? $payload['instrumentNo'] ?? $payload['instrument_no'] ?? null;

        return new self(
            customerId: (int) $customer->id,
            accountId: $accountId,
            trxDate: is_string($payload['trxDate'] ?? $payload['trx_date'] ?? null) ? (string) ($payload['trxDate'] ?? $payload['trx_date']) : now()->toDateString(),
            amount: self::money($payload['amount'] ?? '0'),
            // ⓘ পুরনো অ্যাপ মাধ্যম পাঠায় না — খাত না বললে আগের মতো নগদ
            method: filled($method) ? (string) $method : ($accountId === null ? 'cash' : null),
            reference: filled($reference) ? trim((string) $reference) : null,
            valueDate: filled($payload['value_date'] ?? $payload['valueDate'] ?? null) ? (string) ($payload['value_date'] ?? $payload['valueDate']) : null,
            bankCharge: self::money($payload['bank_charge'] ?? $payload['bankCharge'] ?? '0'),
            vds: self::money($payload['vds'] ?? '0'),
            ait: self::money($payload['ait'] ?? '0'),
            difference: self::difference($payload['difference'] ?? null),
            lines: $lines,
            narration: filled($payload['narration'] ?? null) ? (string) $payload['narration'] : null,
            depositSlipNo: filled($payload['deposit_slip_no'] ?? null) ? trim((string) $payload['deposit_slip_no']) : null,
            // ⓘ ফোন সেটআপের `transfer_modes[].id` (public_id) পাঠায়; সংখ্যাও চলে
            transferModeId: self::transferMode($payload['transfer_mode_id'] ?? $payload['transferModeId'] ?? null),
            autoLines: ! array_key_exists('lines', $payload) && ((array) ($payload['allocations'] ?? [])) === [],
        );
    }

    private static function transferMode(mixed $value): ?int
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        if ($text === '') {
            return null;
        }

        $id = \App\Modules\MasterData\Models\TransferMode::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('company_id', \App\Core\Support\CompanyContext::id())->orWhereNull('company_id'))
            ->when(ctype_digit($text), fn ($q) => $q->whereKey((int) $text), fn ($q) => $q->where('public_id', $text))
            ->value('id');

        if ($id === null) {
            throw ValidationException::withMessages(['transfer_mode_id' => __('sales::collection_rules.unknown_transfer_mode')]);
        }

        return (int) $id;
    }

    /** গ্রাহকের পাওনা থেকে যা কমে — টাকা (চার্জসহ) + VDS + AIT + পার্থক্য ([[Collection::grossToCustomer()]]-এর একই যোগ) */
    public function grossToCustomer(): string
    {
        return bcadd(bcadd(bcadd($this->amount, $this->vds, 4), $this->ait, 4), $this->differenceAmount(), 4);
    }

    public function differenceAmount(): string
    {
        return $this->difference === null ? '0.0000' : $this->difference['amount'];
    }

    public function differenceKind(): ?string
    {
        return $this->difference === null ? null : $this->difference['kind'];
    }

    /** ⓘ রেফারেন্স কোন ঘরে বসে — জমায় জমার স্লিপ, বাকিতে instrument_no ([[MoneyMethod::referenceField()]]) */
    public function referenceColumn(): string
    {
        return $this->method === 'deposit' ? 'deposit_slip_no' : 'instrument_no';
    }

    public function referenceValue(): ?string
    {
        return $this->method === 'deposit' ? ($this->depositSlipNo ?? $this->reference) : $this->reference;
    }

    /**
     * কাগজের ঘরগুলো — পদ্ধতির নিজের ঘর ছাড়া বাকিগুলো খালি ([[MoneyMethod::onlyItsOwn()]])।
     *
     * @return array<string, mixed>
     */
    public function paperFields(): array
    {
        $fields = [
            'instrument' => $this->method,
            'instrument_no' => $this->method === 'deposit' ? null : $this->reference,
            'instrument_date' => $this->instrumentDate,
            'value_date' => $this->valueDate,
            'transfer_mode_id' => $this->transferModeId,
            'deposit_slip_no' => $this->method === 'deposit' ? ($this->depositSlipNo ?? $this->reference) : $this->depositSlipNo,
            'from_bank' => $this->fromBank,
            'bank_charge' => $this->bankCharge,
        ];

        if (class_exists(\App\Core\Support\MoneyMethod::class) && $this->method !== null) {
            $fields = \App\Core\Support\MoneyMethod::onlyItsOwn($fields, 'bank_charge');
        }

        $fields['bank_charge'] = self::money($fields['bank_charge'] ?? '0');

        return $fields;
    }

    /** ফোনের public_id থেকে ভেতরের id — কেবল এই গ্রাহকের কাগজ */
    private static function internalId(string $kind, string $publicId, int $customerId): ?int
    {
        if ($publicId === '') {
            return null;
        }

        $id = match ($kind) {
            // ⓘ বিল গ্রাহক ছাড়াই খোঁজা — অন্যের বিল হলে সেবা কারণসহ ফেরায় ("বিলটা অন্য গ্রাহকের"), আগের ফোনের পথের মতো
            'bill' => SalesInvoice::acrossBranches()->where('public_id', $publicId)->value('id'),
            'debit_note', 'credit_note' => Note::acrossBranches()->where('public_id', $publicId)
                ->where('party_type', 'customer')->where('party_id', $customerId)->value('id'),
            'credit' => Collection::acrossBranches()->where('public_id', $publicId)->where('customer_id', $customerId)->value('id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /** @return array{kind: string, amount: string}|null */
    private static function difference(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $amount = self::abs($value['amount'] ?? '0');
        $kind = (string) ($value['kind'] ?? '');

        if (bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        if (! in_array($kind, self::DIFFERENCE_KINDS, true)) {
            throw ValidationException::withMessages(['difference' => __('sales::collection_rules.unknown_difference')]);
        }

        return ['kind' => $kind, 'amount' => $amount];
    }

    /** ⛔ সাধারণ দশমিক কেবল — "1e5" বা অ্যারে এলে ৫০০ নয়, কারণসহ ফেরত */
    private static function money(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            return '0.0000';
        }

        if (preg_match('/^\d{1,14}(\.\d{1,4})?$/', $text) !== 1) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.not_a_number')]);
        }

        return bcadd($text, '0', 4);
    }

    private static function abs(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return self::money(str_starts_with($text, '-') ? substr($text, 1) : $text);
    }
}
