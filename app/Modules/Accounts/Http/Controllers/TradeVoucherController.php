<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\TradeVoucherService;
use App\Modules\Accounts\Support\TradeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[TradeVoucherService]])।
 *
 * ⓘ মাথা এক সারিতে: পক্ষ | তারিখ·সময় (তালা) | শেষ দিন (পক্ষের শর্ত থেকে, তালা) | রেফ/পিও (SV) বা সরবরাহকারীর বিল নম্বর +
 * মূসক-৬.৩ (PUV) | মোট। ⛔ টাকার মাধ্যম, ব্যাংক বা নগদের ঘর নেই — পাকা SV-র পাতায় "টাকা নিন" রসিদ খোলে।
 * ⓘ চাবি হিসাবের ভাউচারের: দেখা `accounts.report`, লেখা `accounts.voucher.create` (দৈনিক ভাউচারের সারিগুলোর একই)।
 */
class TradeVoucherController extends Controller implements HasMiddleware
{
    /** ⓘ কাগজের রং — বিক্রয় ভাউচার টিল, ক্রয় ভাউচার বেগুনি (নকশা v5) */
    public const TONES = [TradeVoucher::SALE => 'teal', TradeVoucher::PURCHASE => 'purple'];

    public function __construct(
        private readonly TradeVoucherService $vouchers,
        private readonly PartyRegistry $parties,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.report', only: ['show']),
            new Middleware('can:accounts.voucher.create', only: ['create', 'store', 'confirm', 'cancel']),
        ];
    }

    public function create(Request $request, string $kind): View|RedirectResponse
    {
        // ⓘ সুইচ বন্ধ — নতুন কাগজ নয়; তালিকায় ফিরে কারণটা বলা
        if (! $this->vouchers->isOn()) {
            return redirect()->route('accounts.trade_voucher.index', ['kind' => $kind])->withErrors(['kind' => __('accounts::trade.switched_off')]);
        }

        $catalog = app(TradeCatalog::class);
        $partyType = TradeVoucherService::partyType($kind);
        $options = collect($this->parties->forPicker())->firstWhere('type', $partyType)['options'] ?? [];
        $ids = array_map(fn (array $o) => (int) $o['id'], $options);

        return view('accounts::trade-voucher.create', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'tone' => self::TONES[$kind],
            'parties' => $options,
            'partyId' => $request->integer('party_id') ?: null,
            // ⓘ পক্ষের শর্তের দিন — শেষ দিন পর্দায় নিজে বসে (তালা); সার্ভারও একই নিয়মে বসায়
            'creditDays' => $this->creditDays($partyType, $ids),
            'numberPreview' => app(NumberSeriesEngine::class)->upcoming(TradeVoucher::DOC_TYPES[$kind]),
            'approver' => app(ApprovalEngine::class)->firstSigner(AccountsSignature::MODULE,
                $kind === TradeVoucher::SALE ? AccountsSignature::SALES_VOUCHER : AccountsSignature::PURCHASE_VOUCHER, null, class_basename(TradeVoucher::class)),
            'products' => $catalog->products(),
            'taxes' => $catalog->taxes(),
            'accounts' => $this->vouchers->accountChoices($kind),
            'productAccount' => $this->accountLabel($this->vouchers->productAccountId($kind)),
            'storeUrl' => route('accounts.trade_voucher.store', ['kind' => $kind]),
            'listUrl' => route('accounts.trade_voucher.index', ['kind' => $kind]),
            'now' => Carbon::now(),
            // ⭐ মজুদ চালু কোম্পানিতে ছোট সতর্কতা — এখানে মাল নড়ে না (থামায় না)
            'inventoryOn' => $this->vouchers->inventoryIsOn(),
            'binMissing' => NoteController::companyBinMissing(),
            'narrationRequired' => (bool) app(SettingsService::class)->get('accounts.require_narration', true),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $lines = $request->input('lines');

        if (! is_array($lines) && is_string($request->input('lines_json'))) {
            $lines = json_decode((string) $request->input('lines_json'), true);
        }

        $request->merge(['lines' => is_array($lines) ? array_values($lines) : []]);

        $data = $request->validate([
            'party_id' => ['required', 'integer', 'min:1'],
            'ref_no' => ['nullable', 'string', 'max:64'],
            'mushak_no' => ['nullable', 'string', 'max:64'],
            'mushak_date' => ['nullable', 'date', 'before_or_equal:today'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'narration' => [(bool) app(SettingsService::class)->get('accounts.require_narration', true) ? 'required' : 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array'],
            'submit' => ['nullable', 'string'],
        ]);

        $data['trx_date'] = Carbon::today()->toDateString();

        $voucher = $this->vouchers->create($kind, $data);

        if ($request->input('submit') === 'send') {
            $voucher = $this->vouchers->confirm($voucher);

            return redirect()->route('accounts.trade_voucher.show', ['kind' => $kind, 'voucher' => $voucher->id])
                ->with('saved', $voucher->isDraft() ? __('accounts::note.awaiting_signature') : __('accounts::trade.posted'));
        }

        return redirect()->route('accounts.trade_voucher.show', ['kind' => $kind, 'voucher' => $voucher->id])
            ->with('saved', __('accounts::trade.saved'));
    }

    public function show(Request $request, string $kind, TradeVoucher $voucher): View
    {
        abort_unless($voucher->kind === $kind, 404);

        $voucher->load('lines.account');
        $pair = [[$voucher->party_type, (int) $voucher->party_id]];
        $key = $voucher->party_type.':'.$voucher->party_id;
        $rows = $this->vouchers->postingLines($voucher);
        $names = Account::query()->whereIn('id', array_column($rows, 'account_id'))->get()->keyBy('id');

        return view('accounts::trade-voucher.show', [
            'menu' => $this->menu->forUser($request->user()),
            'voucher' => $voucher,
            'kind' => $kind,
            'tone' => self::TONES[$kind],
            'partyName' => $this->parties->labelsOf($pair)[$key] ?? '—',
            'partyRoute' => $this->parties->routesOf($pair)[$key] ?? null,
            'postings' => array_map(fn (array $r) => [
                'code' => (string) ($names[$r['account_id']]->code ?? ''),
                'name' => (string) ($names[$r['account_id']]?->name() ?? ''),
                'party' => isset($r['party_id']),
                'debit' => (string) ($r['debit'] ?? '0'),
                'credit' => (string) ($r['credit'] ?? '0'),
            ], $rows),
            // ⭐ "টাকা নিন" — পাকা SV-র পরে রসিদ (RCV), গ্রাহক আর অঙ্ক আগে থেকে ভরা (মালিকের এক-কাগজের নিয়ম)
            'collectUrl' => $voucher->isSale() && $voucher->isConfirmed()
                ? route('accounts.voucher.create', ['type' => 'receipt', 'party_type' => 'customer', 'party_id' => $voucher->party_id,
                    'amount' => bcadd((string) $voucher->party_total, '0', 2), 'narration' => $voucher->document_no])
                : null,
            'listUrl' => route('accounts.trade_voucher.index', ['kind' => $kind]),
            'binMissing' => NoteController::companyBinMissing(),
        ]);
    }

    public function confirm(string $kind, TradeVoucher $voucher): RedirectResponse
    {
        abort_unless($voucher->kind === $kind, 404);

        $voucher = $this->vouchers->confirm($voucher);

        return back()->with('saved', $voucher->isDraft() ? __('accounts::note.awaiting_signature') : __('accounts::trade.posted'));
    }

    public function cancel(Request $request, string $kind, TradeVoucher $voucher): RedirectResponse
    {
        abort_unless($voucher->kind === $kind, 404);

        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        $this->vouchers->cancel($voucher, $data['cancel_reason']);

        return back()->with('saved', __('accounts::trade.cancelled'));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function creditDays(string $partyType, array $ids): array
    {
        $table = $partyType === 'customer' ? 'customers' : 'suppliers';

        if ($ids === [] || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table($table)->whereIn('id', $ids)->pluck('credit_days', 'id')
            ->map(fn ($d) => (int) ($d ?? 0))->all();
    }

    private function accountLabel(int $id): string
    {
        $account = Account::query()->find($id);

        return $account === null ? '' : $account->code.' · '.$account->name();
    }
}
