<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ হাম্মালি — মাল নামানো (আনলোডিং) আর তোলা (লোডিং) — এক নিয়ম, সব দরজা (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে")।
 *
 * ── চার পথ ──────────────────────────────────────────────────────────────────────
 *  - `cash`     নগদে দিলাম — অঙ্ক আর কোন টিল থেকে; কেবল নিজের টিল ([[CashTill::mayUse()]], ভাড়ার একই নিয়ম)
 *  - `due`      হাম্মালি ঠিকাদারের কাছে বাকি — Cr প্রদেয় হাম্মালি (২১১৭), ঠিকাদারের নামে; পরে PV দিয়ে মেটে
 *  - `supplier` সরবরাহকারী দিয়েছেন — আমাদের খরচ নেই (কেবল আনলোডিংয়ে)
 *  - `none`     লাগেনি — ছোট কারণ লাগে
 *
 * ── খাতা ────────────────────────────────────────────────────────────────────────
 *  আনলোডিং: Dr মজুদ (১১২০) — মালের দামে, IAS 2 (মাল হাতে পাওয়ার খরচ) — Cr টিল বা ২১১৭।
 *  লোডিং:   Dr লোডিং (৫২১৮, বিক্রয়ের খরচ) — Cr টিল বা ২১১৭।
 *  ⓘ সারিগুলো কাগজের নিজের দাখিলায় বসে ([[lines()]]) — কাগজ বাতিল হলে উল্টো দাখিলায় নিজে ফেরে, আলাদা পথ লাগে না।
 *
 * ── কেন সরবরাহকারীর মডিউলে ─────────────────────────────────────────────────────
 *  ঠিকাদার একজন সরবরাহকারী-পক্ষ (ধরন `LABOUR`, [[SupplierService::openingAccountFor()]] — শুরুর দেনাও ২১১৭-এ), আর ক্রয় ও
 *  বিক্রয় দুই মডিউলই সরবরাহকারীর উপর দাঁড়ায় — তাই নিয়মটা দুজনেরই নাগালে, কেউ কারও ভেতরে হাত দেয় না ([[BoundariesTest]])।
 */
final class LabourCost
{
    public const CASH = 'cash';

    public const DUE = 'due';

    public const SUPPLIER = 'supplier';

    public const NONE = 'none';

    /** @var list<string> মাল নামানো — চারটাই */
    public const UNLOADING = [self::CASH, self::DUE, self::SUPPLIER, self::NONE];

    /** @var list<string> মাল তোলা — সরবরাহকারীর পথ নেই (চালান আমাদের) */
    public const LOADING = [self::CASH, self::DUE, self::NONE];

    /** ঠিকাদারের পক্ষের ধরন ([[MasterListService]]) */
    public const CONTRACTOR_TYPE = 'LABOUR';

    public function __construct(private readonly MoneyAccountRule $money) {}

    /**
     * দরজার যাচাইয়ের আকার — সব দরজা (পর্দা, ফোন) একই ঘর নেয়; আসল নিয়ম [[read()]]-এ।
     *
     * @param  list<string>  $modes
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix, array $modes): array
    {
        return [
            $prefix.'mode' => ['nullable', 'string', 'in:'.implode(',', $modes)],
            $prefix.'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            $prefix.'account_id' => ['nullable', 'integer'],
            $prefix.'contractor_id' => ['nullable', 'integer'],
            $prefix.'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<string> দরজা থেকে সেবায় যাওয়া ঘরের নাম */
    public static function fields(string $prefix): array
    {
        return [$prefix.'mode', $prefix.'amount', $prefix.'account_id', $prefix.'contractor_id', $prefix.'reason'];
    }

    /** এই পথে কি আমাদের টাকা যায় */
    public static function costsUs(?string $mode): bool
    {
        return in_array($mode, [self::CASH, self::DUE], true);
    }

    /**
     * দরজা থেকে আসা বাছাই যাচাই করে কাগজের ঘরে বসানোর মতো করে — খসড়ায়, সংরক্ষণের সময়।
     *
     * ⓘ পথ না পাঠালে সব খালি (এখনো বাছা হয়নি) — কাগজ তবু সংরক্ষণ হয়; থামে কেবল পাকা করার সময় ([[assertComplete()]])।
     * ⓘ নগদ বা বাকিতে অঙ্ক খালি থাকলে `$expected` বসে — কার্টন × দর ([[UnloadingSplit::expected()]])। লেখা অঙ্কই সত্য
     *   (যা সত্যিই দেওয়া হলো) — প্রস্তাবের চেয়ে কম-বেশি হতে পারে।
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $modes  [[UNLOADING]] বা [[LOADING]]
     * @return array<string, mixed> `{prefix}mode`, `{prefix}amount`, `{prefix}account_id`, `{prefix}contractor_id`, `{prefix}payer_id`, `{prefix}reason`
     */
    public function read(array $data, string $prefix, array $modes, ?string $expected = null): array
    {
        $mode = trim((string) ($data[$prefix.'mode'] ?? ''));
        $empty = [
            $prefix.'mode' => null, $prefix.'amount' => '0', $prefix.'account_id' => null,
            $prefix.'contractor_id' => null, $prefix.'payer_id' => null, $prefix.'reason' => null,
        ];

        if ($mode === '') {
            return $empty;
        }

        if (! in_array($mode, $modes, true)) {
            throw ValidationException::withMessages([$prefix.'mode' => __('supplier::labour.pick_one')]);
        }

        $out = [$prefix.'mode' => $mode] + $empty;

        if ($mode === self::NONE) {
            $reason = trim((string) ($data[$prefix.'reason'] ?? ''));

            // ⛔ "লাগেনি" — কেন, সেটা ছোট করে হলেও লেখা থাকে (মালিক: "short reason required")
            if (mb_strlen($reason) < 3) {
                throw ValidationException::withMessages([$prefix.'reason' => __('supplier::labour.needs_reason')]);
            }

            $out[$prefix.'reason'] = mb_substr($reason, 0, 255);

            return $out;
        }

        if ($mode === self::SUPPLIER) {
            return $out;
        }

        // ⓘ নগদ বা বাকি — অঙ্ক; খালি হলে প্রস্তাবিত অঙ্ক
        $raw = trim((string) ($data[$prefix.'amount'] ?? ''));

        if ($raw === '' && $expected !== null) {
            $raw = $expected;
        }

        if ($raw === '' || ! is_numeric($raw) || bccomp($raw, '0', 4) < 0) {
            throw ValidationException::withMessages([$prefix.'amount' => __('supplier::labour.needs_amount')]);
        }

        $out[$prefix.'amount'] = Money::round($raw, 2);

        if ($mode === self::CASH) {
            $accountId = (int) ($data[$prefix.'account_id'] ?? 0);

            if ($accountId <= 0) {
                throw ValidationException::withMessages([$prefix.'account_id' => __('supplier::labour.needs_till')]);
            }

            // ⓘ অন্য শাখার টিল এখানেই "নেই" — খাতের নিজের দেয়াল ([[MoneyAccountRule::assert()]])
            $account = $this->money->assert($accountId, false, $prefix.'account_id');

            if (! $account->isCash()) {
                throw ValidationException::withMessages([$prefix.'account_id' => __('supplier::labour.cash_only')]);
            }

            // ⛔ কেবল নিজের টিল — আর দিলেন যিনি লগইন করে আছেন (ভাড়ার একই নিয়ম, [[FarePayment]])
            if (! CashTill::mayUse(auth()->id(), (int) $account->id)) {
                throw ValidationException::withMessages([$prefix.'account_id' => __('supplier::labour.not_your_till')]);
            }

            $out[$prefix.'account_id'] = (int) $account->id;
            $out[$prefix.'payer_id'] = auth()->id();

            return $out;
        }

        // ⓘ বাকি — কার কাছে, সেটা না জানলে খাতায় নামহীন দেনা বসত (ভাড়ার "পরে দেব"-এর একই কারণ)
        $contractorId = (int) ($data[$prefix.'contractor_id'] ?? 0);

        if ($contractorId <= 0 || ! Supplier::query()->whereKey($contractorId)->exists()) {
            throw ValidationException::withMessages([$prefix.'contractor_id' => __('supplier::labour.needs_contractor')]);
        }

        /*
         * ⛔ কেবল "হাম্মালি ঠিকাদার" ধরনের সরবরাহকারী — পর্দা যা দেখায়, সার্ভারও তাই মানে (সমন্বয়ক, ২০ নভেম্বর ২০২৬)। ⓘ নইলে মালের
         * সরবরাহকারী বা পরিবহনকারীর নামে ২১১৭-এ দেনা বসত, আর প্রদেয় হাম্মালির তালিকায় ভুল মানুষ। ওয়েব, ফোনের মাল গ্রহণ আর ফোনের
         * কাউন্টার — সবাই এই এক দরজা দিয়ে আসে।
         */
        if (! Supplier::query()->whereKey($contractorId)
            ->whereHas('partyType', fn ($q) => $q->where('code', self::CONTRACTOR_TYPE))->exists()) {
            throw ValidationException::withMessages([$prefix.'contractor_id' => __('supplier::labour.not_a_contractor')]);
        }

        $out[$prefix.'contractor_id'] = $contractorId;

        return $out;
    }

    /**
     * ⛔ পাকা করার আগে বাছাই পূর্ণ — নইলে থামা, পরিষ্কার বার্তায় (মালিক: "paper must NOT confirm/post until one is chosen")।
     *
     * @param  string  $kind  `unloading` বা `loading` — বার্তার জন্য
     */
    public function assertComplete(Model $paper, string $prefix, string $kind): void
    {
        $mode = $paper->getAttribute($prefix.'mode');
        $no = (string) ($paper->getAttribute('document_no') ?? '');

        if ($mode === null || $mode === '') {
            throw ValidationException::withMessages([$prefix.'mode' => __('supplier::labour.'.$kind.'_required', ['no' => $no])]);
        }

        if (self::costsUs($mode) && bccomp((string) ($paper->getAttribute($prefix.'amount') ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([$prefix.'amount' => __('supplier::labour.needs_amount')]);
        }

        if ($mode === self::CASH && $paper->getAttribute($prefix.'account_id') === null) {
            throw ValidationException::withMessages([$prefix.'account_id' => __('supplier::labour.needs_till')]);
        }

        if ($mode === self::DUE && $paper->getAttribute($prefix.'contractor_id') === null) {
            throw ValidationException::withMessages([$prefix.'contractor_id' => __('supplier::labour.needs_contractor')]);
        }

        if ($mode === self::NONE && trim((string) $paper->getAttribute($prefix.'reason')) === '') {
            throw ValidationException::withMessages([$prefix.'reason' => __('supplier::labour.needs_reason')]);
        }
    }

    /**
     * খাতার দুই সারি — Dr `$debitAccountId` / Cr টিল (নগদ) বা ২১১৭ ঠিকাদারের নামে (বাকি)। খরচ না থাকলে খালি।
     *
     * ⓘ কাগজের নিজের দাখিলার সাথে বসানোর জন্য — তাই বাতিলের উল্টো দাখিলায় এগুলোও ফেরে।
     *
     * @return list<array<string, mixed>>
     */
    public function lines(Model $paper, string $prefix, int $debitAccountId, string $narration, ?string $amount = null): array
    {
        $mode = $paper->getAttribute($prefix.'mode');
        $amount ??= (string) ($paper->getAttribute($prefix.'amount') ?? '0');

        if (! self::costsUs($mode) || bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        $debit = ['account_id' => $debitAccountId, 'debit' => bcadd($amount, '0', 4), 'narration' => $narration];

        if ($mode === self::CASH) {
            // ⓘ খাতটা খসড়ার সময় যাচাই হয়েছে; পাকা করেন অন্য হেডার-শাখার কেউ হলেও টিলের খাত "নেই" হবে না ([[FarePayment::payOnConfirm()]])
            $till = Account::query()->withoutGlobalScope('viewed-branch-till')->findOrFail((int) $paper->getAttribute($prefix.'account_id'));

            return [$debit, ['account_id' => (int) $till->id, 'credit' => bcadd($amount, '0', 4), 'narration' => $narration]];
        }

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $payable = StandardChart::accountFor(StandardChart::LABOUR_PAYABLE);

        return [$debit, [
            'account_id' => (int) $payable->id,
            'credit' => bcadd($amount, '0', 4),
            'party_type' => 'supplier',
            'party_id' => (int) $paper->getAttribute($prefix.'contractor_id'),
            'narration' => $narration,
        ]];
    }

    /**
     * পর্দার বাছাইয়ের তালিকা — নিজের নগদ টিল ([[CashTill::mayUse()]]); অন্যের টিল দেখায়ও না।
     *
     * @return list<array{id: string, label: string}>
     */
    public function cashTills(): array
    {
        return Account::query()->notAnotherBranchsTill()
            ->where('is_group', false)
            ->where('is_active', true)
            ->where('money_kind', Account::CASH)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn', 'money_kind'])
            ->filter(fn (Account $a) => CashTill::mayUse(auth()->id(), (int) $a->id))
            ->map(fn (Account $a): array => ['id' => (string) $a->id, 'label' => $a->code.' · '.$a->name()])
            ->values()
            ->all();
    }

    /**
     * হাম্মালি ঠিকাদার — পক্ষের ধরন `LABOUR`, দেখার শাখায়।
     *
     * @return Collection<int, array{id: string, label: string}>
     */
    public function contractors(): Collection
    {
        return Supplier::query()->inViewedBranch()
            ->active()
            ->whereHas('partyType', fn ($q) => $q->where('code', self::CONTRACTOR_TYPE))
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_bn'])
            ->map(fn (Supplier $s): array => ['id' => (string) $s->id, 'label' => $s->name()])
            ->values();
    }

    /** কাগজে দেখানোর এক লাইন — "নগদ · ৫০.০০ · ১১০১ প্রধান টিল" */
    public function describe(Model $paper, string $prefix): string
    {
        $mode = $paper->getAttribute($prefix.'mode');

        if ($mode === null || $mode === '') {
            return __('supplier::labour.not_chosen');
        }

        $parts = [__('supplier::labour.mode_'.$mode)];

        if (self::costsUs($mode)) {
            $parts[] = Money::format((string) $paper->getAttribute($prefix.'amount'));
        }

        if ($mode === self::CASH && ($id = $paper->getAttribute($prefix.'account_id')) !== null) {
            $parts[] = (string) (Account::query()->withoutGlobalScope('viewed-branch-till')->find((int) $id)?->name() ?? '');
        }

        if ($mode === self::DUE && ($id = $paper->getAttribute($prefix.'contractor_id')) !== null) {
            $parts[] = (string) (Supplier::query()->find((int) $id)?->name() ?? '');
        }

        if ($mode === self::NONE && filled($paper->getAttribute($prefix.'reason'))) {
            $parts[] = (string) $paper->getAttribute($prefix.'reason');
        }

        return implode(' · ', array_filter($parts, fn ($p) => $p !== ''));
    }
}
