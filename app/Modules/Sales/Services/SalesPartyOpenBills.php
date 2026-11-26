<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Support\DocumentStatus;
use App\Core\Support\OpeningRow;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Note;
use App\Modules\Sales\Models\SalesInvoice;
use Carbon\Carbon;

/**
 * গ্রাহকের খোলা বিক্রয় বিল — রসিদের "কোন বিলের বিপরীতে" তালিকা ([[PartyOpenBills]], Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ বাকি = [[SalesInvoice::dueAmount()]] — আদায়, বিলে বাঁধা রসিদ আর পাকা ফেরত বাদ দিয়ে; বিলের নিজের পাতা আর আদায়ের পর্দা
 * ঠিক এই অঙ্কই দেখায়। ⛔ আলাদা করে মাপলে (আগের কাঁচা কোয়েরি) ফেরত বাদ যেত না, আর দুই পর্দায় দুই বাকি দেখাত।
 *
 * ⓘ রসিদ একটাই বিলের বিপরীতে বাঁধা যায় (`against_id`, গ২-এর যাচাইসহ); এক টাকায় বহু বিল আর "পুরনো বিল আগে" হয় বিক্রয়ের
 * "আদায়" পর্দায় — হিসাবের উৎস একটাই থাকে (৬৩-এর সিদ্ধান্ত, ৫ অক্টোবর ২০২৬)।
 */
final class SalesPartyOpenBills implements PartyOpenBills
{
    public function partyType(): string
    {
        return 'customer';
    }

