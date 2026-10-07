<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\MasterData\Models\OpportunityStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সুযোগ — লেখা, ধাপ বদলানো, আর পাইপলাইনের যোগফল।
 */
final class OpportunityService
{
    /** পাইপলাইনে প্রতিটা ধাপের নিচে কয়টা সারি — বাকিগুলো তালিকায়, ছাঁকনিসহ। */
    public const PIPELINE_ROWS_PER_STAGE = 10;

    /** সাধারণ দশমিক, চার ঘর পর্যন্ত — bcmath যা নেয় কেবল তা-ই। */
    private const DECIMAL = '/^-?\d{1,14}(\.\d{1,4})?$/';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly MasterListService $lists,
    ) {}

    public function visibleTo(User $user): Builder
    {
        return Opportunity::query()->visibleTo($user);
    }

    /** না দেখতে পারলে ৪০৪ — [[LeadService::find()]]-এর একই কারণে। */
    public function find(int $id, User $user): Opportunity
    {
        return $this->visibleTo($user)->findOrFail($id);
    }

    /**
     * ⭐ শুরুর ধাপগুলো — কোম্পানির তালিকা একেবারে ফাঁকা হলে।
     *
     * ⓘ ধাপ ছাড়া সুযোগ লেখাই যায় না, আর পুরনো কোম্পানিগুলো এই তালিকা
     * কোনোদিন পায়নি। ⚠️ তাই প্রথমবার সুযোগের ফর্ম খুললে পাঁচটা বসে —
     * [[MasterListService::create()]] দিয়ে, যাতে কোড ও নকল-পাহারা মাস্টার
     * তালিকার একই দরজা পেরোয়। ⓘ মুছে ফেলা সারিও গোনা হয়: কোম্পানি
     * ইচ্ছা করে সব সরিয়ে থাকলে আবার ফিরিয়ে আনা ঠিক নয়।
     */
    public function ensureStages(): void
    {
        if (OpportunityStage::query()->withTrashed()->exists()) {
            return;
        }

        $rows = [
            ['PROS', 'Prospecting', 'খোঁজ', 10, 10, false, false],
            ['PROP', 'Proposal', 'প্রস্তাব', 40, 20, false, false],
            ['NEGO', 'Negotiation', 'দরকষাকষি', 70, 30, false, false],
            ['WON', 'Won', 'জিতেছি', 100, 40, true, false],
            ['LOST', 'Lost', 'হেরেছি', 0, 50, false, true],
        ];

        foreach ($rows as [$code, $en, $bn, $probability, $order, $won, $lost]) {
            $this->lists->create(OpportunityStage::class, [
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                'probability' => $probability,
                'sort_order' => $order,
                'is_won' => $won,
                'is_lost' => $lost,
            ], 'opportunity-stages');
        }
    }

    /** @return Collection<int, OpportunityStage> */
    public function stages(): Collection
    {
        return OpportunityStage::query()->active()->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * কাকে বিক্রয়কর্মী বানানো যায়।
     *
     * @return Collection<int, User>
     */
    public function salespeople(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('sales.opportunity.view'))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: int|string, qty: string, value: string}>  $lines
     */
    public function create(array $data, array $lines, User $actor): Opportunity
    {
        $header = $this->header($data, $lines, $actor, null);

        return DB::transaction(function () use ($header, $lines, $actor) {
            $opportunity = Opportunity::create([
                ...$header,
                'document_no' => $this->numbers->next('OPP'),
                'created_by' => $actor->id,
            ]);

            $this->writeLines($opportunity, $lines);

            return $opportunity->fresh(['lines', 'stage']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: int|string, qty: string, value: string}>  $lines
     */
    public function update(Opportunity $opportunity, array $data, array $lines, User $actor): Opportunity
    {
        // ⛔ কোটেশন হয়ে গেলে সুযোগটা ইতিহাস — অঙ্ক বদলালে কোটেশনের সাথে অমিল
        if ($opportunity->sales_quotation_id !== null) {
            throw ValidationException::withMessages([
                'stage_id' => __('sales::crm.locked_by_quotation'),
            ]);
        }

        $header = $this->header($data, $lines, $actor, $opportunity);

        return DB::transaction(function () use ($opportunity, $header, $lines) {
            $opportunity->update($header);

            $opportunity->lines()->delete();
            $this->writeLines($opportunity, $lines);

            return $opportunity->fresh(['lines', 'stage']);
        });
    }

    /**
     * কোটেশন তৈরি হলে তার id এখানে বসে — কোটেশনের সার্ভিস ডাকবে।
     *
     * ⛔ কেবল জেতা সুযোগে, আর একবারই।
     */
    public function linkQuotation(Opportunity $opportunity, int $quotationId): Opportunity
    {
        if (! $opportunity->isWon() || $opportunity->sales_quotation_id !== null) {
            throw ValidationException::withMessages([
                'sales_quotation_id' => __('sales::crm.quotation_needs_won'),
            ]);
        }

        $opportunity->forceFill(['sales_quotation_id' => $quotationId])->save();

        return $opportunity->fresh();
    }

    /**
     * ⭐ পাইপলাইন — ধাপ ধরে গোনা, মোট অঙ্ক আর ওজন-করা অঙ্ক।
     *
     * ── ⚠️ কেন যোগটা ডাটাবেজে ────────────────────────────────────────
     * PHP-তে যোগ করতে সব সারি টানতে হত, আর ছয় মাস পরে পাতাটা ভারী হত।
     * ⓘ কলামগুলো DECIMAL, তাই MySQL-এর গুণ-ভাগ দশমিকেই চলে — float নয়।
     * ⓘ দেখার দেয়াল ([[Opportunity::scopeVisibleTo()]]) যোগের আগেই বসে:
     * বিক্রয়কর্মী কেবল নিজের অঙ্ক দেখেন, অন্যেরটা যোগে লুকিয়েও নয়।
     *
     * @return list<array{stage: OpportunityStage, count: int, total: string, weighted: string, rows: Collection<int, Opportunity>}>
     */
    public function pipeline(User $user): array
    {
        $sums = $this->visibleTo($user)
            ->selectRaw('stage_id, COUNT(*) as n, SUM(estimated_value) as total, '
                .'SUM(estimated_value * probability / 100) as weighted')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        $board = [];

        foreach ($this->stagesForBoard($sums->keys()->all()) as $stage) {
            $sum = $sums->get($stage->id);

            $board[] = [
                'stage' => $stage,
                'count' => (int) ($sum->n ?? 0),
                'total' => $this->money($sum->total ?? '0'),
                'weighted' => $this->money($sum->weighted ?? '0'),
                'rows' => $this->visibleTo($user)
                    ->with(['customer', 'lead', 'salesperson'])
                    ->where('stage_id', $stage->id)
                    ->orderByRaw('expected_close_date IS NULL')
                    ->orderBy('expected_close_date')
                    ->limit(self::PIPELINE_ROWS_PER_STAGE)
                    ->get(),
            ];
        }

        return $board;
    }

    /**
     * চলমান ধাপগুলোর ওজন-করা যোগ — পাইপলাইনের মাথার সংখ্যা।
     *
     * ⓘ জেতা ও হারা বাদ: জেতাটা আর "সম্ভাবনা" নয়, আর হারাটা শূন্য।
     *
     * @param  list<array{stage: OpportunityStage, weighted: string}>  $board
     */
    public function openWeighted(array $board): string
    {
        $sum = '0';

        foreach ($board as $column) {
            if ($column['stage']->isOpen()) {
                $sum = bcadd($sum, $column['weighted'], 4);
            }
        }

        return $sum;
    }

    /**
     * সক্রিয় ধাপ, আর বন্ধ ধাপ যেগুলোয় এখনো সুযোগ বসে আছে।
     *
     * ⚠️ বন্ধ ধাপ বাদ দিলে ওর সুযোগগুলো পাইপলাইন থেকে নীরবে হারাত।
     *
     * @param  list<int|string>  $used
     * @return Collection<int, OpportunityStage>
     */
    private function stagesForBoard(array $used): Collection
    {
        return OpportunityStage::query()
            ->withTrashed()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('is_active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', $used))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function header(array $data, array $lines, User $actor, ?Opportunity $existing): array
    {
        [$customerId, $leadId] = $this->party($data, $actor, $existing);

        $stage = OpportunityStage::query()->active()->find($data['stage_id'] ?? null);

        // ⓘ বন্ধ ধাপে থাকা পুরনো সুযোগ ঐ ধাপেই থাকতে পারে, নতুন কেউ যেতে পারে না
        if ($stage === null && $existing !== null && (int) ($data['stage_id'] ?? 0) === $existing->stage_id) {
            $stage = $existing->stage;
        }

        if ($stage === null) {
            throw ValidationException::withMessages(['stage_id' => __('sales::crm.stage_required')]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::crm.lines_required')]);
        }

        /*
         * ⛔ পণ্য এই কোম্পানির — কন্ট্রোলারের যাচাই থাকলেও এখানে আবার।
         *
         * ⚠️ সার্ভিস অন্য পথেও ডাকা যায় (আমদানি, API); FK কেবল বলে সারিটা
         * কোথাও আছে, কোন কোম্পানির তা নয়। অন্য কোম্পানির পণ্যের id বসলে
         * সুযোগের পাতায় তার নাম ফাঁস হত।
         */
        $productIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_id'], $lines)));

        if (Product::query()->whereKey($productIds)->count() !== count($productIds)) {
            throw ValidationException::withMessages(['lines' => __('sales::crm.product_not_found')]);
        }

        foreach ($lines as $line) {
            // ⛔ ঋণাত্মক অঙ্ক যোগফল কমিয়ে পাইপলাইন মিথ্যা বলাত
            // ⓘ আগে আকার দেখা — "1e5" is_numeric, কিন্তু bcmath-এ ValueError (৫০০)
            $qty = (string) $line['qty'];
            $value = (string) $line['value'];

            if (! preg_match(self::DECIMAL, $qty) || ! preg_match(self::DECIMAL, $value)
                || bccomp($qty, '0', 4) <= 0 || bccomp($value, '0', 4) < 0) {
                throw ValidationException::withMessages(['lines' => __('sales::crm.line_amount_invalid')]);
            }
        }

        $total = '0';

        foreach ($lines as $line) {
            $total = bcadd($total, (string) $line['value'], 4);
        }

        /*
         * ⓘ সম্ভাবনা: হাতে দিলে সেটাই; না দিলে — অথবা ধাপ বদলালে আর
         * সংখ্যাটা ছোঁয়া হয়নি — ধাপেরটা।
         */
        $stageChanged = $existing !== null && $existing->stage_id !== $stage->id;
        $given = $data['probability'] ?? null;
        $untouched = $existing !== null && (string) $given === (string) $existing->probability;

        $probability = (blank($given) || ($stageChanged && $untouched))
            ? $stage->probability
            : (int) $given;

        $closed = ! $stage->isOpen();

        return [
            'title' => $data['title'],
            'customer_id' => $customerId,
            'lead_id' => $leadId,
            'salesperson_user_id' => $this->salespersonFor($data['salesperson_user_id'] ?? null, $actor, $existing),
            'stage_id' => $stage->id,
            'estimated_value' => $total,
            'probability' => $probability,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'competitor' => $data['competitor'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'closed_at' => $closed ? ($existing?->closed_at ?? now()) : null,
        ];
    }

    /**
     * গ্রাহক অথবা লিড — ঠিক একটা।
     *
     * ⛔ লিড বাছতে গেলে সেটা এই মানুষের দেখার ভিতরে থাকতে হবে — নাহলে
     * অন্যের লিডের id পাঠিয়ে তার নামে সুযোগ বসানো যেত, আর তার খবর
     * সুযোগের পাতা দিয়ে বেরিয়ে যেত।
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: ?int}
     */
    private function party(array $data, User $actor, ?Opportunity $existing): array
    {
        $customerId = filled($data['customer_id'] ?? null) ? (int) $data['customer_id'] : null;
        $leadId = filled($data['lead_id'] ?? null) ? (int) $data['lead_id'] : null;

        if (($customerId === null) === ($leadId === null)) {
            throw ValidationException::withMessages(['customer_id' => __('sales::crm.party_exactly_one')]);
        }

        if ($customerId !== null) {
            if (! Customer::query()->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => __('sales::crm.party_not_found')]);
            }

            /*
             * ⓘ লিড থেকে গ্রাহক হওয়া সুযোগ: ফর্ম কেবল গ্রাহক পাঠায়, কিন্তু
             * লিডের সংযোগটা ইতিহাস — একই গ্রাহক থাকলে সেটা মুছে যায় না।
             */
            $keptLead = $existing?->lead_id !== null
                && (int) Lead::query()->whereKey($existing->lead_id)->value('customer_id') === $customerId
                    ? $existing->lead_id
                    : null;

            return [$customerId, $keptLead];
        }

        // পুরনো সুযোগের নিজের লিড থাকুক, যদিও লিডটা পরে অন্যের হাতে গেছে
        $lead = ($existing !== null && $existing->lead_id === $leadId)
            ? Lead::query()->find($leadId)
            : Lead::query()->visibleTo($actor)->find($leadId);

        if ($lead === null) {
            throw ValidationException::withMessages(['lead_id' => __('sales::crm.party_not_found')]);
        }

        if ($lead->status === Lead::LOST && $existing?->lead_id !== $lead->id) {
            throw ValidationException::withMessages(['lead_id' => __('sales::crm.lead_is_lost')]);
        }

        // লিড আগেই গ্রাহক হয়ে থাকলে সুযোগটা গ্রাহকের — লিডটা ইতিহাস হিসেবে
        return [$lead->customer_id, $lead->id];
    }

    /** ⛔ [[LeadService]]-এর মালিকের নিয়মই — সবার চাবি ছাড়া কেবল নিজের নামে। */
    private function salespersonFor(mixed $requested, User $actor, ?Opportunity $existing): ?int
    {
        if (! $actor->can('sales.opportunity.manage')) {
            return $existing?->salesperson_user_id ?? $actor->id;
        }

        if (blank($requested)) {
            return $existing?->salesperson_user_id ?? $actor->id;
        }

        $member = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereKey((int) $requested)
            ->exists();

        if (! $member) {
            throw ValidationException::withMessages([
                'salesperson_user_id' => __('sales::crm.owner_not_member'),
            ]);
        }

        return (int) $requested;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Opportunity $opportunity, array $lines): void
    {
        foreach ($lines as $line) {
            $opportunity->lines()->create([
                'company_id' => $opportunity->company_id,
                'product_id' => (int) $line['product_id'],
                'qty' => (string) $line['qty'],
                'value' => (string) $line['value'],
            ]);
        }
    }

    /** ডাটাবেজের দশমিক — ভাগের পরে বেশি ঘর আসে, চার ঘরে নামানো। */
    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
}
