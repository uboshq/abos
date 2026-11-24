<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Contracts\NoteTargetLines;
use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\BranchSettings;
use App\Core\Services\PaperPreview;
use App\Core\Services\PaperTrail;
use App\Core\Services\PartyRegistry;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\NoteAccounts;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Support\PaperTaxFacts;
use App\Modules\Accounts\Support\TradeCatalog;
use App\Core\Security\FieldSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ডেবিট/ক্রেডিট নোটের কাগজ — মালিক, ৩ অক্টোবর ২০২৬: নোটের কোনো ছাপা ছিল না।
 *
 * ⓘ ঘরের সাধারণ ছাপার যন্ত্র ([[PrintEngine]]) — A4, A5, থার্মাল; মাপ শাখার সেটিং (`accounts.print.paper.note`)
 * বা ঠিকানার `?paper=`। কাগজে কোম্পানির মাথা (লেআউট), নোটের নম্বর, তারিখ, দিক, পক্ষের নাম-কোড-ঠিকানা, কারণ,
 * বিবরণ, টাকা আর কথায়, বিপরীতের কাগজ, সই আর নিচের লেখা।
 *
 * ⭐ নকশা v5 (২৩ নভেম্বর ২০২৬) — সারিওয়ালা নোট কর-কাগজ হয়ে ছাপে (মূসক ও সম্পূরক শুল্ক আইন ২০১২ ধারা ৫২(১), বিধি ৪০):
 *   ক্রেডিট নোট = মূসক-৬.৭, গ্রাহকের ডেবিট নোট = মূসক-৬.৮ — দুই পক্ষের নাম, ঠিকানা, BIN; নোটের নম্বর, তারিখ ও সময়; মূল
 *   কর চালানপত্রের (মূসক-৬.৩) নম্বর, তারিখ ও সময়; কারণ; দামের সমন্বয়ের সারি আর মাল ফেরতের সারি (SR/PR নম্বরসহ) আলাদা;
 *   মোট (নোট + ফেরত = সর্বমোট), কথায়, সই। সরবরাহকারীর ডেবিট নোট সাধারণ "ডেবিট নোট", তাঁর মূসক-৬.৭ নম্বর-তারিখসহ।
 *   ⓘ সারি নেই এমন পুরনো নোট আগের মতোই এক অঙ্কে ছাপে। ⛔ কোম্পানির BIN খালি হলে ছাপা থামে না — BIN-এর ঘরে সতর্কবার্তা।
 *
 * ⭐ গোনা আর DUPLICATE: প্রতিটা ছাপা [[PaperTrail]]-এ লেখা হয়; আগে ছাপা হয়ে থাকলে কাগজের মাথায় "DUPLICATE —
 * nতম ছাপা", বিক্রয়ের কাগজের একই লেখায় (`core.print.duplicate_notice`)। বাতিল নোট বড় করে "বাতিল" বলে, জলছাপসহ।
 */
