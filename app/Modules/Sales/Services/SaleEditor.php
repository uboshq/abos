<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\OpenPeriod;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Note;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নিশ্চিত বিক্রি গেট পাসের আগে সম্পাদনা — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৪)।
 *
 * *"ইনভয়েসের পরে, গেট পাসের আগে: কেবল সম্পাদনা — খাতার এন্ট্রি উল্টে নতুন বসে, নম্বর একই, অডিটে আগে-পরে দুইটাই"*।
 *
 * ── ⭐ একটা লেনদেন, চার ধাপ ────────────────────────────────────────────
 *   ১ · বিলের খাতা উল্টো, খরচের স্তরে মাল ফেরে ([[SalesInvoiceService::takeBackForEdit()]])
 *   ২ · চালানের মাল তাকে ফেরে — ফ্রি আর উপহারসহ ([[DeliveryChallanService::takeBackForEdit()]])
 *   ৩ · দুই কাগজ কাউন্টারের খসড়ার অবস্থায়, আর কাউন্টারের **নিজের** নিশ্চিতের পথ ([[DirectSaleService::complete()]])
 *       নতুন সারি বসিয়ে আবার মাল বের করে, খাতা বসায় — বাকির দেয়াল, ছাড়ের সই, ফ্রির অনুপাত, লট, মার্জিন সবই আবার খাটে
 *   ৪ · নম্বর একই থাকে (INV-0154 / CHA-0154); অডিটে "সম্পাদিত" আর আগের-পরের মোট
 * ⓘ যেকোনো ধাপ আটকালে পুরোটা উল্টে যায় — অর্ধেক-উল্টানো বিক্রি কখনো থাকে না।
 *
 * ── ⛔ কখন নয় (সমন্বয়কের অনুমোদিত শর্ত, ৩ অক্টোবর ২০২৬) ───────────────
 *   গেট পাস হয়ে গেছে · বিলের বিপরীতে ফেরত বা ক্রেডিট নোট আছে · কাউন্টারের বিক্রি নয় (এই পাতার বাইরের বিল)
 *   বিলের মাস বন্ধ, বা নতুন তারিখের মাস বন্ধ — বিলটা বাতিল করে নতুন বিল (fe, ২১ অক্টোবর ২০২৬; IAS 8: বন্ধ মাসের অঙ্ক বদলায় না)
 * ⓘ আদায় হওয়া টাকা আটকায় না: রসিদ যেমন আছে থাকে; নতুন মোট কমলে বাড়তিটা খাতাতেই ক্রেতার অগ্রিম হয়।
 * ⚠️ সম্পাদনায় নতুন জমা নয় — টাকা আদায়ের পাতা থেকে; আর "খসড়া রাখুন" নয় — নিশ্চিত বিক্রি খসড়ায় ফেরে না।
 */
