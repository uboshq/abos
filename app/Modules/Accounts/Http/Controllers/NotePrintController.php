<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\BranchSettings;
use App\Core\Services\PaperTrail;
use App\Core\Services\PartyRegistry;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteAccounts;
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
            $paper = PaperSize::chosen($request->query('paper'), $this->branch->get('accounts.print.paper.note'));
            $asFile = $request->boolean('download');

            $printed = $this->trail->countsFor('accounts_note', (int) $note->id)[DocumentDelivery::PRINTED] ?? 0;

            $notice = match (true) {
                $note->isCancelled() => (string) __('accounts::print.cancelled'),
                $printed > 0 && ! $asFile => (string) __('core.print.duplicate_notice', ['n' => $printed + 1]),
                default => null,
            };

            $pdf = $this->print->render(
                template: 'print.note',
                data: [
                    'title' => $this->direction($note).' '.$note->document_no,
                    'note' => $this->paper($note),
                    'notice' => $notice,
                    'signatures' => [
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

    /** @return array<string, string> */
    private function paper(Note $note): array
    {
        $party = $this->parties->paperFacts((string) $note->party_type, (int) $note->party_id)
            ?? ['name' => '', 'code' => '', 'address' => '', 'phone' => ''];

        // ⭐ দুই খাত কাগজেও — সমন্বয়কের শর্ত, ৩ অক্টোবর ২০২৬ ([[NoteAccounts]])
        ['control' => $control, 'other' => $other] = app(NoteAccounts::class)->of($note);

        return [
            'document_no' => (string) $note->document_no,
            'date' => DateFormat::format($note->trx_date),
            'direction' => $this->direction($note),
            'party_kind' => (string) ($this->parties->labelFor((string) $note->party_type) ?? ''),
            'party_name' => $party['name'],
            'party_code' => $party['code'],
            'party_address' => $party['address'],
            'party_phone' => $party['phone'],
            'reason' => (string) __('accounts::note.reason_'.$note->reason),
            'narration' => (string) $note->narration,
            'against_no' => (string) $note->against_no,
            'control_account' => $control->code.' — '.$control->name(),
            'other_account' => $other->code.' — '.$other->name(),
            'amount' => Money::format($note->amount),
            'tax' => bccomp((string) $note->tax_amount, '0', 4) > 0 ? Money::format($note->tax_amount) : '',
            'total' => Money::format($note->total),
            'in_words' => AmountInWords::of((string) $note->total, app()->getLocale()),
        ];
    }
}
