<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\NoteTarget;
use App\Core\Contracts\NoteTargetLines;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Support\LineMath;
use App\Modules\Accounts\Support\TradeCatalog;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ নোটের সারি যাচাই আর হিসাব — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।
 *
 * ── নিয়মগুলো (আন্তর্জাতিক মান: SAP credit memo, BC, Odoo) ─────────────────
 * - মূল বিল থাকলে পণ্যের সারি বিলের সারি ধরে: পরিমাণ বিলের পরিমাণের বেশি নয়, ভ্যাটের হার বিলের সারির — তালাবদ্ধ।
 * - মূল বিল না থাকলে ভ্যাট ০ — "সমন্বয় নয়" (মূসক আইন ২০১২, ধারা ৪৪: ৬.৭/৬.৮ মূল চালানপত্র ছাড়া সমন্বয় নয়)।
 * - ক্রেডিট নোটের দাম ঠিক করার সারি Dr ৪১০০ বিক্রয় (আয় কমে); মাল ফেরতের সারি ৪১১০ — কিন্তু সেটা বিক্রয় ফেরতের কাগজ তোলে।
 * - সরবরাহকারীর ডেবিট নোট: দামের ছাড়ের যে ভাগের মাল এখনো মজুদে সেটা Cr ১১২০ মজুদ, বিক্রি হয়ে যাওয়া ভাগ Cr ৫১৫০; ভ্যাট
 *   উল্টায় (Cr ২১২০) কেবল সরবরাহকারীর মূসক-৬.৭ নম্বর আর তারিখ থাকলে।
 * - "অন্য পক্ষ" (ব্যক্তি) আর সরবরাহকারীকে ক্রেডিট নোটে কেবল খাতের সারি — পণ্যের দাম তাদের বিলে নেই।
 */
final class NoteLineBuilder
{
    public function __construct(private readonly TradeCatalog $catalog) {}

