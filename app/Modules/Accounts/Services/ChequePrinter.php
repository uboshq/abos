<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\PayeeBankAccount;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\AmountInWords;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\ChequeBook;
use App\Modules\Accounts\Models\ChequeLayout;
use App\Modules\Accounts\Models\ChequePrint;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * ⭐ চেক ছাপা — মালিকের অনুমোদিত নমুনা (W63BXTDuqM65tDaKRDnvwR, ১০ নভেম্বর ২০২৬)।
 *
 * দুই দরজা, একই ছাপা:
 * - [[forVoucher()]] — পাকা পরিশোধ ভাউচারের চেক: টাকা = ভাউচারের ব্যাংক খাত থেকে যা বেরোল (হাতে বদলায় না), বই = সেই ব্যাংক
 *   হিসাবের কোম্পানির বই, নম্বর = ভাউচারে লেখা চেক নম্বর থাকলে সেটা, নাহলে বইয়ের পরেরটা। ভাউচার নিজে বদলায় না।
 * - [[free()]] — হিসাব ছাড়া (মালিকের নিজের বই, `off_books`): কোনো ভাউচার নয়, খাতায় কিছু নয় — কেবল ছাপা চেকের খাতায় সারি।
 *
 * পাহারা ([[ChequePrint]]): একই বইয়ের একই নম্বর একবারই; আবার ছাপায় কারণ লাগে আর `times` বাড়ে; নষ্ট পাতা [[spoil()]]।
 * সই কখনো ছাপা হয় না। পাতা = বইয়ের মাপ ([[ChequeLayout]]) — mPDF-এর পাতা ঠিক চেকের মাপের, তাই "আসল মাপে" ছাপলে ঘরে ঘরে বসে।
 */
final class ChequePrinter
{
    public function __construct(private readonly PrintEngine $engine) {}

    /**
     * ভাউচারের চেক — নতুন হলে বইয়ের নম্বর নেয়; আগে ছাপা হলে একই নম্বর আবার, কারণসহ।
     */
    public function forVoucher(Voucher $voucher, ChequeBook $book, string $payee, bool $crossed, ?string $reason = null): ChequePrint
    {
        if ($voucher->type !== Voucher::PAYMENT || ! $voucher->isPosted() || $voucher->instrument !== 'cheque') {
            throw ValidationException::withMessages(['voucher' => __('accounts::cheque_print.voucher_not_ready')]);
        }

        if ($book->off_books || (int) $book->bank_account_id !== (int) $voucher->money_account_id) {
            throw ValidationException::withMessages(['book' => __('accounts::cheque_print.book_not_this_bank')]);
        }

        $amount = $this->paidFromBank($voucher);

        return DB::transaction(function () use ($voucher, $book, $payee, $crossed, $reason, $amount) {
            $book = ChequeBook::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();
            $earlier = ChequePrint::query()->where('voucher_id', $voucher->id)->where('status', ChequePrint::PRINTED)->lockForUpdate()->first();

            if ($earlier !== null) {
                return $this->again($earlier, $payee, $crossed, $reason);
            }

            return $this->take($book, $this->numberFor($book, (string) $voucher->instrument_no), [
                'cheque_date' => $voucher->instrument_date ?? $voucher->trx_date,
                'payee' => $payee, 'amount' => $amount, 'crossed' => $crossed,
                'voucher_id' => $voucher->id, 'off_books' => false,
            ]);
        });
    }

