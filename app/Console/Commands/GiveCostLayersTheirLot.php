<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * পুরনো খরচের স্তরে লট বসানো — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (এককালীন, সমন্বয়কের ডিপ্লয়ে)।
 *
 * ── কেন ──────────────────────────────────────────────────────────────
 * স্তর আজ থেকে লট চেনে ([[CostLayerService::receive()]])। কিন্তু আগের স্তরে ঘরটা খালি, আর লট বাছা বিক্রি তখন
 * তার নিজের স্তর পায় না — FIFO-তে পড়ে (চিহ্নসহ, নীরবে নয়)। এই কমান্ড যেখানে **নিশ্চিতভাবে** জানা যায় সেখানে
 * লট বসায়।
 *
 * ── কীভাবে জানা যায় ─────────────────────────────────────────────────────
 * স্তরটা যে কাগজে জন্মেছিল, সেই কাগজের একই পণ্যের **আগমনের** চলাচল খোঁজা হয় (তাকে, না-বসানো ঘরে বা আটকে):
 *   উৎস মেলে দুই রকমে — চলাচলের উৎস = স্তরের উৎস (মাল-গ্রহণ, বিল, রান্না), অথবা স্তরের উৎস-আইডি = চলাচলের
 *   নিজের আইডি (খোলা মজুদ, সমন্বয়ের উদ্বৃত্ত)।
 *   ঠিক একটা লট পাওয়া গেলে → সেই লট।
 *   কয়েকটা লট → পরিমাণ হুবহু মেলে এমন সারি ঠিক একটা হলে সেটা; নাহলে **দ্ব্যর্থ, ছোঁয়া হয় না**।
 *
 * ── ⛔ যা করে না ───────────────────────────────────────────────────────
 * কোনো দাখিলা, কোনো দর, কোনো `qty_remaining` বদলায় না — কেবল `batch_id`। খাতা আগের মতোই সিল করা।
 * ⚠️ অনেক লটের স্তরে বাকি পরিমাণ আর তাকের মাল আলাদা (অতীতের বিক্রি FIFO-তে অন্য লটের স্তর খেয়েছে)। তাই শেষে
 * লট ধরে দুইটা পাশাপাশি ছাপা হয় — ফারাক মেলানো আলাদা প্রস্তাব, সমন্বয়কের অনুমতিতে।
 *
 * ⓘ `--force` ছাড়া কিছুই লেখে না: পুরো কাজ লেনদেনে চলে আর শেষে ফেরানো হয়, তাই শুকনো চালানোর সংখ্যাগুলো আসল
 * চালানোর হুবহু।
 */
class GiveCostLayersTheirLot extends Command
{
    protected $signature = 'abos:cost-layer-lots
                            {--company= : কেবল এই কোম্পানির (id)}
                            {--show=20 : লট-ধরে ফারাকের কয়টা সারি ছাপা হবে}
                            {--force : সত্যিই লেখে; নাহলে কেবল দেখায়}';

    protected $description = 'পুরনো খরচের স্তরে লট বসায় যেখানে নিশ্চিত, আর লট ধরে স্তর বনাম তাকের মাল দেখায় (এককালীন)';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $only = $this->option('company');