    /**
     * @param  list<array<string, mixed>>  $input
     * @return array{lines: list<array<string, mixed>>, amount: string, tax: string, total: string, returned: string, first_account_id: int|null}
     */
    /**
     * @param  int|null  $exceptNoteId  পাকা করার সময় আবার মাপলে নোট নিজেকে গোনে না
     */
    public function build(string $type, string $kind, ?NoteTarget $target, array $input, bool $supplierNoteGiven, ?int $exceptNoteId = null): array
    {
        /** @var array<int, string> $usedHere এই নোটেরই আগের সারিগুলো যতটা নিল, বিলের সারি ধরে */
        $usedHere = [];
        $billLines = $target instanceof NoteTargetLines ? collect($target->noteTargetLines())->keyBy('line_id') : collect();
        $trade = $this->productsAllowed($type, $kind);
        $vatAllowed = $target !== null && ($type !== Note::TYPE_DN || $supplierNoteGiven);
        $allowed = collect($this->accountChoices($type))->keyBy('id');

        $lines = [];
        $sums = ['amount' => '0', 'tax' => '0', 'total' => '0', 'returned' => '0'];
        $firstAccount = null;
        $no = 0;

        foreach (array_values($input) as $i => $row) {
            $lineType = (string) ($row['type'] ?? $row['line_type'] ?? NoteLine::ACCOUNT);
            $field = 'lines.'.$i;

            if (! in_array($lineType, NoteLine::TYPES, true)) {
                throw ValidationException::withMessages([$field => __('accounts::note.line_type_unknown')]);
            }

            if ($lineType !== NoteLine::ACCOUNT && ! $trade) {
                throw ValidationException::withMessages([$field => __('accounts::note.line_only_accounts')]);
            }

            $line = [
                'line_type' => $lineType,
                'product_id' => null,
                'item_name' => null,
                'description' => $this->text($row['description'] ?? null, 255),
                'source_line_id' => null,
                'billed_qty' => null,
                'unit' => null,
                'tax_id' => null,
                'goods_moved' => false,
                'stock_share' => null,
            ];
            $vatRate = '0';

            if ($lineType === NoteLine::PRODUCT) {
                $sourceId = (int) ($row['source_line_id'] ?? 0);

                if ($target !== null) {
                    $bill = $billLines->get($sourceId);

                    // ⛔ বিল বাছা থাকলে পণ্যের সারি বিলেরই — অন্য পণ্য বা অন্য বিলের সারি নয়
                    if ($bill === null) {
                        throw ValidationException::withMessages([$field => __('accounts::note.line_not_on_bill', ['no' => $target->noteTargetNumber()])]);
                    }

                    $qty = LineMath::money($row['qty'] ?? '0');

                    /*
                     * ⛔ পরিমাণ বিলের জায়গার বেশি নয় — বিলের পরিমাণ − পাকা ফেরত − অন্য নোটে ফেরতের অপেক্ষায় থাকা মাল − এই নোটের আগের সারি
                     * (মালিকের নির্দেশ, ২৩ নভেম্বর ২০২৬)। ⓘ বেশি ফেরত লেখার মুহূর্তেই থামে — মালিক সই দেওয়ার পরে ফেরতের কাগজে নয়।
                     */
                    $room = bcsub($this->roomOnTheLine($target, $bill, $exceptNoteId), $usedHere[(int) $bill['line_id']] ?? '0', 4);

                    if (bccomp($qty, $room, 4) > 0) {
                        throw ValidationException::withMessages([$field => __('accounts::note.qty_over_room', [
                            'name' => $bill['name'],
                            'qty' => TradeCatalog::rate(bccomp($room, '0', 4) > 0 ? $room : '0'),
                            'billed' => TradeCatalog::rate((string) $bill['qty']),
                        ])]);
                    }

                    $line['product_id'] = (int) $bill['product_id'];
                    $line['item_name'] = (string) $bill['name'];
                    $line['unit'] = (string) $bill['unit'];
                    $line['source_line_id'] = (int) $bill['line_id'];
                    $line['billed_qty'] = LineMath::money($bill['qty']);
                    // ⛔ ভ্যাটের হার বিলের সারির — পর্দা যা-ই পাঠাক
                    $vatRate = $vatAllowed ? (string) $bill['vat_rate'] : '0';
                    $line['goods_moved'] = in_array($type, [Note::TYPE_CN, Note::TYPE_DN], true) && filter_var($row['goods_moved'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    // ⓘ ফেরতের সারি এই নোটের পরের সারির জায়গা খায় (দামের সারি খায় না — একই মালে দুই দামের সমন্বয় হতে পারে)
                    if ($line['goods_moved']) {
                        $usedHere[(int) $bill['line_id']] = bcadd($usedHere[(int) $bill['line_id']] ?? '0', $qty, 4);
                    }

                    if ($type === Note::TYPE_DN) {
                        $billed = LineMath::money($bill['qty']);
                        $inStock = $bill['in_stock'] === null ? $billed : LineMath::money($bill['in_stock']);
                        $share = bccomp($billed, '0', 4) > 0 ? bcdiv($inStock, $billed, 6) : '1.000000';
                        $line['stock_share'] = match (true) {
                            bccomp($share, '1', 6) > 0 => '1.000000',
                            bccomp($share, '0', 6) < 0 => '0.000000',
                            default => $share,
                        };
                    }

                    // ⓘ মাল ফেরতের সারির দর বিলের নিট দর — ফেরতের কাগজ যে দরে তুলবে, পর্দাও সেই দরে দেখায়
                    $rateIn = $line['goods_moved'] ? (string) $bill['rate'] : (string) ($row['rate'] ?? '0');
                    $amountIn = $line['goods_moved'] ? null : ($row['amount'] ?? null);
                } else {
                    $product = $this->catalog->product((int) ($row['product_id'] ?? 0));

                    if ($product === null) {
                        throw ValidationException::withMessages([$field => __('accounts::note.product_unknown')]);
                    }

                    $line['product_id'] = $product['id'];
                    $line['item_name'] = $product['name'];
                    $line['unit'] = $product['unit'];
                    // ⓘ বিল ছাড়া কোন স্তরের মাল জানা নেই — সরবরাহকারীর নোটে পুরোটা ক্রয়মূল্যের পার্থক্যে (৫১৫০), মজুদের খাত ছোঁয় না
                    $line['stock_share'] = $type === Note::TYPE_DN ? '0.000000' : null;
                    $rateIn = (string) ($row['rate'] ?? '0');
                    $amountIn = $row['amount'] ?? null;
                }

                $line['account_id'] = $this->productAccount($type, (bool) $line['goods_moved']);
            } elseif ($lineType === NoteLine::SERVICE) {
                $line['item_name'] = $this->text($row['item_name'] ?? null, 191);

                if ($line['item_name'] === null) {
                    throw ValidationException::withMessages([$field => __('accounts::note.service_name_needed')]);
                }

                $line['account_id'] = $this->chosenAccount($row, $allowed, $field);
                $line['unit'] = $this->text($row['unit'] ?? null, 32);
                [$line['tax_id'], $vatRate] = $this->chosenVat($row, $vatAllowed);
                $rateIn = (string) ($row['rate'] ?? '0');
                $amountIn = $row['amount'] ?? null;
            } else {
                $line['account_id'] = $this->chosenAccount($row, $allowed, $field);
                [$line['tax_id'], $vatRate] = $this->chosenVat($row, $vatAllowed);
                $row['qty'] = '1';
                $rateIn = (string) ($row['amount'] ?? $row['rate'] ?? '0');
                $amountIn = null;
            }

            $figures = LineMath::figures((string) ($row['qty'] ?? '1'), $rateIn, $amountIn === null ? null : (string) $amountIn, $vatRate);

            // ⛔ ঋণাত্মক পরিমাণ × ঋণাত্মক দর = ধনাত্মক অঙ্ক — পরিমাণ নিজে শূন্যের বেশি না হলে থামে
            if (bccomp($figures['qty'], '0', 4) <= 0) {
                throw ValidationException::withMessages([$field => __('accounts::note.qty_must_be_positive')]);
            }

            if (bccomp($figures['amount'], '0', 4) <= 0) {
                throw ValidationException::withMessages([$field => __('accounts::note.amount_must_be_positive')]);
            }

            $line = [...$line,
                'line_no' => ++$no,
                'qty' => $figures['qty'],
                'rate' => $figures['rate'],
                'amount' => $figures['amount'],
                'vat_rate' => LineMath::money($vatRate),
                'vat_amount' => $figures['vat'],
                'total' => $figures['total'],
            ];

            if ($line['goods_moved']) {
                $sums['returned'] = bcadd($sums['returned'], $figures['total'], 4);
            } else {
                $sums['amount'] = bcadd($sums['amount'], $figures['amount'], 4);
                $sums['tax'] = bcadd($sums['tax'], $figures['vat'], 4);
                $sums['total'] = bcadd($sums['total'], $figures['total'], 4);
                $firstAccount ??= (int) $line['account_id'];
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('accounts::note.no_lines')]);
        }

        return [...$sums, 'lines' => $lines, 'first_account_id' => $firstAccount];
    }

    /**
     * সারির "খাত" ধরনে যে খাতগুলো বাছা যায় — আয় আর খরচের খাত; ক্রেডিট/ডেবিট নোটে ড্যামেজ দাবিও, সরবরাহকারীর নোটে মজুদও।
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function accountChoices(string $type): array
    {
        /*
         * ⛔ মজুদের খাত (১১২০) খাতের সারিতে নয় — হাতে ১১২০ ক্রেডিট করলে মজুদের স্তর বদলাত না, আর মজুদ-মূল্য রিপোর্ট খাতার
         * সাথে মিলত না। মজুদের দামের ছাড় কেবল বিলের পণ্যের সারি দিয়ে, স্তরসহ ([[\App\Core\Contracts\RevaluesStockForNotes]])।
         */
        $extra = [StandardChart::DAMAGE_CLAIM];

        return $this->catalog->accounts([Account::INCOME, Account::EXPENSE], $extra);
    }

