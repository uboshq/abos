<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesResponsibility;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ পুরনো বিল আর ফেরতে দায়িত্বের মানুষ বসানো — মালিক, ২৩ নভেম্বর ২০২৬ ([[SalesResponsibility]])।
 *
 * ⓘ `--apply` ছাড়া কেবল দেখায় — মানুষ ধরে বিল আর ফেরতের সংখ্যা আর টাকা (কেবল পাকা কাগজ), আর "নেই" যাঁদের কেউ মেলেনি।
 *   দেখানোটা আসলেই হিসাব করে, এক লেনদেনে, তারপর পুরোটা ফিরিয়ে নেয় — তাই যা দেখায় `--apply` ঠিক তাই বসায়।
 * ⓘ কেবল ফাঁকা ঘর ভরে; `--recompute` দিলে বসানোগুলোও আবার মেলায় (যেখানে উত্তর বদলায় সেখানেই লেখে)। আবার চালালে কিছু বদলায় না।
 * ⓘ `--places-from=YYYY-MM-DD` — এখনো চালু এলাকার বাঁধন যেগুলো ঐ দিনের **পরে** শুরু, তাদের শুরু ঐ দিনে টানে (মালিক:
 *   *"ইতিহাসও আজকের ম্যাপ ধরে"*)। শেষ হয়ে যাওয়া বাঁধন ছোঁয়া হয় না — ওগুলো ইতিহাস।
 * ⛔ মোট, খাতার সারি (`ledger_entries`), কাগজের নম্বর, অবস্থা — কিছুই ছোঁয় না; কেবল `sales_person_id`।
 *   খসড়া ছোঁয় না: খসড়া পাকা হওয়ার মুহূর্তে নিজের মানুষ পায় ([[SalesInvoiceService::confirm()]])।
 * ⭐ `--apply` হলে কোম্পানি-প্রতি নিরীক্ষার খাতায় একটা সারি — কয়টা বিল, কয়টা ফেরত, কোন বাঁধন সরল।
 */
final class AssignSalesResponsible extends Command
{
    protected $signature = 'sales:assign-responsible
        {--company= : কেবল এই কোম্পানির কোড (যেমন ADI) — না দিলে সব কোম্পানি}
        {--apply : সত্যিই বসায় — না দিলে কেবল দেখায়}
        {--recompute : আগে বসানোগুলোও আবার মেলায়}
        {--places-from= : এখনো চালু এলাকার বাঁধনের শুরু এই দিনে টানে (YYYY-MM-DD)}';

    protected $description = 'Fills sales_person_id on old sales invoices and returns from the person responsible for the dealer (dealer binding, area, company default); dry run unless --apply';

    /** ⓘ পাকা হয়েছে এমন কাগজই — খসড়া পাকা হওয়ার সময় নিজে পায় */
    private const TOUCHED = [DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED, DocumentStatus::CLOSED];

    public function handle(AuditEngine $audit): int
    {
        $apply = (bool) $this->option('apply');
        $recompute = (bool) $this->option('recompute');
        $placesFrom = $this->option('places-from');

        if ($placesFrom !== null && $placesFrom !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $placesFrom);
            if ($parsed === false || $parsed->format('Y-m-d') !== (string) $placesFrom) {
                $this->error('  --places-from তারিখ YYYY-MM-DD আকারে দিন');

                return self::FAILURE;
            }
            $placesFrom = $parsed->format('Y-m-d');
        } else {
            $placesFrom = null;
        }

        $companies = Company::query()->orderBy('id')
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        if ($companies->isEmpty()) {
            $this->error('  এই কোডে কোনো কোম্পানি নেই');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            CompanyContext::forCompany((int) $company->id, function () use ($company, $apply, $recompute, $placesFrom, $audit): void {
                $this->runFor($company, $apply, $recompute, $placesFrom, $audit);
            });
        }

        if (! $apply) {
            $this->line('  কিছুই লেখা হয়নি — --apply দিলে উপরের সবটা বসবে');
        }