final class SaleEditor
{
    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly DeliveryChallanService $challans,
        private readonly DirectSaleService $counter,
    ) {}

    /**
     * এই বিল কি এখন সম্পাদনা করা যায় — না গেলে কারণসহ ব্যতিক্রম; গেলে তার চালান।
     */
    public function assertEditable(SalesInvoice $invoice): DeliveryChallan
    {
        $no = ['no' => $invoice->document_no];

        if ($invoice->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_only_confirmed', $no)]);
        }

        /*
         * ⛔ বন্ধ মাসের বিল সম্পাদনা নয় — fe, ২১ অক্টোবর ২০২৬ (IAS 8; [[AClosedMonthsBillIsNotEditedTest]])।
         * ⓘ মাসের তালা একটাই নিয়ম ([[OpenPeriod::lockOn()]], খাতার ইঞ্জিন আর বাতিল-ইনভয়েস যা দেখে)। ⚠️ আগে উল্টানো বসত আজ, নতুন
         * বিল আজকের তারিখে — বন্ধ মাসের বিক্রিটা চুপচাপ এ মাসে সরে যেত। বন্ধ মাসের ভুল বিলের পথ বাতিল-ইনভয়েস, তারপর নতুন বিল।
         */
        if (app(OpenPeriod::class)->lockOn($invoice->trx_date->format('Y-m-d')) !== null) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_month_closed', $no)]);
        }

        if ($invoice->counter_screen === null) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_only_counter', $no)]);
        }

        $challanIds = $invoice->lines()->with('challanLine')->get()
            ->map(fn ($line) => $line->challanLine?->delivery_challan_id)
            ->filter()->unique()->values();

        $challan = $challanIds->count() === 1 ? DeliveryChallan::query()->find($challanIds->first()) : null;

        if ($challan === null || $challan->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_only_counter', $no)]);
        }

        $passed = GatePass::query()
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '<>', GatePass::CANCELLED)
            ->exists();

        if ($passed) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_after_gate_pass', $no)]);
        }

        $returned = SalesReturn::query()
            ->where('sales_invoice_id', $invoice->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();

        $noted = Note::query()
            ->where('direction', Note::CREDIT)
            ->where('against_no', $invoice->document_no)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();

        if ($returned || $noted) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_after_return', $no)]);
        }

        /*
         * ⛔ বাতিলের অনুরোধ খোলা থাকলে সম্পাদনা নয় — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[ACancellationSignatureCoversTheBillItSawTest]])।
         * ⓘ মালিক যে বিল বাতিলে সই দেন, ঠিক সেটাই উল্টায়: আগে অনুরোধের পরে বিলটা বদলে দেওয়া যেত, আর সই পড়ত অন্য একটা বিলে।
         */
        $cancelling = SalesInvoiceCancellation::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereIn('status', [DocumentStatus::DRAFT, SalesInvoiceCancellation::AWAITING])
            ->value('document_no');

        if ($cancelling !== null) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_while_cancelling', [...$no, 'cxl' => $cancelling])]);
        }

        return $challan;
    }

    /**
     * সম্পাদনা — কাউন্টারের পাতার একই ঘর ([[DirectSaleController::store()]]-এর যাচাই করা মান)।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $gifts
     */
    public function edit(SalesInvoice $invoice, array $data, array $lines, array $gifts = []): SalesInvoice
    {
        if (($data['save_as_draft'] ?? null) === '1') {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_no_draft')]);
        }

        if (collect($data['deposits'] ?? [])->contains(fn ($row) => is_array($row) && bccomp($this->num($row['amount'] ?? '0'), '0', 4) > 0)) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_no_new_money')]);
        }

        /*
         * ⛔ নতুন রূপের তারিখও খোলা মাসে — ফাঁকা তারিখ মানে আজ, কাউন্টারের নিজের নিয়মে ([[DirectSaleService::complete()]])।
         * ⓘ পর্দা পুরনো তারিখ পাঠালে সেটাই বসত; বন্ধ মাসে পড়লে এখানেই থামে, কিছু উল্টানোর আগে (fe, ২১ অক্টোবর ২০২৬)।
         */
        $on = filled($data['trx_date'] ?? null) ? Carbon::parse((string) $data['trx_date']) : Carbon::today();

        if (app(OpenPeriod::class)->lockOn($on->format('Y-m-d')) !== null) {
            throw ValidationException::withMessages(['edit' => __('sales::validation.edit_month_closed', ['no' => $invoice->document_no])]);
        }

        return DB::transaction(function () use ($invoice, $data, $lines, $gifts) {
            $invoice = SalesInvoice::acrossBranches()->lockForUpdate()->findOrFail($invoice->id);
            $challan = $this->assertEditable($invoice);
            $challan = DeliveryChallan::acrossBranches()->lockForUpdate()->findOrFail($challan->id);

            $numbers = [$invoice->document_no, $challan->document_no, $invoice->sale_no];
            $before = (string) $invoice->total;
            $date = Carbon::now();
            $reason = (string) __('sales::validation.edited_after_confirm', ['no' => $invoice->document_no]);

            $this->invoices->takeBackForEdit($invoice, $date, $reason);
            $this->challans->takeBackForEdit($challan, $date, $reason);

            $this->counter->complete([
                ...$data,
                'resume_invoice_id' => $invoice->id,
                'save_as_draft' => null,
                'deposits' => [],
                // ⓘ নম্বর বদলায় না — কাউন্টার রাখা কাগজ দুটোই হালনাগাদ করে
                'challan_no' => null,
                'invoice_no' => null,
            ], $lines, $gifts);

            $fresh = $invoice->fresh();
            $freshChallan = $challan->fresh();

            /*
             * ⭐ সম্পাদনা সইয়ের অপেক্ষায় — মালিক, ৭ অক্টোবর ২০২৬ ("এডিট করতে গিয়ে আটকে গেছে"; ADI-তে প্রতিটা চালানে সই লাগে)।
             * ⛔ আগে নতুন চালান সইয়ে গেলেই "সম্পাদনা শেষ হয়নি" বলে পুরোটা উল্টাত, তাই সই-ছক থাকা কোম্পানিতে পাকা বিক্রি কখনো
             * সম্পাদনা করা যেত না। ⓘ আন্তর্জাতিক নিয়মে পাকা কাগজের বদল আবার অনুমোদনে যায়: পুরনোটা উল্টানো থাকে, নতুনটা একই
             * নম্বরে সইয়ের অপেক্ষায়; শেষ সই পড়লে আগের মতোই পাকা হয় ([[HeldCounterSaleFinisher]])। গেট পাসের আগেই কেবল সম্পাদনা
             * চলে, তাই মাল তখনো গুদামে।
             */
            if ($fresh->status !== DocumentStatus::CONFIRMED
                && [$fresh->document_no, $freshChallan->document_no, $fresh->sale_no] === $numbers
                && DirectSaleService::isHeldForSignature($fresh)) {
                $fresh->auditAction('edited', (string) __('sales::validation.edited_totals', [
                    'before' => $before,
                    'after' => (string) $fresh->total,
                ]).' — '.__('sales::validation.edit_awaiting_signature'));

                return $fresh;
            }

            // ⛔ নিশ্চিত না হয়ে ফিরলে (যেকোনো কারণে খসড়ায় থেকে গেলে) পুরো সম্পাদনা উল্টো — অর্ধেক-উল্টানো বিক্রি নয়
            if ($fresh->status !== DocumentStatus::CONFIRMED || $freshChallan->status !== DocumentStatus::CONFIRMED
                || [$fresh->document_no, $freshChallan->document_no, $fresh->sale_no] !== $numbers) {
                throw ValidationException::withMessages(['edit' => __('sales::validation.edit_could_not_finish', ['no' => $invoice->document_no])]);
            }

            $fresh->auditAction('edited', (string) __('sales::validation.edited_totals', [
                'before' => $before,
                'after' => (string) $fresh->total,
            ]));

            return $fresh;
        });
    }

    private function num(mixed $v): string
    {
        $v = trim((string) $v);

        return is_numeric($v) ? $v : '0';
    }
}
