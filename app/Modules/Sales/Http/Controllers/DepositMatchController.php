<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ জমা মেলানো — অফিসের দিক (জমার খবর ধাপ গ; মালিক, ১৬ নভেম্বর ২০২৬: "শেষ কথা ব্যাংক স্টেটমেন্ট")।
 *
 * ⓘ এক পাতায় চার ভাগ, NEXUS নমুনার অফিসের ধারা: উপরে স্টেটমেন্ট তোলা (ফাইল বা হাতে এক সারি) → নিজে থেকে মেলানোর প্রস্তাব
 * (এক চাপে মেলান) → ব্যাংকে মিলেছে, "অনুমোদন দিন" বাকি → দুই দিকের না-মেলা: খবর আর ব্যাংকের সারি, হাতে মেলানো।
 * ⓘ "অনুমোদন দিন" আগের গ্রহণের দরজাতেই যায় (`sales.claim.accept`) — খাতায় তোলার পথ একটাই।
 */
class DepositMatchController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DepositMatchService $matches,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.claim.match', only: ['index', 'load', 'line', 'match', 'unmatch']),
            new Middleware('can:sales.claim.view', only: ['receipt']),
        ];
    }

    public function index(Request $request): View
    {
        $suggested = $this->matches->suggestions();
        $suggestedIds = array_map(fn (array $p) => (int) $p['claim']->id, $suggested);

        $matched = DepositClaim::query()->inViewedBranch()->open()->whereNotNull('statement_line_id')
            ->with(['customer.location.parent', 'customer.branch', 'statementLine.bankAccount', 'matcher', 'collection'])
            ->orderBy('claimed_on')->orderBy('id')->limit(200)->get();

        $waiting = $this->matches->waitingClaims()
            ->with(['customer.location.parent', 'customer.branch', 'bankAccount'])
            ->orderBy('claimed_on')->orderBy('id')->limit(200)->get();

        $lines = $this->linesInView()->with('bankAccount')->orderBy('trx_date')->orderBy('id')->limit(200)->get();

        return view('sales::claim.match', [
            'menu' => $this->menu->forUser($request->user()),
            'suggested' => $suggested,
            'suggestedIds' => $suggestedIds,
            'matched' => $matched,
            'waiting' => $waiting,
            'lines' => $lines,
            'days' => $this->matches->windowDays(),
            'banks' => Account::query()->notAnotherBranchsTill()->active()
                ->whereIn('money_kind', [Account::BANK, Account::MFS])->where('is_group', false)->orderBy('code')->get(),
            'confirmedToday' => DepositClaim::query()->inViewedBranch()->where('status', DepositClaim::ACCEPTED)
                ->where('decided_at', '>=', now()->startOfDay())->count(),
        ]);
    }

    /** ব্যাংকের স্টেটমেন্ট ফাইল — CSV বা XLSX */
    public function load(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', \App\Core\Support\CompanyContext::id())],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
        ]);

        $result = $this->matches->load(Account::query()->findOrFail((int) $data['account_id']), $request->file('file'));

        return back()
            ->with('status', __('sales::deposit.loaded', ['added' => $result['added'], 'known' => $result['known'], 'bad' => count($result['bad'])]))
            ->withErrors($result['bad'] === [] ? [] : ['file' => implode(' · ', array_slice($result['bad'], 0, 10))]);
    }

    /** হাতে এক সারি — ব্যাংকের অ্যাপে দেখা জমা */
    public function line(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', \App\Core\Support\CompanyContext::id())],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $added = $this->matches->addByHand(Account::query()->findOrFail((int) $data['account_id']), $data);

        return back()->with('status', __($added ? 'sales::deposit.line_added' : 'sales::deposit.line_known'));
    }

    public function match(Request $request, DepositClaim $claim): RedirectResponse
    {
        $this->inView($claim);

        $data = $request->validate([
            'line' => ['required', 'integer'],
        ]);

        $line = $this->linesInView(free: false)->whereKey((int) $data['line'])->first();
        abort_if($line === null, 404);

        $this->matches->match($claim, $line);

        return back()->with('status', __('sales::deposit.matched', ['no' => $claim->public_id]));
    }

    public function unmatch(Request $request, DepositClaim $claim): RedirectResponse
    {
        $this->inView($claim);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->matches->unmatch($claim, $data['reason']);

        return back()->with('status', __('sales::deposit.unmatched'));
    }

    /**
     * ⭐ মানি রিসিট — খাতায় ওঠা জমার (কেন্দ্রীয় ছাপার ইঞ্জিন; ইংরেজি অঙ্ক; "নাম · পয়েন্ট · শাখা"; প্রস্তুতকারী / অনুমোদনকারী)।
     * ⛔ খাতায় না ওঠা খবরের রিসিট নয় — হাতে পেলে দোকানি ভাবতেন টাকা জমা হয়ে গেছে।
     */
    public function receipt(Request $request, DepositClaim $claim, PrintEngine $print): Response
    {
        $this->inView($claim);
        abort_unless($claim->isAccepted() && $claim->collection_id !== null, 404);

        $claim->load(['customer.location.parent', 'customer.branch', 'collection.account', 'statementLine', 'submitter', 'decider', 'matcher']);
        $collection = $claim->collection;
        abort_if($collection === null, 404);

        $amount = (string) $collection->amount;

        $doc = new PrintableDocument(
            title: __('sales::deposit.receipt_title'),
            meta: array_filter([
                'sales::deposit.receipt_no' => (string) $collection->document_no,
                'core.print.date' => DateFormat::format($collection->trx_date),
                'sales::field.customer' => (string) $claim->customer?->nameWithPoint(),
                'sales::field.account' => (string) $collection->account?->name(),
                'sales::portal.reference' => (string) ($claim->reference ?? ''),
                'sales::slip.depositor' => (string) ($claim->depositor ?? ''),
                'sales::deposit.bank_date' => $claim->statementLine === null ? '' : DateFormat::format($claim->statementLine->trx_date),
                'sales::deposit.claim_no' => (string) $claim->public_id,
            ], fn (string $v) => $v !== ''),
            lines: [[
                'name' => (string) __('sales::deposit.receipt_line', ['date' => DateFormat::format($claim->claimed_on)]),
                'qty' => '',
                'unit' => '',
                'rate' => '',
                'amount' => Money::format($amount),
            ]],
            totals: array_filter([
                'sales::field.bank_charge' => bccomp((string) ($collection->bank_charge ?? '0'), '0', 4) > 0 ? Money::format($collection->bank_charge) : '',
                'core.print.total' => Money::format($amount),
            ], fn (string $v) => $v !== ''),
            signatures: ['core.print.prepared_by', 'core.print.approved_by'],
            narration: $collection->narration,
        );

        $locale = \App\Core\Support\PaperLanguage::for($claim->customer);
        $pdf = $print->render(
            template: 'print.document',
            data: ['doc' => $doc->withWordsFor($amount, $locale), 'title' => $doc->title.' '.$collection->document_no],
            paper: PaperSize::A5,
            locale: $locale,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$collection->document_no.'.pdf"',
        ]);
    }

    /** ⛔ দেখা শাখার বাইরের খবর — ৪০৪ (শাখার দেয়াল) */
    private function inView(DepositClaim $claim): void
    {
        abort_unless(DepositClaim::query()->inViewedBranch()->whereKey($claim->getKey())->exists(), 404);
    }

    /**
     * ব্যাংকের সারি — দেখা শাখার, বা শাখাহীন (প্রধান কার্যালয়ের তোলা)।
     *
     * @return \Illuminate\Database\Eloquent\Builder<BankStatementLine>
     */
    private function linesInView(bool $free = true): \Illuminate\Database\Eloquent\Builder
    {
        $branch = ViewedBranch::one();

        return ($free ? $this->matches->freeLines() : BankStatementLine::query())
            ->when($branch !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branch)));
    }
}