final class NotePrintController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,
        private readonly BranchSettings $branch,
        private readonly PaperTrail $trail,
        private readonly PartyRegistry $parties,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.note.view')];
    }

    public function __invoke(Request $request, Note $note): Response
    {
        return $this->branch->during($note->branch_id, function () use ($request, $note) {
            $paper = PaperSize::chosen(PaperSize::fromQuery($request), $this->branch->get('accounts.print.paper.note'));
            $asFile = $request->boolean('download');

            $printed = $this->trail->countsFor('accounts_note', (int) $note->id)[DocumentDelivery::PRINTED] ?? 0;

            $notice = match (true) {
                $note->isCancelled() => (string) __('accounts::print.cancelled'),
                // ⓘ দেখা ছাপা নয় — DUPLICATE ছাপার ([[PaperPreview]]; মালিক, ২৪ নভেম্বর ২০২৬)
                $printed > 0 && ! $asFile && ! PaperPreview::looking() => (string) __('core.print.duplicate_notice', ['n' => $printed + 1]),
                default => null,
            };

            $facts = $this->paper($note);

            $pdf = $this->print->render(
                template: 'print.note',
                data: [
                    'title' => $this->direction($note).' '.$note->document_no,
                    'note' => $facts,
                    'notice' => $notice,
                    'signatures' => $facts['mushak'] !== ''
                        ? [
                            (string) __('accounts::print.prepared_by'),
                            (string) __('accounts::note_print.officer'),
                            (string) __('accounts::note_print.recipient_sign'),
                        ]
                        : [
                            (string) __('accounts::print.prepared_by'),
                            (string) __('accounts::print.approved_by'),
                            (string) __('accounts::print.received_by'),
                        ],
                    'footnote' => trim((string) $this->branch->get('accounts.print.note_footnote')),
                ],
                paper: $paper,
                watermark: $note->isCancelled() ? (string) __('core.print.cancelled_watermark') : null,
            );

            $this->trail->record(
                'accounts_note', (int) $note->id, $paper,
                $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
                $note->document_no,
            );

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$note->document_no.'.pdf"',
            ]);
        });
    }

    private function direction(Note $note): string
    {
        return (string) __($note->isCredit() ? 'accounts::note.credit_note' : 'accounts::note.debit_note');
    }

    /**
     * ⓘ মূসক ফর্ম — ক্রেডিট নোট ৬.৭, গ্রাহকের ডেবিট নোট ৬.৮; সরবরাহকারীর ডেবিট নোট কর-ফর্ম নয় (তাঁর ৬.৭ তিনি দেন)।
     */
    private function mushak(Note $note): string
    {
        return match ($note->type()) {
            Note::TYPE_CN => '6.7',
            Note::TYPE_CDN => '6.8',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function paper(Note $note): array
    {
        $taxFacts = app(PaperTaxFacts::class);
        $party = $taxFacts->party((string) $note->party_type, (int) $note->party_id);
        $company = $taxFacts->company((int) $note->company_id);

        // ⭐ দুই খাত কাগজেও — সমন্বয়কের শর্ত, ৩ অক্টোবর ২০২৬ ([[NoteAccounts]])
        ['control' => $control, 'other' => $other] = app(NoteAccounts::class)->of($note);

        $lines = $note->lines()->with('account')->get();
        $price = $lines->reject(fn (NoteLine $l) => $l->postedByItsReturn())->values();
        $returned = $lines->filter(fn (NoteLine $l) => $l->postedByItsReturn())->values();

        /* ⓘ মূল কর চালানপত্র — বাঁধা বিল হলে তার নম্বর আর লেখার মুহূর্ত ([[NoteTargetLines::noteTargetIssuedAt()]]) */
        $bill = $note->against_id !== null ? app(NoteService::class)->targetOf($note) : null;
        $billAt = $bill instanceof NoteTargetLines ? $bill->noteTargetIssuedAt() : null;

        // ⛔ সরবরাহকারীর নোটে পণ্যের দর আর তার উপ-যোগ কেনা দর — চাবি না থাকলে ঢাকা; নোটের মোট দেনার অঙ্ক, খোলা
        $showsPrice = $note->type() !== Note::TYPE_DN || TradeCatalog::seesCost();
        $sum = fn (\Illuminate\Support\Collection $of, string $field): string => ! $showsPrice && $of->contains(fn (NoteLine $l) => $l->line_type === NoteLine::PRODUCT)
            ? FieldSecurity::mask() : Money::format(Money::sumOf($of, fn (NoteLine $l) => $l->{$field}));

        $grand = $note->grandTotal();
        $returnedTotal = (string) ($note->returned_total ?? '0');

        return [
            'document_no' => (string) $note->document_no,
            'date' => DateFormat::format($note->trx_date),
            'direction' => $this->direction($note),
            'type' => $note->type(),
            'mushak' => $lines->isEmpty() ? '' : $this->mushak($note),
            'has_lines' => $lines->isNotEmpty(),
            'issued_at' => PaperTaxFacts::issuedAt($note),
            'party_kind' => (string) ($this->parties->labelFor((string) $note->party_type) ?? ''),
            'party_name' => $party['name'],
            'party_code' => $party['code'],
            'party_address' => $party['address'],
            'party_phone' => $party['phone'],
            'party_bin' => $party['bin'],
            'company_bin' => $company['bin'],
            'reason' => (string) __('accounts::note.reason_'.$note->reason),
            'narration' => (string) $note->narration,
            'against_no' => (string) $note->against_no,
            'bill_issued_at' => $billAt === null ? '' : DateFormat::formatWithTime(\Illuminate\Support\Carbon::instance($billAt)),
            'supplier_note_no' => (string) ($note->supplier_note_no ?? ''),
            'supplier_note_date' => DateFormat::format($note->supplier_note_date),
            'control_account' => $control->code.' — '.$control->name(),
            'other_account' => $other->code.' — '.$other->name(),
            'amount' => Money::format($note->amount),
            'tax' => bccomp((string) $note->tax_amount, '0', 4) > 0 ? Money::format($note->tax_amount) : '',
            'total' => Money::format($note->total),
            'price_lines' => $price->map(fn (NoteLine $l) => PaperTaxFacts::line($l, $showsPrice))->all(),
            'return_lines' => $returned->map(fn (NoteLine $l) => PaperTaxFacts::line($l, $showsPrice))->all(),
            'price_amount' => $sum($price, 'amount'),
            'price_vat' => $sum($price, 'vat_amount'),
            'return_amount' => $sum($returned, 'amount'),
            'return_vat' => $sum($returned, 'vat_amount'),
            'returned_total' => bccomp($returnedTotal, '0', 4) > 0 ? Money::format($returnedTotal) : '',
            'grand_total' => Money::format($grand),
            'in_words' => AmountInWords::of($grand, app()->getLocale()),
        ];
    }
}
