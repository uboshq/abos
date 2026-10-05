<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DO বিক্রয় আদেশে মেশানোর সুইচ — এক কোম্পানির জন্য দেখা, প্রবাহ কপি, চালু/বন্ধ (SO+DO নকশা, ধাপ ১৩; ৫ অক্টোবর ২০২৬)।
 *
 *   php artisan abos:orders-replace-do TDEPOT                 — কেবল দেখায়, কিছু লেখে না
 *   php artisan abos:orders-replace-do TDEPOT --copy-flow     — DO-র অনুমোদন-প্রবাহ "order"-এ কপি (আগে থেকে থাকলে ছোঁয় না)
 *   php artisan abos:orders-replace-do TDEPOT --on            — সুইচ চালু (প্রবাহ না থাকলে থামে)
 *   php artisan abos:orders-replace-do TDEPOT --off           — সুইচ বন্ধ
 *
 * ── ⛔ কেন চালুর আগে প্রবাহ ─────────────────────────────────────────────
 * সুইচ চালু হলে "নিশ্চিত" মানে জমা ([[SalesOrderService::submit()]]), আর সই চাওয়া হয় কোম্পানির একটাই `order` প্রবাহে
 * (উত্তর ৫)। ⓘ DO-তে সই চলত `delivery_order` প্রবাহে; সেটা কপি না করে চালু করলে যে কোম্পানি আজ DO-তে সুপারভাইজারের
 * সই চায়, সে আদেশে সই ছাড়াই "অনুমোদিত" পেত। তাই DO-র প্রবাহ আছে অথচ আদেশের নেই — তখন চালু থামে।
 *
 * ⓘ খোলা DO গুলো চালুর পরেও নিজের পথে শেষ হয় (নকশা ধাপ ১৫) — তালিকাটা কেবল জানানোর জন্য, থামানোর জন্য নয়।
 */
class OrdersReplaceDo extends Command
{
    protected $signature = 'abos:orders-replace-do {company : কোম্পানির কোড} {--copy-flow} {--on} {--off}';

    protected $description = 'DO বিক্রয় আদেশে মেশানোর সুইচ: অবস্থা দেখা, অনুমোদন-প্রবাহ কপি, চালু বা বন্ধ';

    /** যেসব DO আর চলমান নয় */
    private const FINISHED = [DeliveryOrderStatus::INVOICED, DeliveryOrderStatus::REJECTED, DeliveryOrderStatus::CANCELLED];

    public function handle(SettingsService $settings): int
    {
        $company = Company::query()->where('code', (string) $this->argument('company'))->first();

        if ($company === null) {
            $this->error('এই কোডে কোনো কোম্পানি নেই: '.$this->argument('company'));

            return self::FAILURE;
        }

        if ($this->option('on') && $this->option('off')) {
            $this->error('--on আর --off একসাথে নয়।');

            return self::FAILURE;
        }

        return CompanyContext::forCompany($company->id, function () use ($company, $settings): int {
            if ($this->option('copy-flow')) {
                $this->copyFlow();
            }

            $doFlows = $this->flows('delivery_order');
            $orderFlows = $this->flows(SalesOrderService::APPROVAL_ACTION);

            if ($this->option('on')) {
                if ($doFlows > 0 && $orderFlows === 0) {
                    $this->error("⛔ {$company->code}: DO-র অনুমোদন-প্রবাহ আছে, আদেশের নেই — আগে --copy-flow চালান, নইলে আদেশ সই ছাড়াই অনুমোদিত হত।");

                    return self::FAILURE;
                }

                $settings->set(SalesOrderService::REPLACES_DO, true);
                $this->info("{$company->code}: সুইচ চালু — এখন থেকে \"নিশ্চিত\" মানে জমা, সই আর বাকির সীমা জমার সময়েই।");
            }

            if ($this->option('off')) {
                $settings->set(SalesOrderService::REPLACES_DO, false);
                $this->info("{$company->code}: সুইচ বন্ধ — আগের ধারা।");
            }

            $this->report($company, $settings, $doFlows, $orderFlows);

            return self::SUCCESS;
        });
    }

    private function flows(string $action): int
    {
        return ApprovalFlow::query()->where('module', 'sales')->where('action', $action)->where('is_active', true)->count();
    }

    /**
     * DO-র প্রতিটা চালু প্রবাহ "order"-এ — ধাপ আর শর্তসহ, এক লেনদেনে। ⓘ আদেশের প্রবাহ আগে থেকে থাকলে কিছুই নয়:
     * আজকের আদেশেও ঐ প্রবাহ খাটে, আর কোম্পানির নিজের বসানো নিয়ম মুছে দেওয়া যায় না।
     */
    private function copyFlow(): void
    {
        if ($this->flows(SalesOrderService::APPROVAL_ACTION) > 0) {
            $this->line('আদেশের অনুমোদন-প্রবাহ আগে থেকেই আছে — কপি করা হয়নি।');

            return;
        }

        $sources = ApprovalFlow::query()->where('module', 'sales')->where('action', 'delivery_order')
            ->where('is_active', true)->with(['steps', 'conditions'])->get();

        if ($sources->isEmpty()) {
            $this->line('DO-র কোনো অনুমোদন-প্রবাহ নেই — কপির কিছু নেই।');

            return;
        }

        DB::transaction(function () use ($sources): void {
            foreach ($sources as $source) {
                $copy = ApprovalFlow::query()->create([
                    'company_id' => $source->company_id,
                    'module' => 'sales',
                    'action' => SalesOrderService::APPROVAL_ACTION,
                    'document_type' => $source->document_type === class_basename(DeliveryOrder::class) ? null : $source->document_type,
                    'threshold_amount' => $source->threshold_amount,
                    'remarks' => trim('DO-র প্রবাহ '.$source->code.' থেকে কপি (abos:orders-replace-do). '.(string) $source->remarks),
                    'is_active' => true,
                ]);

                foreach ($source->steps as $step) {
                    $copy->steps()->create($step->only($step->getFillable()) + ['approval_flow_id' => $copy->id]);
                }

                foreach ($source->conditions as $condition) {
                    $copy->conditions()->create(
                        $condition->only($condition->getFillable()) + ['approval_flow_id' => $copy->id, 'company_id' => $copy->company_id]);
                }

                $this->info("প্রবাহ {$source->code} → {$copy->code} (আদেশ): {$source->steps->count()}টা ধাপ, {$source->conditions->count()}টা শর্ত।");
            }
        });
    }

    private function report(Company $company, SettingsService $settings, int $doFlows, int $orderFlows): void
    {
        $open = DeliveryOrder::query()->whereNotIn('status', self::FINISHED)->orderBy('id');

        $this->line('');
        $this->line("কোম্পানি: {$company->code}");
        $this->line('মেশানোর সুইচ: '.((bool) $settings->get(SalesOrderService::REPLACES_DO, false) ? 'চালু' : 'বন্ধ'));
        $this->line('বাকির সীমার সুইচ: '.((bool) $settings->get('customer.credit_limit_enabled', true) ? 'চালু' : 'বন্ধ — জমার সময় সীমা দেখা হবে না'));
        $this->line("অনুমোদন-প্রবাহ: DO {$doFlows}টা, আদেশ {$orderFlows}টা");
        $this->line('খোলা DO: '.(clone $open)->count().'টা (চালুর পরেও নিজের পথে শেষ হবে)');

        foreach ((clone $open)->limit(20)->get(['document_no', 'status']) as $do) {
            $this->line("  · {$do->document_no} — {$do->status}");
        }
    }
}
