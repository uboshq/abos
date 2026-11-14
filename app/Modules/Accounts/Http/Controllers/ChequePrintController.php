<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\ChequeBook;
use App\Modules\Accounts\Models\ChequeLayout;
use App\Modules\Accounts\Models\ChequePrint;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\ChequePrinter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ চেক ছাপার পাতাগুলো — মালিকের অনুমোদিত নমুনা (W63BXTDuqM65tDaKRDnvwR, ১০ নভেম্বর ২০২৬):
 * ভাউচারের চেক (নমুনা ২–৩), শুধু চেক ছাপা — হিসাব ছাড়া (৬), ছাপা চেকের খাতা (৫), চেক বই ও মাপ (৪)। কাজ [[ChequePrinter]]-এ।
 *
 * ⓘ ছাপা মানে PDF — পাতা ঠিক চেকের মাপের; ফর্ম নতুন ট্যাবে খোলে, সেখান থেকে "আসল মাপে" ছাপা। পরীক্ষার ছাপ খাতায় ওঠে না।
 */
class ChequePrintController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public function __construct(
        private readonly ChequePrinter $printer,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.cheque.print', only: ['voucherForm', 'voucherPrint', 'voucherTest', 'chequeForm', 'chequePrint', 'chequeTest']),
            new Middleware('can:accounts.cheque.print_free', only: ['freeForm', 'freePrint']),
            // ⓘ আবার ছাপা আর নষ্ট — খাতা দেখার চাবি দরজায়, তারপর কাগজ ধরে ছাপার চাবি ([[mayTouch()]])
            new Middleware('can:accounts.cheque.view', only: ['log', 'again', 'spoil']),
            new Middleware('can:accounts.cheque.layout', only: ['setup', 'saveLayout', 'saveBook']),
        ];
    }

    public function voucherForm(Request $request, Voucher $voucher): View|RedirectResponse
    {
        // ⓘ ভাউচারের চেক খাতায় থাকলে (সরবরাহকারীর পরিশোধ) ছাপা সেই চেক থেকে — একই চেকের দুই সারি নয়
        $cheque = Cheque::query()->where('voucher_id', $voucher->id)->where('direction', Cheque::ISSUED)->first();

        if ($cheque !== null) {
            return redirect()->route('accounts.cheque.print.cheque', $cheque);
        }

        $books = ChequeBook::query()->usable()->where('off_books', false)->where('bank_account_id', $voucher->money_account_id)->with('layout')->orderBy('label')->get();
        $earlier = ChequePrint::query()->where('voucher_id', $voucher->id)->with('book')->latest('id')->get();
        $ready = $voucher->type === Voucher::PAYMENT && $voucher->isPosted() && $voucher->instrument === 'cheque';
        $amount = $ready ? $this->safeAmount($voucher) : null;

        return $this->paperView($request, $books, $earlier, [
            'no' => (string) $voucher->document_no,
            'date' => $voucher->instrument_date ?? $voucher->trx_date,
            'amount' => $amount,
            'not_ready' => $ready && $amount !== null ? null : __('accounts::cheque_print.voucher_not_ready'),
            'payee' => $this->printer->payeeOf($voucher),
            'written' => (string) $voucher->instrument_no,
            'store' => route('accounts.cheque.print.voucher.store', $voucher),
            'test' => route('accounts.cheque.print.voucher.test', $voucher),
        ]);
    }

    public function chequeForm(Request $request, Cheque $cheque): View
    {
        $books = ChequeBook::query()->usable()->where('off_books', false)
            ->when($cheque->bank_account_id !== null, fn ($q) => $q->where('bank_account_id', $cheque->bank_account_id))
            ->with('layout')->orderBy('label')->get();
        $earlier = ChequePrint::query()->where('cheque_id', $cheque->id)->with('book')->latest('id')->get();
        $ready = $cheque->direction === Cheque::ISSUED && $cheque->status === Cheque::PENDING;

        return $this->paperView($request, $books, $earlier, [
            'no' => (string) $cheque->document_no,
            'date' => $cheque->cheque_date,
            'amount' => bcadd((string) $cheque->amount, '0', 4),
            'not_ready' => $ready ? null : __('accounts::cheque_print.cheque_not_ready'),
            'payee' => $this->printer->payeeOf($cheque),
            'written' => (string) $cheque->cheque_no,
            'store' => route('accounts.cheque.print.cheque.store', $cheque),
            'test' => route('accounts.cheque.print.cheque.test', $cheque),
        ]);
    }

    public function chequePrint(Request $request, Cheque $cheque): Response
    {
        $data = $request->validate([
            'book' => ['required', 'integer'],
            'payee' => ['required', 'string', 'max:160'],
            'crossed' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $book = ChequeBook::query()->with('layout')->findOrFail($data['book']);
        $print = $this->printer->forCheque($cheque, $book, trim($data['payee']), $request->boolean('crossed'), $data['reason'] ?? null);

        return $this->pdf($book->layout, $print, $book->number($print->cheque_no));
    }

    public function chequeTest(Request $request, Cheque $cheque): Response
    {
        $data = $request->validate(['book' => ['required', 'integer'], 'payee' => ['nullable', 'string', 'max:160'], 'crossed' => ['nullable', 'boolean']]);
        $book = ChequeBook::query()->with('layout')->findOrFail($data['book']);

        return $this->inline($this->printer->pdf($book->layout, [
            'cheque_date' => $cheque->cheque_date, 'payee' => $data['payee'] ?? null,
            'amount' => bcadd((string) $cheque->amount, '0', 4), 'crossed' => $request->boolean('crossed'),
        ], true), 'test');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ChequeBook>  $books
     * @param  \Illuminate\Support\Collection<int, ChequePrint>  $earlier
     * @param  array<string, mixed>  $paper
     */
    private function paperView(Request $request, $books, $earlier, array $paper): View
    {
        $printed = $earlier->firstWhere('status', ChequePrint::PRINTED);
        $first = $books->first();
        $written = preg_replace('/\D/', '', (string) $paper['written']) ?? '';
        $inBook = $first !== null && $written !== '' && strlen($written) <= 15 && (int) $written >= $first->first_no && (int) $written <= $first->last_no;
        $paper['number'] = $printed !== null ? $printed->book?->number($printed->cheque_no)
            : ($first === null ? '—' : $first->number($inBook ? (int) $written : $first->next_no));

        return view('accounts::cheque_print.voucher', [
            'menu' => $this->menu->forUser($request->user()),
            'paper' => $paper,
            'books' => $books,
            'earlier' => $earlier,
            'printed' => $printed,
        ]);
    }

    public function voucherPrint(Request $request, Voucher $voucher): Response
    {
        $data = $request->validate([
            'book' => ['required', 'integer'],
            'payee' => ['required', 'string', 'max:160'],
            'crossed' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $book = ChequeBook::query()->with('layout')->findOrFail($data['book']);
        $print = $this->printer->forVoucher($voucher, $book, trim($data['payee']), $request->boolean('crossed'), $data['reason'] ?? null);

        return $this->pdf($book->layout, $print, $book->number($print->cheque_no));
    }

    public function voucherTest(Request $request, Voucher $voucher): Response
    {
        $data = $request->validate(['book' => ['required', 'integer'], 'payee' => ['nullable', 'string', 'max:160'], 'crossed' => ['nullable', 'boolean']]);
        $book = ChequeBook::query()->with('layout')->findOrFail($data['book']);

        return $this->inline($this->printer->pdf($book->layout, [
            'cheque_date' => $voucher->instrument_date ?? $voucher->trx_date, 'payee' => $data['payee'] ?? null,
            'amount' => $this->printer->paidFromBank($voucher), 'crossed' => $request->boolean('crossed'),
        ], true), 'test');
    }

    public function freeForm(Request $request): View
    {
        return view('accounts::cheque_print.free', [
            'menu' => $this->menu->forUser($request->user()),
            'books' => ChequeBook::query()->usable()->where('off_books', true)->with('layout')->orderBy('label')->get(),
            'recent' => ChequePrint::query()->where('off_books', true)->with(['book', 'printer'])->latest('id')->limit(5)->get(),
        ]);
    }

    /**
     * ⓘ পরীক্ষার ছাপ একই দরজায় (`test=1`) — খাতায় কিছু ওঠে না, বইয়ের নম্বর নড়ে না।
     *
     * ⭐ বই না থাকলেও ছাপা যায় — মালিক, 11 নভেম্বর 2026: "শুধু চেক ছাপা কি করে create করবে, কিছুই নাই, জাস্ট মেনুতে চাপলে
     * স্ক্রিন আসে"। ⛔ আগে বই ছাড়া পাতায় ফর্মই ছিল না, কেবল "চেক বই ও মাপ পাতায় বসান"। ⓘ এখন ফর্মেই নতুন বই (ব্যাংক বা বইয়ের নাম,
     * প্রথম আর শেষ চেক নম্বর) — আসল ছাপার সাথেই বইটা বসে, মাপ বাংলাদেশ ব্যাংকের মানক (কোম্পানির প্রথম মাপ); পরীক্ষার ছাপে কিছুই বসে না।
     */
    public function freePrint(Request $request): Response
    {
        $data = $this->freeData($request);
        $test = $request->boolean('test');
        $book = $data['book'] !== null
            ? ChequeBook::query()->with('layout')->findOrFail($data['book'])
            : $this->newFreeBook($request, $test);

        if ($test) {
            return $this->inline($this->printer->pdf($book->layout, $data, true), 'test');
        }

        $print = $this->printer->free($book, $data);

        return $this->pdf($book->layout, $print, $book->number($print->cheque_no));
    }

    /** ⓘ ফর্মে লেখা নতুন হিসাব-ছাড়া বই — পরীক্ষার ছাপে কেবল মনে রাখা (সেভ নয়), আসল ছাপায় সেভ */
    private function newFreeBook(Request $request, bool $test): ChequeBook
    {
        $new = $request->validate([
            'new_label' => ['required', 'string', 'max:160'],
            'new_first' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'new_last' => ['required', 'string', 'regex:/^\d{1,12}$/'],
        ], [], [
            'new_label' => __('accounts::cheque_print.new_label'),
            'new_first' => __('accounts::cheque_print.new_first'),
            'new_last' => __('accounts::cheque_print.new_last'),
        ]);

        if ((int) $new['new_last'] < (int) $new['new_first']) {
            throw ValidationException::withMessages(['new_last' => __('accounts::cheque_print.range_wrong')]);
        }

        $layout = ChequeLayout::query()->orderBy('id')->first() ?? ChequeLayout::query()->create([
            'company_id' => CompanyContext::id(), 'created_by' => $request->user()?->id,
            'name' => __('accounts::cheque_print.standard_name'), 'offset_x' => 0, 'offset_y' => 0,
        ] + ChequeLayout::standard());

        // ⓘ একই বই আবার লেখা হলে (পাতা নতুন করে না খুলে দ্বিতীয় চেক) — নতুন বই নয়, সেটাই; নইলে একই নম্বর দুই বইয়ে
        $same = ChequeBook::query()->with('layout')->where('off_books', true)->where('label', trim($new['new_label']))
            ->where('first_no', (int) $new['new_first'])->where('last_no', (int) $new['new_last'])->first();

        if ($same !== null) {
            return $same;
        }

        $book = new ChequeBook([
            'company_id' => CompanyContext::id(), 'created_by' => $request->user()?->id, 'off_books' => true, 'bank_account_id' => null,
            'label' => trim($new['new_label']), 'first_no' => (int) $new['new_first'], 'last_no' => (int) $new['new_last'],
            'next_no' => (int) $new['new_first'], 'digits' => max(strlen($new['new_first']), strlen($new['new_last'])),
            'layout_id' => $layout->id, 'is_active' => true,
        ]);
        $book->setRelation('layout', $layout);

        if (! $test) {
            $book->save();
        }

        return $book;
    }

    public function log(Request $request): View
    {
        $mayFree = $request->user()?->can('accounts.cheque.print_free') ?? false;
        $query = ChequePrint::query()
            // ⛔ হিসাব ছাড়া চেক কেবল যাঁর সেই চাবি আছে, তিনিই দেখেন
            ->when(! $mayFree, fn ($q) => $q->where('off_books', false))
            ->with(['book', 'voucher', 'printer'])
            ->latest('id');

        return view('accounts::cheque_print.log', [
            'menu' => $this->menu->forUser($request->user()),
            'prints' => (clone $query)->paginate(\App\Core\Support\PerPage::of())->withQueryString(),
            'grand' => $this->grandTotals($query->where('status', ChequePrint::PRINTED), ['amount' => 't.amount']),
            'mayPrint' => $request->user()?->can('accounts.cheque.print') ?? false,
            'mayFree' => $mayFree,
        ]);
    }

    public function again(Request $request, ChequePrint $print): Response
    {
        $this->mayTouch($request, $print);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:200']])['reason'];
        $print = $this->printer->again($print, null, null, $reason);
        $book = $print->book()->with('layout')->firstOrFail();

        return $this->pdf($book->layout, $print, $book->number($print->cheque_no));
    }

    public function spoil(Request $request, ChequePrint $print): RedirectResponse
    {
        $this->mayTouch($request, $print);
        $this->printer->spoil($print, (string) $request->validate(['reason' => ['required', 'string', 'max:200']])['reason']);

        return back()->with('saved', __('accounts::cheque_print.saved'));
    }

    public function setup(Request $request): View
    {
        return view('accounts::cheque_print.setup', [
            'menu' => $this->menu->forUser($request->user()),
            'layouts' => ChequeLayout::query()->orderBy('name')->get(),
            'books' => ChequeBook::query()->with(['layout', 'bankAccount'])->orderByDesc('is_active')->orderBy('label')->get(),
            'banks' => Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->get(),
            'standard' => ChequeLayout::standard(),
        ]);
    }

    public function saveLayout(Request $request): RedirectResponse
    {
        $rules = [
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'width_mm' => ['required', 'numeric', 'min:50', 'max:300'],
            'height_mm' => ['required', 'numeric', 'min:30', 'max:200'],
            'offset_x' => ['nullable', 'numeric', 'min:-30', 'max:30'],
            'offset_y' => ['nullable', 'numeric', 'min:-30', 'max:30'],
        ];

        foreach (ChequeLayout::standard()['fields'] as $name => $keys) {
            foreach (array_keys($keys) as $key) {
                $rules["fields.$name.$key"] = ['required', 'numeric', 'min:-90', 'max:300'];
            }
        }

        $data = $request->validate($rules);
        $fields = [];

        foreach (ChequeLayout::standard()['fields'] as $name => $keys) {
            foreach (array_keys($keys) as $key) {
                $fields[$name][$key] = bcadd((string) $data['fields'][$name][$key], '0', 2);
            }
        }

        $layout = filled($data['id'] ?? null) ? ChequeLayout::query()->findOrFail($data['id']) : new ChequeLayout(['company_id' => CompanyContext::id(), 'created_by' => $request->user()?->id]);
        $layout->fill([
            'name' => $data['name'], 'width_mm' => $data['width_mm'], 'height_mm' => $data['height_mm'],
            'offset_x' => $data['offset_x'] ?? 0, 'offset_y' => $data['offset_y'] ?? 0, 'fields' => $fields,
        ])->save();

        return back()->with('saved', __('accounts::cheque_print.saved'));
    }

    public function saveBook(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'label' => ['required', 'string', 'max:160'],
            'off_books' => ['nullable', 'boolean'],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', CompanyContext::id())],
            'first_no' => ['required', 'integer', 'min:1'],
            'last_no' => ['required', 'integer', 'min:1'],
            'digits' => ['required', 'integer', 'min:1', 'max:12'],
            'layout_id' => ['required', 'integer', Rule::exists('acc_cheque_layouts', 'id')->where('company_id', CompanyContext::id())],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $offBooks = $request->boolean('off_books');

        if ($data['last_no'] < $data['first_no']) {
            throw ValidationException::withMessages(['last_no' => __('accounts::cheque_print.range_wrong')]);
        }

        if (! $offBooks && blank($data['bank_account_id'] ?? null)) {
            throw ValidationException::withMessages(['bank_account_id' => __('accounts::cheque_print.bank_needed')]);
        }

        $book = filled($data['id'] ?? null) ? ChequeBook::query()->findOrFail($data['id']) : new ChequeBook(['company_id' => CompanyContext::id(), 'created_by' => $request->user()?->id, 'next_no' => $data['first_no']]);
        $book->fill([
            'label' => $data['label'], 'off_books' => $offBooks, 'bank_account_id' => $offBooks ? null : $data['bank_account_id'],
            'first_no' => $data['first_no'], 'last_no' => $data['last_no'], 'digits' => $data['digits'],
            'layout_id' => $data['layout_id'], 'is_active' => $request->boolean('is_active', true),
        ])->save();

        return back()->with('saved', __('accounts::cheque_print.saved'));
    }

    /**
     * @return array{book: ?int, cheque_date: string, payee: string, amount: string, crossed: bool, purpose: ?string}
     */
    private function freeData(Request $request): array
    {
        $data = $request->validate([
            // ⓘ "new" বা খালি = ফর্মে লেখা নতুন বই ([[newFreeBook()]])
            'book' => ['nullable', 'regex:/^(new|\d+)$/'],
            'cheque_date' => ['required', 'date'],
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'purpose' => ['nullable', 'string', 'max:200'],
        ]);

        return [
            'book' => ctype_digit((string) ($data['book'] ?? '')) ? (int) $data['book'] : null, 'cheque_date' => (string) $data['cheque_date'], 'payee' => trim((string) $data['payee']),
            'amount' => (string) $data['amount'], 'crossed' => $request->boolean('crossed'), 'purpose' => $data['purpose'] ?? null,
        ];
    }

    /** ⛔ আবার ছাপা আর নষ্ট — কোম্পানির চেকে ছাপার চাবি, হিসাব ছাড়া চেকে হিসাব-ছাড়ার চাবি */
    private function mayTouch(Request $request, ChequePrint $print): void
    {
        abort_unless($request->user()?->can($print->off_books ? 'accounts.cheque.print_free' : 'accounts.cheque.print'), 403);
    }

    private function safeAmount(Voucher $voucher): ?string
    {
        try {
            return $this->printer->paidFromBank($voucher);
        } catch (ValidationException) {
            return null;
        }
    }

    private function pdf(ChequeLayout $layout, ChequePrint $print, string $no): Response
    {
        return $this->inline($this->printer->pdf($layout, [
            'cheque_date' => $print->cheque_date, 'payee' => $print->payee, 'amount' => (string) $print->amount, 'crossed' => $print->crossed,
        ]), 'cheque-'.$no);
    }

    private function inline(string $pdf, string $name): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