    /**
     * ⭐ বিলের সারির এখনো খালি জায়গা — বিলের পরিমাণ − পাকা ফেরত − অন্য (বাতিল নয়) নোটের এখনো ফেরতের কাগজ না-পাওয়া "মাল ফেরত" সারি।
     * ⓘ পাকা নোটের ফেরতের সারির মাল তার ফেরতের কাগজেই গোনা (`returned`) — দুবার নয়।
     *
     * @param  array<string, mixed>  $bill
     */
    private function roomOnTheLine(NoteTarget $target, array $bill, ?int $exceptNoteId): string
    {
        $pending = (string) (\Illuminate\Support\Facades\DB::table('acc_note_lines as l')
            ->join('acc_notes as n', 'n.id', '=', 'l.note_id')
            ->where('n.company_id', \App\Core\Support\CompanyContext::id())
            ->where('n.against_type', $target->noteTargetType())
            ->where('n.against_id', (int) $target->getKey())
            ->whereNull('n.deleted_at')
            ->where('n.status', '<>', \App\Core\Support\DocumentStatus::CANCELLED)
            ->when($exceptNoteId !== null, fn ($q) => $q->where('n.id', '<>', $exceptNoteId))
            ->where('l.source_line_id', (int) $bill['line_id'])
            ->where('l.goods_moved', true)
            ->whereNull('l.return_id')
            ->sum('l.qty') ?: '0');

        return bcsub(bcsub(LineMath::money($bill['qty']), LineMath::money($bill['returned'] ?? '0'), 4), LineMath::money($pending), 4);
    }

