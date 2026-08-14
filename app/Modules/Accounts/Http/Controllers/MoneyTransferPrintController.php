<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\MoneyTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * হস্তান্তরের স্লিপ — দুইজনের সইয়ের কাগজ।
 *
 * ── কেন পর্দার রেকর্ড যথেষ্ট নয় ─────────────────────────────────────
 * হাতে হাতে টাকা যায় দিনে কয়েকবার। ঝগড়াটা কখনো "দিয়েছি কি না" নিয়ে
 * হয় না — হয় **কত** আর **কখন** নিয়ে। যিনি টাকা নিচ্ছেন তাঁর হাতে
 * পর্দা থাকে না, আর যিনি দিচ্ছেন তিনি পরে রেকর্ড বদলাতে পারেন বলে
 * সন্দেহ থেকেই যায়। কাগজে দুইজনের সই — ওটাই একমাত্র জিনিস যা দুইজনের
 * কাছেই সমান।
 *
 * ── অনুমতি: যিনি হস্তান্তর দেখতে পারেন ──────────────────────────────
 * আলাদা "print" অনুমতি নয়। স্লিপটা নতুন কোনো তথ্য দেয় না, একই তথ্য
 * কাগজে দেয় — আর ওটা ছাপাই হস্তান্তরের কাজের অংশ।
 */
class MoneyTransferPrintController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintEngine $print) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.transfer.create')];
    }

    public function __invoke(Request $request, MoneyTransfer $transfer): Response
    {
        $transfer->load(['fromTill.account', 'toTill.account', 'toAccount', 'giver', 'receiver', 'branch']);

        $paper = $request->query('paper', PaperSize::A4);

        // অজানা মাপে ৪০৪ নয় — পুরনো বুকমার্কের জন্য একটা স্লিপ আটকে
        // যাওয়ার কারণ নেই
        if (! in_array($paper, PaperSize::all(), true)) {
            $paper = PaperSize::A4;
        }

        $pdf = $this->print->render(
            template: 'print.handover',
            data: [
                'title' => __('accounts::print.handover_title').' '.$transfer->document_no,
                'handover' => $this->paper($transfer),
                /*
                 * বাতিল করা স্লিপ যেন বৈধ কাগজ হিসেবে না চলে।
                 *
                 * টাকার কাগজে এটা সবচেয়ে জরুরি: বাতিল করা হস্তান্তরের
                 * স্লিপ হুবহু বৈধ স্লিপের মতো দেখালে কেউ সেটা "আমি
                 * দিয়েছি" প্রমাণ হিসেবে দেখাতে পারতেন।
                 */
                'notice' => $transfer->isCancelled() ? __('accounts::print.cancelled') : null,
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$transfer->document_no.'.pdf"',
        ]);
    }

    /**
     * টেমপ্লেট যা যা চায়, ঠিক সেই নামে।
     *
     * @return array<string, string>
     */
    private function paper(MoneyTransfer $transfer): array
    {
        return [
            'document_no' => (string) $transfer->document_no,
            'date' => DateFormat::format($transfer->trx_date),
            'from' => (string) $transfer->fromTill?->name(),

            /*
             * গন্তব্য দুই রকম হতে পারে — আরেকটা টিল, বা ব্যাংক।
             *
             * দুইটার জন্য আলাদা ঘর না রেখে একটাই লাইন, কারণ কাগজে
             * পড়ার সময় প্রশ্নটা একটাই: "টাকাটা কোথায় গেল"।
             */
            'to' => (string) ($transfer->toTill?->name() ?? $transfer->toAccount?->label()),

            'branch' => (string) $transfer->branch?->name(),
            'narration' => (string) $transfer->narration,
            'amount' => Money::format($transfer->amount),
            'amount_in_words' => AmountInWords::of((string) $transfer->amount, app()->getLocale()),

            /*
             * নাম দুইটা, আর সইয়ের রেখার নিচে ছাপা।
             *
             * ছয় মাস পরে কাগজটা দেখে বোঝা যেতে হবে কার সই থাকার কথা
             * ছিল। শুধু "দাতা / গ্রহীতা" লিখলে কাগজটা কিছুই বলত না, আর
             * কেউ অন্যের হয়ে সই করলে মিলিয়ে দেখার উপায় থাকত না।
             *
             * গ্রহীতার নাম খালি থাকতে পারে — হস্তান্তর শুরুর সময় কে
             * নেবেন তা সবসময় জানা থাকে না। তখন রেখাটা খালিই থাকে, আর
             * যিনি নেবেন তিনি নিজের নাম লিখে সই করেন।
             */
            'given_by' => (string) $transfer->giver?->name,
            'received_by' => (string) $transfer->receiver?->name,
        ];
    }
}