        $companies = Company::query()->orderBy('id')
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($apply, $company) {
                DB::beginTransaction();

                try {
                    $counts = $this->fill((int) $company->id);
                    $gaps = $this->gaps((int) $company->id);
                } catch (\Throwable $e) {
                    DB::rollBack();

                    throw $e;
                }

                $apply ? DB::commit() : DB::rollBack();

                $this->report((string) $company->code, $counts, $gaps, $apply);
            });
        }

        if (! $apply) {
            $this->warn('কিছুই বদলায়নি — সত্যিই লিখতে --force দিন (আগে ব্যাকআপ, আগে demo)।');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{filled: int, ambiguous: int, unmatched: int, lotless: int}
     */
    private function fill(int $companyId): array
    {
        $counts = ['filled' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'lotless' => 0];

        $layers = DB::table('inv_cost_layers as l')
            ->join('inv_products as p', 'p.id', '=', 'l.product_id')
            ->where('l.company_id', $companyId)
            ->whereNull('l.batch_id')
            ->orderBy('l.id')
            ->get(['l.id', 'l.product_id', 'l.source_type', 'l.source_id', 'l.qty_in', 'p.track_batch']);

        foreach ($layers as $layer) {
            if (! (bool) $layer->track_batch) {
                $counts['lotless']++;

                continue;
            }

            $arrivals = DB::table('inv_stock_movements')
                ->where('company_id', $companyId)
                ->where('product_id', $layer->product_id)
                ->where('source_type', $layer->source_type)
                ->where(fn ($q) => $q->where('source_id', $layer->source_id)->orWhere('id', $layer->source_id))
                ->whereNotNull('batch_id')
                ->whereRaw('(floor_change + unplaced_change + hold_change) > 0')
                ->get(['batch_id', DB::raw('(floor_change + unplaced_change + hold_change) as qty')]);

            $lot = $this->theOneLot($arrivals, (string) $layer->qty_in);

            if ($lot === null) {
                $counts[$arrivals->isEmpty() ? 'unmatched' : 'ambiguous']++;

                continue;
            }

            DB::table('inv_cost_layers')->where('id', $layer->id)->update(['batch_id' => $lot]);
            $counts['filled']++;
        }

        return $counts;
    }

    /** @param Collection<int, object{batch_id: int, qty: string}> $arrivals */
    private function theOneLot($arrivals, string $qtyIn): ?int
    {
        $lots = $arrivals->pluck('batch_id')->unique()->values();

        if ($lots->count() === 1) {
            return (int) $lots->first();
        }

        // ⓘ কয়েকটা লট — পরিমাণ হুবহু মেলে এমন সারি ঠিক একটা হলে তবেই
        $exact = $arrivals->filter(fn ($a) => bccomp((string) $a->qty, $qtyIn, 4) === 0)->pluck('batch_id')->unique();

        return $exact->count() === 1 ? (int) $exact->first() : null;
    }

    /**
     * লট ধরে: স্তরে বাকি বনাম তাকের মাল — কেবল যেখানে আলাদা, বড় ফারাক আগে।
     *
     * @return list<array{lot: string, product: string, layers: string, stock: string}>
     */
    private function gaps(int $companyId): array
    {
        $layers = DB::table('inv_cost_layers')
            ->where('company_id', $companyId)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(qty_remaining) as qty')
            ->pluck('qty', 'batch_id');

        $stock = DB::table('inv_stock_movements')
            ->where('company_id', $companyId)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(floor_change + unplaced_change + hold_change) as qty')
            ->pluck('qty', 'batch_id');

        $names = DB::table('inv_batches as b')
            ->join('inv_products as p', 'p.id', '=', 'b.product_id')
            ->where('b.company_id', $companyId)
            ->get(['b.id', 'b.batch_no', 'p.name_en'])
            ->keyBy('id');

        $gaps = [];

        foreach ($layers->keys()->merge($stock->keys())->unique() as $lot) {
            $inLayers = bcadd((string) ($layers[$lot] ?? '0'), '0', 4);
            $onShelf = bcadd((string) ($stock[$lot] ?? '0'), '0', 4);

            if (bccomp($inLayers, $onShelf, 4) !== 0) {
                $gaps[] = [
                    'lot' => (string) ($names[$lot]->batch_no ?? $lot),
                    'product' => (string) ($names[$lot]->name_en ?? ''),
                    'layers' => $inLayers,
                    'stock' => $onShelf,
                ];
            }
        }

        usort($gaps, fn ($a, $b) => bccomp(
            ltrim(bcsub($b['layers'], $b['stock'], 4), '-'),
            ltrim(bcsub($a['layers'], $a['stock'], 4), '-'),
            4,
        ));

        return $gaps;
    }

    /**
     * @param  array{filled: int, ambiguous: int, unmatched: int, lotless: int}  $counts
     * @param  list<array{lot: string, product: string, layers: string, stock: string}>  $gaps
     */
    private function report(string $code, array $counts, array $gaps, bool $applied): void
    {
        $this->line("── {$code}: লট ".($applied ? 'বসল ' : 'বসত ').$counts['filled']
            .' · দ্ব্যর্থ (ছোঁয়া হয়নি) '.$counts['ambiguous']
            .' · আগমন মেলেনি '.$counts['unmatched']
            .' · লটহীন পণ্যের স্তর '.$counts['lotless']);

        $this->line('   লট ধরে স্তরে বাকি ≠ তাকের মাল: '.count($gaps).' টা লট');

        /*
         * ⓘ সমন্বয়কের প্রশ্ন: কতটা বিক্রি FIFO-তে পড়বে? — যে লটের নিজের স্তরে তাকের চেয়ে কম, তার ঘাটতিটুকু বেচলে
         * লটের দাম মেলে না, FIFO-তে পড়ে (চিহ্ন আর ঘটনাসহ)। এটা তারই আগাম মাপ।
         */
        $short = array_filter($gaps, fn ($g) => bccomp($g['layers'], $g['stock'], 4) < 0);
        $shortQty = array_reduce($short, fn ($sum, $g) => bcadd($sum, bcsub($g['stock'], $g['layers'], 4), 4), '0');
        $this->line('   লটের স্তরে তাকের চেয়ে কম (বেচলে FIFO-তে পড়বে): '.count($short).' টা লট, মোট '.$shortQty.' একক');

        foreach (array_slice($gaps, 0, max(0, (int) $this->option('show'))) as $gap) {
            $this->line("   {$gap['lot']}  {$gap['product']}  স্তর {$gap['layers']}  তাক {$gap['stock']}");
        }
    }
}