    /**
     * ⓘ খাতার জের ([[Customer::outstanding()]]-এর একই যোগ, গোটা কোম্পানি) − সব পাকা বিলের নিজের বাকি ([[SalesInvoice::ownDue()]];
     * বিলের তালিকার একই অঙ্ক) − নোট চাইলে বিলে না-বাঁধা নোট (ক্রেডিট ঋণাত্মক)।
     * ⓘ আপনা থেকে শোধের ভাগ ([[AutoDues]]) এই অঙ্ক বদলায় না: জের বিলের যোগের বেশি হলে কোনো বিলই আপনা থেকে শোধ হয় না।
     */
    public function openingRow(string $partyType, int $partyId, bool $withNotes = false): ?array
    {
        if ($partyType !== 'customer' || $partyId <= 0) {
            return null;
        }

        $ledger = LedgerEntry::query()->forParty('customer', $partyId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net, MIN(trx_date) as since')
            ->first();

        $onBills = '0';
        // ⛔ জের গোটা কোম্পানির — বিলও সব শাখার, নইলে অন্য শাখার বিল "আগের জের" হয়ে দেখা দিত
        foreach (SalesInvoice::acrossBranches()->withCollected()->where('customer_id', $partyId)->where('status', DocumentStatus::CONFIRMED)->cursor() as $invoice) {
            $onBills = bcadd($onBills, $invoice->ownDue(), 4);
        }

        if ($withNotes) {
            $notes = Note::acrossBranches()->where('party_type', 'customer')->where('party_id', $partyId)->whereNull('against_id')
                ->whereIn('status', DocumentStatus::POSTED)->get(['direction', 'total']);

            foreach ($notes as $note) {
                $onBills = $note->direction === Note::CREDIT ? bcsub($onBills, (string) $note->total, 4) : bcadd($onBills, (string) $note->total, 4);
            }
        }

        return OpeningRow::make('customer', $partyId, bcadd((string) ($ledger?->net ?? 0), '0', 4), $onBills,
            $ledger?->since === null ? null : (string) $ledger->since);
    }

    public function openBills(string $partyType, int $partyId, int $limit = 50, bool $withNotes = false): array
    {
        if ($partyType !== 'customer' || $partyId <= 0) {
            return [];
        }

        $today = now()->startOfDay();
        $open = [];

        $invoices = SalesInvoice::query()
            ->withCollected()
            ->where('customer_id', $partyId)
            ->where('status', DocumentStatus::CONFIRMED)
            ->orderBy('trx_date')
            ->orderBy('id')
            ->cursor();

        foreach ($invoices as $invoice) {
            $due = $invoice->ownDue();

            if (bccomp($due, '0', 4) <= 0) {
                continue;
            }

            $date = Carbon::parse($invoice->trx_date)->startOfDay();
            // ⓘ শেষ দিন না থাকলে বিলের দিনই — ঋণ নিয়ন্ত্রণের বকেয়া রিপোর্টের একই নিয়ম (COALESCE(due_on, trx_date))
            $dueDate = $invoice->due_on !== null ? Carbon::parse($invoice->due_on)->startOfDay() : $date;

            $open[] = [
                'kind' => 'invoice',
                'against_type' => SalesInvoice::drillSourceType(),
                'id' => (int) $invoice->id,
                'no' => (string) $invoice->document_no,
                'date' => $date->toDateString(),
                // ⓘ বয়স সার্ভারে — ফোনের ঘড়ি ভুল থাকলে ব্রাউজারে গোনা বয়সও ভুল হত
                'age' => (int) $date->diffInDays($today),
                'outstanding' => $due,
                // ⭐ শেষ দিন, দেরি আর মূল টাকা (মালিকের রসিদ-পরিশোধ নকশা, মিল-তালিকা দফা ৩৪, ২৪ অক্টোবর ২০২৬); দেরি সার্ভারে, বয়সের মতো
                'due_date' => $dueDate->toDateString(),
                'days_late' => $dueDate->lessThan($today) ? (int) $dueDate->diffInDays($today) : 0,
                'total' => (string) $invoice->total,
                'public_id' => (string) $invoice->public_id,
            ];

            if (count($open) >= $limit) {
                break;
            }
        }

        if (! $withNotes) {
            return $open;
        }

        /*
         * ⭐ বিল ছাড়া নোট — কোনো বিলে বাঁধা নয় (`against_id` খালি), তাই কোনো বিলের বাকিতে নেই; খাতায় পাওনা বদলেছে, তাই তালিকায়
         * নিজের সারি (SAP-এর open item)। ⓘ লেখা `against_no` (যেমন UB-র "Damage Bill") বাঁধন নয় — fe, ২৫ অক্টোবর ২০২৬।
         */
        $notes = Note::query()
            ->where('party_type', 'customer')
            ->where('party_id', $partyId)
            ->whereNull('against_id')
            ->whereIn('status', DocumentStatus::POSTED)
            ->orderBy('trx_date')
            ->orderBy('id')
            ->get();

        foreach ($notes as $note) {
            $date = Carbon::parse($note->trx_date)->startOfDay();
            $credit = $note->direction === Note::CREDIT;
            $amount = $credit ? bcsub('0', (string) $note->total, 4) : bcadd((string) $note->total, '0', 4);

            $open[] = [
                'kind' => $credit ? 'credit_note' : 'debit_note',
                'against_type' => Note::drillSourceType(),
                'id' => (int) $note->id,
                'no' => (string) $note->document_no,
                'date' => $date->toDateString(),
                'age' => (int) $date->diffInDays($today),
                'outstanding' => $amount,
                'due_date' => $date->toDateString(),
                // ⓘ ক্রেডিট নোট গ্রাহকের পাওনা — দেরির কিছু নেই; ডেবিট নোট বিলের মতো নিজের দিনে পাওনা
                'days_late' => $credit || ! $date->lessThan($today) ? 0 : (int) $date->diffInDays($today),
                'total' => $amount,
                'public_id' => (string) $note->public_id,
            ];
        }

        // ⓘ পুরনো আগে — বিল আর নোট এক ক্রমে, তারপর সীমা
        usort($open, fn (array $a, array $b) => [$a['date'], $a['kind'] !== 'invoice', $a['id']] <=> [$b['date'], $b['kind'] !== 'invoice', $b['id']]);

        return array_slice($open, 0, $limit);
    }
}