        return self::SUCCESS;
    }

    private function runFor(Company $company, bool $apply, bool $recompute, ?string $placesFrom, AuditEngine $audit): void
    {
        $companyId = (int) $company->id;
        $resolver = app(SalesResponsibility::class);
        $resolver->forget();

        DB::beginTransaction();

        try {
            $moved = $placesFrom !== null ? $this->movePlaces($companyId, $placesFrom) : [];
            $resolver->forget();

            $invoices = $this->fillInvoices($companyId, $recompute, $resolver);
            $returns = $this->fillReturns($companyId, $recompute, $resolver);

            $this->report($company, $moved, $invoices, $returns, $placesFrom);

            if (! $apply) {
                DB::rollBack();
                $resolver->forget();

                return;
            }

            $audit->recordAction($company, 'sales_responsible_assigned', sprintf(
                'console: sales:assign-responsible%s — %d invoices, %d returns; area bindings moved to %s: %s',
                $recompute ? ' --recompute' : '',
                $invoices,
                $returns,
                $placesFrom ?? '—',
                $moved === [] ? 'none' : implode(',', array_column($moved, 'id')),
            ));

            DB::commit();
            $resolver->forget();
            $this->info("  {$company->code}: বসল — {$invoices}টা বিল, {$returns}টা ফেরত".($moved === [] ? '' : ', '.count($moved).'টা এলাকার বাঁধন সরল'));
        } catch (\Throwable $e) {
            DB::rollBack();
            $resolver->forget();

            throw $e;
        }
    }

    /**
     * ⓘ এখনো চালু (শেষ নেই, বা আজ/পরে শেষ) আর ঐ দিনের পরে শুরু — শুরু ঐ দিনে।
     *
     * @return list<array{id: int, user: string, place: string, from: string}>
     */
    private function movePlaces(int $companyId, string $placesFrom): array
    {
        $today = Carbon::today()->toDateString();

        $rows = DB::table('area_bindings as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('mdm_locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.company_id', $companyId)
            ->where('b.starts_on', '>', $placesFrom)
            ->where(fn ($q) => $q->whereNull('b.ends_on')->orWhere('b.ends_on', '>=', $today))
            ->orderBy('b.id')
            ->get(['b.id', 'b.starts_on', 'u.name as user', 'l.code as place']);

        if ($rows->isNotEmpty()) {
            DB::table('area_bindings')->whereIn('id', $rows->pluck('id'))->update(['starts_on' => $placesFrom]);
        }

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'user' => (string) ($r->user ?? '#'.$r->id),
            'place' => (string) ($r->place ?? '—'),
            'from' => substr((string) $r->starts_on, 0, 10),
        ])->all();
    }

    /** কয়টা বিলের ঘর বদলাল। ⓘ দিন ধরে দল — এক দিনের সব ডিলার এক ডাকে ([[SalesResponsibility::forMany()]])। */
    private function fillInvoices(int $companyId, bool $recompute, SalesResponsibility $resolver): int
    {
        $rows = DB::table('sal_invoices')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', self::TOUCHED)
            ->when(! $recompute, fn ($q) => $q->whereNull('sales_person_id'))
            ->orderBy('id')
            ->get(['id', 'customer_id', 'trx_date', 'sales_person_id']);

        $changes = [];

        foreach ($rows->groupBy(fn ($r) => substr((string) $r->trx_date, 0, 10)) as $day => $group) {
            $people = $resolver->forMany($group->pluck('customer_id')->map(fn ($c) => (int) $c)->all(), (string) $day);

            foreach ($group as $r) {
                $new = $people[(int) $r->customer_id] ?? null;
                if ($new !== ($r->sales_person_id === null ? null : (int) $r->sales_person_id)) {
                    $changes[$new ?? 0][] = (int) $r->id;
                }
            }
        }

        return $this->write('sal_invoices', $companyId, $changes);
    }

    /** কয়টা ফেরতের ঘর বদলাল — মূল বিলের মানুষ আগে ([[SalesResponsibility::forReturn()]]), বিলগুলো ভরার পরে। */
    private function fillReturns(int $companyId, bool $recompute, SalesResponsibility $resolver): int
    {
        $changes = [];

        SalesReturn::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', self::TOUCHED)
            ->when(! $recompute, fn ($q) => $q->whereNull('sales_person_id'))
            ->orderBy('id')
            ->select(['id', 'company_id', 'customer_id', 'sales_invoice_id', 'trx_date', 'sales_person_id'])
            ->chunkById(500, function ($chunk) use ($resolver, &$changes): void {
                foreach ($chunk as $r) {
                    $new = $resolver->forReturn($r);
                    if ($new !== ($r->sales_person_id === null ? null : (int) $r->sales_person_id)) {
                        $changes[$new ?? 0][] = (int) $r->id;
                    }
                }
            });

        return $this->write('sal_returns', $companyId, $changes);
    }

    /**
     * ⛔ কেবল `sales_person_id` — `updated_at`ও নয়; মডেলের ঘটনা চলে না, তাই মোট, খাতা, নম্বর কিছুই নড়ে না।
     *
     * @param  array<int, list<int>>  $changes  মানুষ (০ = কেউ নন) → সারি
     */
    private function write(string $table, int $companyId, array $changes): int
    {
        $count = 0;

        foreach ($changes as $person => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table($table)
                    ->where('company_id', $companyId)
                    ->whereIn('id', $chunk)
                    ->update(['sales_person_id' => $person === 0 ? null : $person]);
            }
        }

        return $count;
    }

    /** @param list<array{id: int, user: string, place: string, from: string}> $moved */
    private function report(Company $company, array $moved, int $invoices, int $returns, ?string $placesFrom): void
    {
        $code = $company->code;

        foreach ($moved as $m) {
            $this->line("  {$code}: এলাকার বাঁধন #{$m['id']} {$m['user']} @ {$m['place']} — শুরু {$m['from']} → {$placesFrom}");
        }

        $sales = DB::table('sal_invoices')
            ->where('company_id', $company->id)->whereNull('deleted_at')->whereIn('status', DocumentStatus::POSTED)
            ->groupBy('sales_person_id')
            ->selectRaw('sales_person_id as p, COUNT(*) as n, COALESCE(SUM(total), 0) as amt')
            ->get()->keyBy(fn ($r) => (int) ($r->p ?? 0));

        $back = DB::table('sal_returns')
            ->where('company_id', $company->id)->whereNull('deleted_at')->whereIn('status', DocumentStatus::POSTED)
            ->groupBy('sales_person_id')
            ->selectRaw('sales_person_id as p, COUNT(*) as n, COALESCE(SUM(total), 0) as amt')
            ->get()->keyBy(fn ($r) => (int) ($r->p ?? 0));

        $ids = $sales->keys()->merge($back->keys())->unique()->sort()->values();
        $names = DB::table('users')->whereIn('id', $ids->filter()->all())->pluck('name', 'id');

        $rows = $ids->map(function (int $id) use ($sales, $back, $names) {
            $s = $sales->get($id);
            $b = $back->get($id);
            $sAmt = (string) ($s->amt ?? '0');
            $bAmt = (string) ($b->amt ?? '0');

            return [
                $id === 0 ? 'নেই' : (string) ($names[$id] ?? '#'.$id),
                (int) ($s->n ?? 0),
                number_format((float) $sAmt, 2),
                (int) ($b->n ?? 0),
                number_format((float) $bAmt, 2),
                number_format((float) bcsub($sAmt, $bAmt, 4), 2),
            ];
        })->all();

        $this->line("  {$code}: {$invoices}টা বিল আর {$returns}টা ফেরতের ঘর বদলাবে (পাকা কাগজের ছবি নিচে, বদলের পরে)");
        $this->table(['বিক্রয়কর্মী', 'বিল', 'বিলের টাকা', 'ফেরত', 'ফেরতের টাকা', 'নিট'], $rows);
    }
}
