<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * স্থানান্তরের কাগজ — মালের সাথে যায়।
 *
 * ── কেন মজুদের কাগজ বলতে এটাই ───────────────────────────────────────
 * মজুদের বাকি পর্দাগুলো ভেতরের হিসাব: গণনা, সমন্বয়, খোলা মজুদ। ওগুলোর
 * ফল খতিয়ানে বসে, আর খতিয়ান পড়া যায় পর্দাতেই।
 *
 * স্থানান্তর আলাদা, কারণ **মাল সত্যিই একটা ট্রাকে ওঠে**। এক গুদাম থেকে
 * বেরোয়, রাস্তায় থাকে, অন্য গুদামে পৌঁছায় — আর ওই পথটুকুতে কাগজই
 * একমাত্র প্রমাণ। কাগজ ছাড়া পাঠানো মাল রাস্তায় হারালে কেউ বলতে পারবে
 * না কতটা উঠেছিল।
 *
 * ── দুইটা সই, আর সেটাই পুরো কথা ─────────────────────────────────────
 * পাঠান এক গুদামের লোক, বুঝে নেন অন্য গুদামের। দুইটা সই আলাদা মানুষের,
 * ঠিক যে কারণে অনুমতিও দুইটা আলাদা (`transfer.create` আর
 * `transfer.receive`): একজনে দুইটা করতে পারলে "পাঠিয়েছি, পৌঁছেছে" লিখে
 * দিয়ে মাল পথেই সরিয়ে ফেলা যেত, আর কাগজে সবই মিলত।
 *
 * ── দাম নেই ────────────────────────────────────────────────────────
 * স্থানান্তরের সারিতে দাম থাকেই না — এক গুদাম থেকে আরেক গুদামে মাল গেলে
 * প্রতিষ্ঠানের সম্পদ বদলায় না, শুধু জায়গা বদলায়। কাগজে দাম বসালে
 * ড্রাইভার আর পথের সবাই জেনে যেত মালের দাম কত, অথচ ওটা তাঁদের কাজে
 * লাগে না।
 */
class StockPrintController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintEngine $print) {}

    public static function middleware(): array
    {
        // দেখার চাবিই ছাপার চাবি — কাগজ নতুন কোনো তথ্য দেয় না।
        return [new Middleware('can:inventory.transfer.view')];
    }

    public function transfer(Request $request, StockTransfer $transfer): Response
    {
        $transfer->load(['lines.product.unit', 'fromWarehouse', 'toWarehouse', 'branch']);

        $doc = new PrintableDocument(
            title: __('inventory::doc.transfer'),
            meta: array_filter([
                'core.print.document_no' => (string) $transfer->document_no,
                'core.print.date' => DateFormat::format($transfer->trx_date),
                'inventory::field.from_warehouse' => $transfer->fromWarehouse?->name() ?? '',
                'inventory::field.to_warehouse' => $transfer->toWarehouse?->name() ?? '',
            ], fn ($value) => filled($value)),
            lines: $transfer->lines->map(fn ($line) => [
                'name' => trim(($line->product?->code ?? '').' '.($line->product?->name() ?? '')),
                // যে প্যাকে পাঠানো হয়েছিল সেটাই কাগজে — ওপারে যিনি
                // গুনবেন তিনি বাক্স গোনেন, পিস নয়
                'qty' => $this->qty($line->packedQty()),
                'unit' => $line->packedUnitName(),
            ])->values()->all(),
            totals: [],
            signatures: [
                'inventory::print.dispatched_by',
                'inventory::print.received_by',
            ],
            showMoney: false,
            narration: $transfer->narration,

            /*
             * বাতিল স্থানান্তরের কাগজ ছাপা যায়, কিন্তু গায়ে লেখা থাকে।
             *
             * ছাপতে না দিলে "ওই চালানটার কী হলো" জিজ্ঞেস করলে দেখানোর
             * কিছু থাকত না। কিন্তু চালু চালানের মতো দেখতে একটা বাতিল
             * চালান নিয়ে কেউ গেট দিয়ে মাল বের করে নিতে পারেন।
             */
            notice: $transfer->status === DocumentStatus::CANCELLED
                ? __('inventory::print.cancelled')
                : null,
        );

        $paper = $request->query('paper', PaperSize::A4);

        if (! in_array($paper, PaperSize::all(), true)) {
            $paper = PaperSize::A4;
        }

        $pdf = $this->print->render(
            template: 'print.document',
            data: [
                'doc' => $doc,
                'title' => $doc->title.' '.$transfer->document_no,
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$transfer->document_no.'.pdf"',
        ]);
    }

    /** ভগ্নাংশ থাকলে দেখাও, না থাকলে নয় — "১০.০০ পিস" কেউ লেখে না। */
    private function qty(mixed $value): string
    {
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? number_format($number)
            : rtrim(rtrim(number_format($number, 4), '0'), '.');
    }
}
