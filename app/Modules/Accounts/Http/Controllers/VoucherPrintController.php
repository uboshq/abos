<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ভাউচারের কাগজ — টাকা হাতবদলের প্রমাণ।
 *
 * ── কেন এটা দরকার, আর কেন এতদিন ছিল না ──────────────────────────────
 * ছাপা বসানো হয়েছিল বিক্রয়ের ছয়টা ডকুমেন্টে, আর সেখানেই থেমে গিয়েছিল।
 * কিন্তু ভাউচারই সেই কাগজ যেটা **হাতে হাতে যায়**: গ্রাহক টাকা দিলে
 * তাঁকে একটা রসিদ দিতে হয়। না দিলে পরদিন "আমি তো দিয়েছি" বনাম "পাইনি",
 * আর দুই পক্ষের কারও হাতে কিছু নেই।
 *
 * বিক্রয়ের কাগজের সাথে এখানে একটাই পার্থক্য, আর সেটা কাঠামোগত: বিলে
 * থাকে পণ্যের সারি, ভাউচারে থাকে **খাতের সারি**। বাকি সব — মাপ,
 * শিরোনাম, কথায় টাকা, সই — একই ইঞ্জিনের, নতুন করে কিছু লেখা হয়নি।
 *
 * ── পাঁচ ধরনের ভাউচার, এক কাগজ ─────────────────────────────────────
 * প্রাপ্তি, পরিশোধ, খরচ, জার্নাল, কন্ট্রা — পাঁচটার কাগজ আলাদা নয়, শুধু
 * শিরোনাম আর সইয়ের ঘর আলাদা। পাঁচটা টেমপ্লেট বানালে একটায় ভুল সারানো
 * হত আর বাকি চারটায় নয়।
 */