    /**
     * ⭐ চেকের খাতার দেওয়া চেক — সরবরাহকারীর পরিশোধ এখানেই চেক বসায় ([[PaymentService]]: নিট টাকা, ব্যাংক, পক্ষ, তারিখ)।
     * টাকা = চেকের টাকা; বই = সেই ব্যাংকের কোম্পানির বই (চেকে ব্যাংক না থাকলে যেকোনো কোম্পানির বই); নম্বর = চেকে লেখা নম্বর এই বইয়ের
     * হলে সেটা, নাহলে বইয়ের পরেরটা — আর তখন খাতার চেকে ছাপা নম্বরটাই বসে (ব্যাংকের কাগজের সাথে মেলাতে আসল নম্বর লাগে)।
     */
    public function forCheque(Cheque $cheque, ChequeBook $book, string $payee, bool $crossed, ?string $reason = null): ChequePrint
    {
        if ($cheque->direction !== Cheque::ISSUED || $cheque->status !== Cheque::PENDING) {
            throw ValidationException::withMessages(['cheque' => __('accounts::cheque_print.cheque_not_ready')]);
        }

        if ($book->off_books || ($cheque->bank_account_id !== null && (int) $book->bank_account_id !== (int) $cheque->bank_account_id)) {
            throw ValidationException::withMessages(['book' => __('accounts::cheque_print.book_not_this_bank')]);
        }

        return DB::transaction(function () use ($cheque, $book, $payee, $crossed, $reason) {
            $book = ChequeBook::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();
            $earlier = ChequePrint::query()->where('cheque_id', $cheque->id)->where('status', ChequePrint::PRINTED)->lockForUpdate()->first();

            if ($earlier !== null) {
                return $this->again($earlier, $payee, $crossed, $reason);
            }

            $no = $this->numberFor($book, (string) $cheque->cheque_no);
            $print = $this->take($book, $no, [
                'cheque_date' => $cheque->cheque_date, 'payee' => $payee, 'amount' => bcadd((string) $cheque->amount, '0', 4),
                'crossed' => $crossed, 'cheque_id' => $cheque->id, 'voucher_id' => $cheque->voucher_id, 'off_books' => false,
            ]);

            if ($cheque->cheque_no !== $book->number($no)) {
                $cheque->forceFill(['cheque_no' => $book->number($no)])->save();
            }

            return $print;
        });
    }

    /**
     * ⓘ কাগজে লেখা চেক নম্বর এই বইয়ের সীমার ভিতরে আর এখনো না-ছাপা হলে সেটা, নাহলে বইয়ের পরের নম্বর।
     * ⛔ নষ্ট পাতার পরে খাতার চেকে সেই নষ্ট নম্বরই লেখা থাকে — তখন সেটা নয় (প্রথম স্থানীয় রানে ধরা, ১০ নভেম্বর ২০২৬)।
     */
    private function numberFor(ChequeBook $book, string $written): int
    {
        $digits = preg_replace('/\D/', '', $written) ?? '';
        $no = $digits !== '' && strlen($digits) <= 15 ? (int) $digits : 0;
        $usable = $no >= $book->first_no && $no <= $book->last_no
            && ! ChequePrint::query()->where('cheque_book_id', $book->id)->where('cheque_no', $no)->exists();

        return $usable ? $no : $book->next_no;
    }

    /**
     * হিসাব ছাড়া চেক — কেবল `off_books` বই থেকে।
     *
     * @param  array{cheque_date: string, payee: string, amount: string, crossed: bool, purpose?: ?string}  $data
     */
    public function free(ChequeBook $book, array $data): ChequePrint
    {
        if (! $book->off_books) {
            throw ValidationException::withMessages(['book' => __('accounts::cheque_print.book_is_the_companys')]);
        }

        $amount = bcadd($data['amount'], '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('accounts::cheque_print.amount_positive')]);
        }

        return DB::transaction(function () use ($book, $data, $amount) {
            $book = ChequeBook::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();

            return $this->take($book, $book->next_no, [
                'cheque_date' => $data['cheque_date'], 'payee' => $data['payee'], 'amount' => $amount,
                'crossed' => $data['crossed'], 'purpose' => $data['purpose'] ?? null, 'off_books' => true,
            ]);
        });
    }

    /** একই চেক আবার — কারণ ছাড়া নয় (প্রিন্টারে কাগজ আটকানো …) */
    public function again(ChequePrint $print, ?string $payee = null, ?bool $crossed = null, ?string $reason = null): ChequePrint
    {
        if ($print->status !== ChequePrint::PRINTED) {
            throw ValidationException::withMessages(['print' => __('accounts::cheque_print.spoiled_never_again')]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('accounts::cheque_print.reason_for_again')]);
        }

        $print->forceFill([
            'payee' => $payee ?? $print->payee,
            'crossed' => $crossed ?? $print->crossed,
            'times' => $print->times + 1,
            'reason' => mb_substr((string) $reason, 0, 200),
            'printed_by' => auth()->id(),
        ])->save();

