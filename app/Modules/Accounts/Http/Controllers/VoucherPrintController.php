<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\BranchSettings;
use App\Core\Services\PaperTrail;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\AuditTrail;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Support\VoucherDesigns;
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
    public function __construct(
        private readonly PrintEngine $print,

        // ছাপা · নামানো · পাঠানো · খোলা — সব কাগজের এক হিসাব
        private readonly PaperTrail $trail,

        // ⭐ শাখার নিজের মাপ ও নকশা — মালিক, ৩০ সেপ্টেম্বর ২০২৬: "প্রতিটা শাখা আলাদা ব্যবসা হতে পারে"
        private readonly BranchSettings $branch,
    ) {}

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

        /*
         * ⭐ কাগজটা তার শাখার সেটিংয়ে আঁকা হয় ([[BranchSettings::during()]]) — মাপ, নকশা, লোগো।
         * ⓘ শাখা কিছু না বসালে কোম্পানিরটা; আর শেষে আগের অবস্থায় ফেরে, ব্যতিক্রম হলেও।
         */
        return $this->branch->during($voucher->branch_id, fn (): Response => $this->printed($request, $voucher));
    }

    private function printed(Request $request, Voucher $voucher): Response
    {
        /*
         * ⭐ কাগজের মাপ মালিকের বসানো, হাতে লেখা A4 নয় (২০ সেপ্টেম্বর ২০২৬)।
         * ⓘ ঠিকানায় চাওয়া মাপ আগে, তারপর সেটিং — কারণ [[PaperSize::chosen()]]-এ।
         */
        $paper = PaperSize::chosen(PaperSize::fromQuery($request), $this->branch->get('accounts.print.paper.voucher'));

        /*
         * টেমপ্লেটটা আগে থেকেই ছিল — `resources/views/print/voucher.blade.php`।
         *
         * লেখা হয়েছিল, তারপর কোনো রুট কোনোদিন ওটা ডাকেনি। তাপীয়
         * কাগজে কলাম কমানো, বিবরণ লুকানো, দুই দিকের যোগফল — সবই ওখানে
         * করা আছে। বিক্রয়ের সাধারণ `print.document` ব্যবহার করলে
         * ভাউচারের সারিগুলো পণ্যের সারির ছাঁচে ঢোকাতে হত, আর ওই ছাঁচে
         * ডেবিট-ক্রেডিট বলে কিছু নেই।
         */
        /*
         * ⭐ বাছা নকশা, মাপ ধরে ([[VoucherDesigns]]); `standard` বা অচেনা → চলতি ভাউচার।
         * ⓘ থার্মাল মাপগুলো (৮০/৫৮মিমি) এক ট্যাব; বাকিরা A5 বা A4।
         */
        $size = PaperSize::of($paper)->isThermal ? 'thermal' : ($paper === PaperSize::A5 ? 'a5' : 'a4');
        $template = VoucherDesigns::template($size, (string) $this->branch->get(VoucherDesigns::key($size))) ?? 'print.voucher';

        $pdf = $this->print->render(
            template: $template,
            data: [
                'title' => $voucher->typeLabel().' '.$voucher->document_no,
                'voucher' => $this->paper($voucher),
                'signatures' => $this->signatures($voucher),
                // ⛔ খসড়া ভাউচারেও "খসড়া" — পাকা রসিদের মতো ছাপা হত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, ছাপা ১০)
                'notice' => $voucher->isCancelled() ? __('accounts::print.cancelled') : ($voucher->isDraft() ? __('core.print.draft_paper_notice') : null),
            ],
            paper: $paper,

            watermark: $this->watermarkFor($voucher),

            /*
             * ⭐ এই কাগজটা এখন সুইচের আওতায় — ২৩ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ এটা না দিলে [[PrintProfile::everything()]] চলত — সব
             * অংশ চালু, কোনো সেটিং পড়া হয় না। ⓘ ফলে মালিক
             * ভাউচারের সুইচ বদলে সেভ করতেন, আর কাগজে কিছুই
             * বদলাত না — সুইচগুলো আঁকা হত, কিন্তু দরজাটা বন্ধ।
             */
            profile: 'voucher',
        );

        /*
         * ⭐ কাগজটা বেরোল — ছাপা হয়ে, নাকি ফাইল হয়ে (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ মালিকের চাওয়া: *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল"*। ⚠️ দুইটা
         * আলাদা গোনা হয়, কারণ "ছেপে দিয়েছি" আর "ফাইল পাঠিয়েছি" এক কথা নয়।
         * ⛔ ফাইলটা আলাদা করে আঁকা হয় না — উপরের `$pdf`-ই নামে।
         */
        $asFile = $request->boolean('download');

        $this->trail->record(
            'accounts_voucher', (int) $voucher->id, $paper,
            $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
            $voucher->document_no,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$voucher->document_no.'.pdf"',
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

            /* ⓘ নতুন নকশাগুলো মাথায় ধরন লেখে (RECEIPT, PAYMENT …) — চলতি ভাউচার এগুলো পড়ে না */
            'type' => (string) $voucher->type,
            'type_label' => mb_strtoupper((string) $voucher->typeLabel()),
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

            // ⭐ মালিকের ২১ নকশা (১২ অক্টোবর ২০২৬) যা দেখায় — পুরনো ঘরগুলো অক্ষত, নতুন সব এক জায়গায়
            'details' => $this->details($voucher),
        ];
    }

    /**
     * কাগজের বাড়তি কথা — কার কাছ থেকে বা কাকে, কোন পথে, কোন খাতে, কোন কাগজের বিপরীতে, কে কখন।
     *
     * ⭐ মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"* — ২১টা নকশা ([[VoucherDesigns]])।
     * ⓘ সব ঘর লেখা (না থাকলে খালি), তাই নকশা কেবল ভরা ঘর আঁকে। পক্ষ আর কাগজের নম্বর [[DrillResolver]] দিয়ে —
     * Accounts কারও উপর দাঁড়ায় না (উপরের `party`-র টীকা)। ⚠️ পুরনো `party` ঘর ইচ্ছা করে খালিই থাকে, যাতে পুরনো ২০টা
     * নকশার কাগজ এক চুলও না বদলায়।
     *
     * @return array<string, mixed>
     */
    private function details(Voucher $voucher): array
    {
        $party = $this->partyName($voucher->party_type, $voucher->party_id);
        $point = '';

        if ($party !== '' && filled($voucher->party_type)) {
            $model = app(DrillResolver::class)->resolve((string) $voucher->party_type, (int) $voucher->party_id);
            $point = $model !== null && method_exists($model, 'pointName') ? (string) $model->pointName() : '';
        }

        $against = '';

        if (filled($voucher->against_type) && (int) $voucher->against_id > 0) {
            $paper = app(DrillResolver::class)->resolve((string) $voucher->against_type, (int) $voucher->against_id);
            $against = $paper !== null && method_exists($paper, 'drillDocumentNo') ? (string) $paper->drillDocumentNo() : '';
        }

        $money = $voucher->money_account_id === null ? null
            : Account::query()->whereKey($voucher->money_account_id)->first();

        $instrument = (string) ($voucher->instrument ?? '');

        return [
            'party' => $party,
            'point' => $point,
            'payee' => (string) ($voucher->payee_name ?? ''),
            'money_account' => $money === null ? '' : trim($money->code.' '.$money->name()),
            'method' => $instrument === '' ? '' : (string) __('accounts::instrument.'.$instrument),
            'instrument' => $instrument,
            'instrument_no' => (string) ($voucher->instrument_no ?? ''),
            'instrument_date' => $voucher->instrument_date === null ? '' : DateFormat::format($voucher->instrument_date),
            'from_bank' => trim(implode(' · ', array_filter([(string) $voucher->from_bank, (string) $voucher->from_account_no]))),
            'wallet' => (string) ($voucher->wallet ?? ''),
            'against' => $against,
            'bill_no' => (string) ($voucher->bill_no ?? ''),
            'notes' => array_filter((array) ($voucher->note_counts ?? []), fn ($n) => (int) $n > 0),
            'entered_on' => $voucher->created_at === null ? '' : $voucher->created_at->format('d/m/Y h:i:s A'),
            'prepared_by' => (string) ($voucher->creator?->name ?? ''),
            'approved_by' => (string) ($voucher->approver?->name ?? ''),
            'approved_on' => $voucher->approved_at === null ? '' : $voucher->approved_at->format('d/m/Y h:i A'),
            'trail' => $this->trail($voucher),
            'history' => $this->history($voucher),
            'printed_on' => now()->format('d/m/Y h:i A'),
            'printed_by' => (string) (auth()->user()?->name ?? ''),
        ];
    }

    /**
     * সইয়ের ধারা — অনুমোদনের খাতা থেকে, ধাপে ধাপে (নকশা ১৬)।
     *
     * @return list<array{step: string, name: string, decision: string, at: string, remarks: string}>
     */
    private function trail(Voucher $voucher): array
    {
        $approval = Approval::query()
            ->where('approvable_type', $voucher->getMorphClass())
            ->where('approvable_id', $voucher->getKey())
            ->latest('id')
            ->first();

        if ($approval === null) {
            return [];
        }

        return $approval->decisions()->with('user')->orderBy('id')->get()->map(fn ($d) => [
            'step' => (string) $d->level,
            'name' => (string) ($d->user?->name ?? ''),
            'decision' => (string) __('accounts::vx.decision.'.$d->decision),
            'at' => $d->decided_at === null ? '' : $d->decided_at->format('d/m/Y h:i A'),
            'remarks' => (string) ($d->remarks ?? ''),
        ])->values()->all();
    }

    /**
     * নথির ইতিহাস — কে কখন কী করলেন (নকশা ২১)।
     *
     * @return list<array{at: string, who: string, what: string}>
     */
    private function history(Voucher $voucher): array
    {
        return AuditTrail::query()
            ->where('auditable_type', $voucher->getMorphClass())
            ->where('auditable_id', $voucher->getKey())
            ->with('user')
            ->orderBy('id')
            ->limit(12)
            ->get()
            ->map(fn (AuditTrail $t) => [
                'at' => $t->created_at === null ? '' : $t->created_at->format('d/m/Y h:i:s A'),
                'who' => (string) ($t->user?->name ?? ''),
                'what' => trim(implode(' — ', array_filter([(string) $t->action, (string) $t->reason]))),
            ])->values()->all();
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
            /*
             * ⭐ খাতের পাশে পক্ষের নাম — মালিক, ৫ অক্টোবর ২০২৬ (ডেমো JRN-0003: দুই সারিতেই "1110 প্রাপ্য হিসাব",
             * কার থেকে কার নামে সরল তা কাগজে নেই — "etaw ager motoi")। ⓘ নামটা [[DrillResolver]] দিয়ে, মডেল চিনে
             * নয় — Accounts কারও উপর দাঁড়ায় না, নিচের পুরনো টীকার কারণটা অক্ষত।
             */
            'account' => trim(
                ($line->account?->code ?? '').' '.($line->account?->name() ?? '')
                .(($name = $this->partyName($line->party_type, $line->party_id)) !== '' ? ' — '.$name : '')
            ),
            'narration' => (string) ($line->narration ?? ''),
            'debit' => $this->money($line->debit),
            'credit' => $this->money($line->credit),
        ])->all();
    }

    /** পক্ষের নাম — গ্রাহক, সরবরাহকারী, ব্যক্তি, কর্মী — যে-ই হোক, ধরন আর নম্বর থেকে; না পেলে খালি। */
    private function partyName(?string $type, int|string|null $id): string
    {
        if (blank($type) || (int) $id <= 0) {
            return '';
        }

        $party = app(DrillResolver::class)->resolve((string) $type, (int) $id);

        return $party !== null && method_exists($party, 'name') ? (string) $party->name() : '';
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
     * বাতিল কাগজের গায়ে কোনাকুনি জলছাপ, ৩০ আগস্ট ২০২৬।
     *
     * ── কেন উপরের বাক্সটা যথেষ্ট ছিল না ─────────────────────────────
     * বাতিল ভাউচারে উপরে একটা বাক্সে "বাতিল" লেখা ওঠে, আর সেটা
     * ১৪ আগস্ট HP-র ধরা একটা ভুলের সমাধান ছিল।
     *
     * বাক্সটা কাগজের একটা কোণে থাকে: ভাঁজ করলে ঢাকা পড়ে, ফটোকপিতে
     * কেটে ফেলা যায়, আর উপরের অংশটুকু বাদ দিয়ে স্ক্যান করলে বাকিটা
     * হুবহু বৈধ একটা রসিদ। সমাধানটা এক ভাঁজ দূরে ছিল।
     *
     * জলছাপ সংখ্যাগুলোর উপর দিয়ে যায়, তাই কেটে বাদ দিলে সংখ্যাগুলোও
     * যায়। বাক্সটাও থাকে — দুইটা দুই কাজ করে: বাক্স পড়ে বোঝায়,
     * জলছাপ নকল করা আটকায়।
     *
     * ── কেন এটা নিজের মেথড ──────────────────────────────────────────
     * সিদ্ধান্তটা `render()`-এর ভেতরে লেখা ছিল, আর তখন পরীক্ষা করার
     * একমাত্র উপায় ছিল দুইটা PDF-এর মাপ মেলানো। ওটা **সবুজ কিন্তু
     * অন্ধ** হয়ে গিয়েছিল: বাতিল করলে উপরের বাক্সটাও যোগ হয়, তাই
     * জলছাপ সরিয়ে দিলেও PDF বড়ই থাকত আর পরীক্ষা পাস করত।
     *
     * এখন সিদ্ধান্তটা নিজেই দেখা যায়, তাই ভুলটাও দেখা যায়।
     */
    private function watermarkFor(Voucher $voucher): ?string
    {
        return $voucher->isCancelled() ? __('core.print.cancelled_watermark')
            : ($voucher->isDraft() ? __('core.print.draft_watermark') : null);
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
        return Money::format($value);
    }
}