    /** পণ্যের সারি কোন পক্ষে চলে — বিলের পক্ষে: ক্রেডিট/গ্রাহকের ডেবিট নোটে গ্রাহক, সরবরাহকারীর ডেবিট নোটে সরবরাহকারী */
    public function productsAllowed(string $type, string $kind): bool
    {
        return match ($type) {
            Note::TYPE_CN, Note::TYPE_CDN => $kind === Note::KIND_CUSTOMER,
            Note::TYPE_DN => $kind === Note::KIND_SUPPLIER,
            default => false,
        };
    }

    /** পণ্যের সারির খাত — ⓘ সরবরাহকারীর ডেবিট নোটে মজুদ (১১২০); বিক্রি হয়ে যাওয়া ভাগ পোস্টের সময় ৫১৫০-এ ([[NotePosting]]) */
    private function productAccount(string $type, bool $moved): int
    {
        $code = match ($type) {
            Note::TYPE_CN => $moved ? StandardChart::SALES_RETURN : StandardChart::SALES,
            Note::TYPE_CDN => StandardChart::SALES,
            default => StandardChart::INVENTORY,
        };

        return (int) StandardChart::accountFor($code)->id;
    }

    /** @param \Illuminate\Support\Collection<int, array{id: int, code: string, name: string}> $allowed */
    private function chosenAccount(array $row, \Illuminate\Support\Collection $allowed, string $field): int
    {
        $id = (int) ($row['account_id'] ?? 0);

        // ⛔ তালিকার বাইরের খাত কখনো নয় — ফর্ম এড়িয়ে পাঠালেও
        if (! $allowed->has($id)) {
            throw ValidationException::withMessages([$field => __('accounts::note.account_not_allowed')]);
        }

        return $id;
    }

    /** @return array{0: int|null, 1: string} */
    private function chosenVat(array $row, bool $vatAllowed): array
    {
        if (! $vatAllowed) {
            return [null, '0'];
        }

        $taxId = (int) ($row['tax_id'] ?? 0);
        $rate = $this->catalog->taxRate($taxId);

        return $rate === null ? [null, '0'] : [$taxId, $rate];
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