        return $print;
    }

    /** নষ্ট পাতা — সারি থাকে, নম্বর আর কখনো নয়; ভাউচারের চেক তখন পরের নম্বরে আবার ছাপা যায় */
    public function spoil(ChequePrint $print, string $reason): ChequePrint
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('accounts::cheque_print.reason_for_spoil')]);
        }

        $print->forceFill(['status' => ChequePrint::SPOILED, 'reason' => mb_substr($reason, 0, 200)])->save();

        return $print;
    }

    /**
     * ভাউচারের ব্যাংক খাত থেকে যা বেরোল — চেকের টাকা এটাই (মোট নয়: কাটা কর বা চার্জ চেকে যায় না)।
     */
    public function paidFromBank(Voucher $voucher): string
    {
        $out = $voucher->lines()->where('account_id', $voucher->money_account_id)->get()
            ->reduce(fn (string $c, VoucherLine $l) => bcadd($c, bcsub((string) $l->credit, (string) $l->debit, 4), 4), '0');

        if (bccomp($out, '0', 4) <= 0) {
            throw ValidationException::withMessages(['voucher' => __('accounts::cheque_print.nothing_left_the_bank')]);
        }

        return $out;
    }

    /** কাগজের পক্ষের নাম চেকে কেমন — সরবরাহকারীর ব্যাংক হিসাবের নাম থাকলে সেটা, নাহলে পক্ষের নাম; বড় হাতের */
    public function payeeOf(Voucher|Cheque $paper): string
    {
        if (blank($paper->party_type) || (int) $paper->party_id <= 0) {
            return '';
        }

        $bank = app(PayeeBankAccount::class)->accountOf((string) $paper->party_type, (int) $paper->party_id);

        if (filled($bank['account_name'] ?? null)) {
            return mb_strtoupper((string) $bank['account_name']);
        }

        $party = app(\App\Core\Engines\Drill\DrillResolver::class)->resolve((string) $paper->party_type, (int) $paper->party_id);

        return $party !== null && method_exists($party, 'name') ? mb_strtoupper((string) $party->name()) : '';
    }

    /**
     * চেকের পাতার PDF — পাতা ঠিক চেকের মাপ। `$test` = সাধারণ কাগজে পরীক্ষার ছাপ: পাতার কিনারা আর ঘরের হালকা দাগ, যাতে চেকের ওপর
     * রেখে আলোয় মেলানো যায়।
     *
     * @param  array{cheque_date: Carbon|string|null, payee: ?string, amount: string|null, crossed: bool}  $cheque
     */
    public function pdf(ChequeLayout $layout, array $cheque, bool $test = false): string
    {
        $fonts = $this->engine->fonts();
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [$layout->width_mm, $layout->height_mm],
            'orientation' => 'P',
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'default_font' => 'hindsiliguri',
            'tempDir' => storage_path('framework/cache'),
            'fontDir' => $fonts['fontDir'],
            'fontdata' => $fonts['fontdata'],
        ]);
        $mpdf->SetTitle('Cheque');
        $mpdf->SetCreator('ABOS');
        $mpdf->AddPage();

        $dx = (string) $layout->offset_x;
        $dy = (string) $layout->offset_y;
        $at = fn (string $v, string $d): string => bcadd($v, $d, 2);
        $ink = 'color: #1d3f9e; font-family: dejavusansmono; font-weight: bold;';

        if ($test) {
            $mpdf->SetDrawColor(150, 150, 150);
            $mpdf->Rect(0.3, 0.3, bcsub((string) $layout->width_mm, '0.6', 2), bcsub((string) $layout->height_mm, '0.6', 2));
            foreach (ChequeLayout::FIELDS as $name) {
                $f = $layout->field($name);
                $mpdf->SetDrawColor(200, 200, 200);
                $mpdf->Rect($at($f['x'], $dx), $at($f['y'], $dy), $f['w'], $name === 'words' ? '14' : '7');
            }
        }

        $date = $cheque['cheque_date'] === null ? null : Carbon::parse($cheque['cheque_date']);

        if ($date !== null) {
            $f = $layout->field('date');
            foreach (str_split($date->format('dmY')) as $i => $digit) {
                $x = bcadd($at($f['x'], $dx), bcmul((string) $i, $f['box'], 2), 2);
                $mpdf->WriteFixedPosHTML('<div style="'.$ink.' font-size: '.$f['size'].'pt; text-align: center;">'.$digit.'</div>', $x, $at($f['y'], $dy), $f['box'], '7', 'visible');
            }
        }

        if (filled($cheque['payee'])) {
            $f = $layout->field('payee');
            // ⓘ বাংলা নাম হলে বাংলা ফন্ট — মোনো ফন্টে বাংলা নেই, ঘর আসত (প্রথম ছাপে দেখা, ১০ নভেম্বর ২০২৬)
            $font = preg_match('/[^\x00-\x7F]/u', (string) $cheque['payee']) === 1 ? 'font-family: hindsiliguri;' : '';
            // ⓘ 'auto' = ঘরে না ধরলে অক্ষর ছোট হয়, ঘরের বাইরে যায় না
            $mpdf->WriteFixedPosHTML('<div style="'.$ink.$font.' font-size: '.$f['size'].'pt;">**'.e((string) $cheque['payee']).'**</div>', $at($f['x'], $dx), $at($f['y'], $dy), $f['w'], '7', 'auto');
        }

        if ($cheque['amount'] !== null) {
            $f = $layout->field('words');
            // ⓘ দুই লাইনের ফাঁক মাপ থেকে (বাংলাদেশ ব্যাংকের মানে 0.35" = 8.9 mm); পুরনো মাপে না থাকলে 7 mm
            $line = isset($f['line']) && bccomp($f['line'], '0', 2) > 0 ? $f['line'] : '7';
            $mpdf->WriteFixedPosHTML('<div style="'.$ink.' font-size: '.$f['size'].'pt; line-height: '.$line.'mm;">**'.e(AmountInWords::cheque($cheque['amount'])).'**</div>', $at($f['x'], $dx), $at($f['y'], $dy), $f['w'], bcmul($line, '2', 2), 'auto');

            $f = $layout->field('figures');
            $whole = bccomp(bcsub(Money::round($cheque['amount'], 2), Money::round($cheque['amount'], 0), 2), '0', 2) === 0;
            $figure = Money::format($cheque['amount'], $whole ? 0 : 2);
            $mpdf->WriteFixedPosHTML('<div style="'.$ink.' font-size: '.$f['size'].'pt; white-space: nowrap;">**'.e($figure).'/-</div>', $at($f['x'], $dx), $at($f['y'], $dy), $f['w'], '7', 'auto');
        }

        if ($cheque['crossed']) {
            $f = $layout->field('crossing');
            $x = $at($f['x'], $dx);
            $y = $at($f['y'], $dy);
            $gap = '6';
            $mpdf->StartTransform();
            // ⓘ mPDF-এর ঘোরানো ঘড়ির কাঁটার দিকে — নমুনার মতো ডানে ওঠা দাগের জন্য উল্টো চিহ্ন
            $mpdf->transformRotate(bcmul($f['angle'], '-1', 2), bcadd($x, bcdiv($f['w'], '2', 2), 2), bcadd($y, bcdiv($gap, '2', 2), 2));
            $mpdf->SetDrawColor(29, 63, 158);
            $mpdf->SetLineWidth(0.4);
            $mpdf->Line($x, $y, bcadd($x, $f['w'], 2), $y);
            $mpdf->Line($x, bcadd($y, $gap, 2), bcadd($x, $f['w'], 2), bcadd($y, $gap, 2));
            $mpdf->WriteFixedPosHTML('<div style="'.$ink.' font-size: '.$f['size'].'pt; text-align: center;">A/C PAYEE ONLY</div>', $x, bcadd($y, '1.2', 2), $f['w'], '4', 'visible');
            $mpdf->StopTransform();
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * বইয়ের একটা নম্বর নেওয়া — সীমার ভিতরে, আগে কখনো নেওয়া নয়; বইয়ের পরের নম্বর এগোয়।
     *
     * @param  array<string, mixed>  $fields
     */
    private function take(ChequeBook $book, int $no, array $fields): ChequePrint
    {
        if (! $book->is_active || $no < $book->first_no || $no > $book->last_no) {
            throw ValidationException::withMessages(['cheque_no' => __('accounts::cheque_print.number_outside_book', ['no' => $book->number($no)])]);
        }

        if (ChequePrint::query()->where('cheque_book_id', $book->id)->where('cheque_no', $no)->exists()) {
            throw ValidationException::withMessages(['cheque_no' => __('accounts::cheque_print.number_used', ['no' => $book->number($no)])]);
        }

        $print = ChequePrint::query()->create($fields + [
            'company_id' => $book->company_id, 'cheque_book_id' => $book->id, 'cheque_no' => $no,
            'status' => ChequePrint::PRINTED, 'times' => 1, 'printed_by' => auth()->id(),
        ]);

        if ($no >= $book->next_no) {
            // ⓘ এর আগের ফাঁকা নম্বরগুলো হাতে লেখা বা নষ্ট — বই এগোয় ছাপা নম্বরের পরে
            $book->forceFill(['next_no' => $no + 1])->save();
        }

        return $print;
    }
}