class VoucherPrintController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintEngine $print) {}

    public static function middleware(): array
    {
        /*
         * ভাউচার দেখার যে চাবি, ছাপারও সেই চাবি।
         *
         * ছাপা কোনো নতুন তথ্য দেয় না — একই তথ্য কাগজে দেয়। আলাদা
         * "print" অনুমতি রাখলে ক্যাশিয়ার রসিদ দিতে পারতেন না, অথচ
         * রসিদ দেওয়াই তাঁর কাজ।
         *
         * চাবিটা `accounts.report`, কারণ ভাউচারের তালিকা ও বিস্তারিত
         * পর্দা দুইটাই ওটাই চায় (VoucherController::middleware)। এখানে
         * অন্য কিছু বসালে পর্দা দেখা যেত অথচ কাগজ পাওয়া যেত না।
         */
        return [new Middleware('can:accounts.report')];
    }

    public function __invoke(Request $request, Voucher $voucher): Response
    {
        $voucher->load(['lines.account', 'creator', 'approver', 'branch']);

        $paper = $request->query('paper', PaperSize::A4);

        // অজানা মাপে ৪০৪ নয় — পুরনো বুকমার্ক বা হাতে বদলানো ঠিকানার
        // জন্য একজন ক্যাশিয়ারের রসিদ আটকে যাওয়ার কারণ নেই।
        if (! in_array($paper, PaperSize::all(), true)) {
            $paper = PaperSize::A4;
        }

        /*
         * টেমপ্লেটটা আগে থেকেই ছিল — `resources/views/print/voucher.blade.php`।
         *
         * লেখা হয়েছিল, তারপর কোনো রুট কোনোদিন ওটা ডাকেনি। তাপীয়
         * কাগজে কলাম কমানো, বিবরণ লুকানো, দুই দিকের যোগফল — সবই ওখানে
         * করা আছে। বিক্রয়ের সাধারণ `print.document` ব্যবহার করলে
         * ভাউচারের সারিগুলো পণ্যের সারির ছাঁচে ঢোকাতে হত, আর ওই ছাঁচে
         * ডেবিট-ক্রেডিট বলে কিছু নেই।
         */
        $pdf = $this->print->render(
            template: 'print.voucher',
            data: [
                'title' => $voucher->typeLabel().' '.$voucher->document_no,
                'voucher' => $this->paper($voucher),
                'signatures' => $this->signatures($voucher),
                'notice' => $voucher->isCancelled() ? __('accounts::print.cancelled') : null,
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$voucher->document_no.'.pdf"',
        ]);
    }

    /**
     * টেমপ্লেট যা যা চায়, ঠিক সেই নামে।
     *
     * @return array<string, mixed>
     */
    private function paper(Voucher $voucher): array
    {
        [$debit, $credit] = $this->totals($voucher);

        return [
            'document_no' => (string) $voucher->document_no,
            'date' => DateFormat::format($voucher->trx_date),
            /*
             * পক্ষের নাম নেই, ইচ্ছাকৃতভাবে।
             *
             * ভাউচারে `party_type`/`party_id` থাকে, কিন্তু নামটা বের
             * করতে হলে Accounts-কে Customer বা Supplier চিনতে হত — আর
             * Accounts কারও উপর দাঁড়ায় না, সবাই তার উপর দাঁড়ায়
             * (§১৯.৭, BoundariesTest পাহারা দেয়)।
             *
             * টেমপ্লেট খালি হলে সারিটাই বাদ দেয়, তাই কাগজে কিছু ভাঙে
             * না। নামটা কাগজে তোলার সঠিক পথ হলো ContributesFacts-এর
             * মতো একটা উল্টো-দিকের সরবরাহ, আর সেটা আলাদা কাজ।
             */
            'party' => '',
            'branch' => (string) $voucher->branch?->name(),
            'narration' => (string) $voucher->narration,
            'lines' => $this->lines($voucher),
            'total_debit' => $debit,
            'total_credit' => $credit,
            'amount_in_words' => AmountInWords::of((string) $voucher->amount, app()->getLocale()),
        ];
    }

    /**
     * খাতের সারিগুলো — কোড, নাম, ডেবিট, ক্রেডিট।
     *
     * বিবরণের কলামটা রাখা, কারণ জার্নাল ভাউচারে প্রতিটা সারির নিজের
     * কারণ থাকে। ওটা ছাড়া কাগজ পড়ে কিছু বোঝা যায় না — "৫২০৮ আপ্যায়ন
     * ৳৪৫০" কেন, উত্তরটা সারির বিবরণেই লেখা।
     *
     * @return list<array<string, string>>
     */
    private function lines(Voucher $voucher): array
    {
        return $voucher->lines->map(fn (VoucherLine $line) => [
            'account' => trim(
                ($line->account?->code ?? '').' '.($line->account?->name() ?? '')
            ),
            'narration' => (string) ($line->narration ?? ''),
            'debit' => $this->money($line->debit),
            'credit' => $this->money($line->credit),
        ])->all();
    }

    /**
     * দুই দিকের যোগফল — আর দুইটাই ছাপা হয়।
     *
     * একটা "মোট" ছাপলে কাগজ ছোট হত, কিন্তু ভাউচারের পুরো কথাটাই দুই দিক
     * সমান। যিনি কাগজ হাতে নিয়ে মেলাবেন, তাঁর দুইটাই লাগে।
     *
     * @return array{0: string, 1: string} ডেবিট, ক্রেডিট
     */
    private function totals(Voucher $voucher): array
    {
        $debit = $voucher->lines->reduce(
            fn (string $sum, VoucherLine $line) => bcadd($sum, (string) $line->debit, 4),
            '0',
        );
        $credit = $voucher->lines->reduce(
            fn (string $sum, VoucherLine $line) => bcadd($sum, (string) $line->credit, 4),
            '0',
        );

        return [$this->money($debit), $this->money($credit)];
    }

    /**
     * সইয়ের ঘর — ধরন অনুযায়ী।
     *
     * প্রাপ্তিতে টাকা যিনি দিলেন তাঁর সই লাগে, পরিশোধে যিনি নিলেন তাঁর।
     * জার্নালে কেউ টাকা ছোঁয় না, তাই ওখানে প্রস্তুতকারক ও অনুমোদনকারী —
     * টাকার সই চাইলে কাগজে একটা ঘর থাকত যা কেউ কোনোদিন সই করত না।
     *
     * @return list<string>
     */
    private function signatures(Voucher $voucher): array
    {
        $prepared = __('accounts::print.prepared_by');
        $approved = __('accounts::print.approved_by');

        return match ($voucher->type) {
            Voucher::RECEIPT => [__('accounts::print.paid_by'), $prepared, $approved],
            Voucher::PAYMENT, Voucher::EXPENSE => [__('accounts::print.received_by'), $prepared, $approved],
            default => [$prepared, $approved],
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }
}
