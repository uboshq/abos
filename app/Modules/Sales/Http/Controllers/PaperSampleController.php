<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Support\PaperDesigns;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ছাপার নিয়ন্ত্রণের নকশা-কার্ডের নমুনা — চালান · আদেশ · রসিদ; বানানো তথ্যে, DB ছোঁয় না।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: কার্ডে চাপলে পপআপে আসল A4 ছাপা। ⓘ বিলের নমুনার ([[InvoiceSampleController]]) সেই
 * একই ধাঁচ: `?design=<code>`, `?size=a4|a5|thermal`, `?pdf=1` দিলে আসল PDF, নাহলে HTML (কার্ডের ছোট ছবি)।
 *
 * ── ⚠️ কেন বানানো তথ্য ──────────────────────────────────────────────────
 * নকশা বাছার সময় কোম্পানির হয়তো একটাও চালান-আদেশ-রসিদ নেই, আর থাকলেও কোনো গ্রাহকের আসল টাকা নিয়ন্ত্রণ
 * পাতায় দেখানো ঠিক নয়। ⛔ তাই এখানে কোনো মডেল পড়া হয় না — সব নমুনার লেখা `sales::print.sample`-এ।
 *
 * ⓘ প্রতিটা কাগজ কেবল তার নিজের `$doc` আর `$facts` দেয় ([[ChallanPaperFacts]], [[OrderPaperFacts]]-এর সেই একই
 * ঘরে); ছাঁচ বাছা, মাপ আর উত্তর এখানে একবার।
 */
abstract class PaperSampleController extends Controller implements HasMiddleware
{
    public function __construct(
        protected readonly PrintEngine $print,
        protected readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    /** `PaperDesigns`-এর কাগজের নাম — challan · order · receipt */
    abstract protected function paper(): string;

    /** ছাপার সুইচের লক্ষ্য ([[PrintProfile::for()]]) */
    abstract protected function target(): string;

    /**
     * ছাঁচ যা চায় — `doc` আর `facts`।
     *
     * @return array<string, mixed>
     */
    abstract protected function sample(): array;

    public function show(Request $request): Response
    {
        $size = in_array($request->query('size'), PaperDesigns::SIZES, true) ? (string) $request->query('size') : 'a4';
        $asked = (string) $request->query('design', (string) $this->settings->get(PaperDesigns::key($this->paper(), $size)));

        // ⓘ অচেনা নকশা হলে তালিকার প্রথমটা — পুরনো লিংক বা ভুল লেখায় পাতা ভাঙে না
        $template = PaperDesigns::template($this->paper(), $size, $asked)
            ?? PaperDesigns::template($this->paper(), $size, PaperDesigns::codes($this->paper(), $size)[0] ?? null);
        abort_if($template === null, 404);

        $paperSize = match ($size) {
            'a5' => PaperSize::A5,
            'thermal' => PaperSize::THERMAL_80,
            default => PaperSize::A4,
        };
        $data = $this->sample();

        if ($request->boolean('pdf')) {
            $pdf = $this->print->render(template: $template, data: $data, paper: $paperSize, profile: $this->target());

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="sample.pdf"',
            ]);
        }

        $html = $this->print->preview(
            template: $template,
            data: $data,
            paper: $paperSize,
            profile: PrintProfile::for($this->target(), $this->settings),
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // ── নমুনার ভাগের টুকরো ─────────────────────────────────────────────

    protected function s(string $key): string
    {
        return (string) __('sales::print.sample.'.$key, [], 'en');
    }

    protected function today(): string
    {
        return DateFormat::format(now());
    }

    /** @return array{name: string, point: string, address: string, phone: string} */
    protected function party(): array
    {
        return ['name' => $this->s('customer'), 'point' => $this->s('point'), 'address' => $this->s('address'), 'phone' => '01700-000000'];
    }

    /** @return list<array<string, string>> দুই সারির পণ্য — বিলের নমুনার সেই একই দুটো */
    protected function lines(): array
    {
        return [
            ['code' => 'P-0001', 'name' => $this->s('item_one'), 'qty' => '10', 'unit' => 'Ctn', 'rate' => '1,200.00', 'amount' => '12,000.00', 'note' => '', 'free' => '1'],
            ['code' => 'P-0002', 'name' => $this->s('item_two'), 'qty' => '6', 'unit' => 'Bag', 'rate' => '450.00', 'amount' => '2,700.00', 'note' => '', 'free' => ''],
        ];
    }

    /** DUPLICATE ছাপসহ — সুইচ বন্ধ করলে নমুনায় মিলিয়ে দেখা যায় */
    protected function doc(string $title, array $signatures, array $totals = [], array $lines = []): PrintableDocument
    {
        return new PrintableDocument(
            title: $title,
            lines: $lines,
            totals: $totals,
            signatures: $signatures,
            notice: __('core.print.duplicate_notice'),
        );
    }

    /** @return array{0: string, 1: string} ইংরেজি আর বাংলায় কথায় টাকা */
    protected function words(string $amount): array
    {
        return [AmountInWords::of($amount, 'en'), AmountInWords::of($amount, 'bn')];
    }
}
