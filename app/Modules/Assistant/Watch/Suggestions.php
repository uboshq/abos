<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Watch;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * ⭐ কোপাইলটের প্রস্তাব — কী করা ভালো (পূর্ণ পরিকল্পনা অংশ ১, ধাপ ২)।
 *
 * ── ⛔ নিজে কিছু গোনে না ───────────────────────────────────────────────
 * আবার কেনা = মজুদ বোর্ডের পুনঃক্রয় তালিকা ([[InventoryBoard::reorder()]]); কাকে ফোন আর কার বাকির সীমা = বাকি নিয়ন্ত্রণের
 * ঝুঁকির উপকরণ ও মাত্রা ([[CreditRisk::factsFor()]], [[CreditRisk::rateAll()]]); মেয়াদের মাল = [[InventoryBoard::expiringLots()]]।
 * ⓘ প্রস্তাব শুধু কথা — কোপাইলট নিজে কিছু বদলায় না। ছাড়ের প্রস্তাব বিশেষ করে: মালিকের নিয়মে যেকোনো ছাড় মালিকের অনুমোদনে।
 * ⓘ প্রত্যেকে নিজের চাবির প্রস্তাবই পান; গ্রাহকের তালিকা গ্রাহকের দেয়াল মানে (SR কেবল নিজের ডিলার)।
 */
final class Suggestions
{
    /** ⓘ "কাকে ফোন" — বাকি পেরোনো সবচেয়ে বড় এই কয়জন */
    public const CALL_LIST = 5;

    /** ⓘ এতদিনের মধ্যে মেয়াদ শেষ হলে ছাড়ের প্রস্তাব */
    public const EXPIRY_DAYS = 30;

    /**
     * @return list<array{id: string, module: string, title: string, detail: string, rows: list<array{label: string, value: string, href: string}>, href: string, note: string}>
     */
    public function for(User $user): array
    {
        return Cache::remember(Watch::cacheKey($user, 'suggest'), Watch::CACHE_SECONDS, function () use ($user): array {
            $out = [];

            foreach ([
                'reorder' => [['inventory.stock.view'], fn (): ?array => $this->reorder()],
                'call' => [['customer.view', 'credit_control.view'], fn (): ?array => $this->callList()],
                'limit' => [['customer.view', 'credit_control.view'], fn (): ?array => $this->lowerLimits()],
                'expiry' => [['inventory.stock.view'], fn (): ?array => $this->expiry()],
            ] as $key => [$keys, $read]) {
                if (! collect($keys)->every(fn (string $k): bool => $user->can($k))) {
                    continue;
                }

                try {
                    $one = $read();
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }

                if ($one !== null) {
                    $out[] = $one + ['id' => 'suggest.'.$key];
                }
            }

            return $out;
        });
    }

    /** ⭐ আবার কেনার সময় — মজুদ বোর্ডের পুনঃক্রয় তালিকা, যেগুলোর প্রস্তাবিত পরিমাণ আছে */
    private function reorder(): ?array
    {
        $r = app(InventoryBoard::class)->reorder(20);
        $rows = collect($r['rows'])->filter(fn (array $row): bool => $row['sug'] !== '—' && $row['sug'] !== '0')->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $href = route('module.dashboard', ['module' => 'inventory']);
        $first = $rows->first();

        return [
            'module' => 'inventory',
            'title' => (string) __('assistant::suggest.reorder', ['n' => $rows->count()]),
            'detail' => (string) __('assistant::suggest.reorder_detail', ['name' => $first['name'], 'days' => $first['days']]),
            'rows' => $rows->take(6)->map(fn (array $row): array => [
                'label' => (string) $row['name'], 'value' => (string) __('assistant::suggest.reorder_row', ['qty' => $row['sug'], 'days' => $row['days']]), 'href' => $href,
            ])->all(),
            'href' => $href,
            'note' => (string) __('assistant::suggest.reorder_note'),
        ];
    }

    /** ⭐ আজ কাকে ফোন — বাকি পেরিয়েছে, টাকা সবচেয়ে বেশি; পুরনো বিল সাথে (গ্রাহকের দেয়াল মেনে) */
    private function callList(): ?array
    {
        // ⓘ হেডারে বাছা শাখার গ্রাহক কেবল — ড্যাশবোর্ড আর তালিকার একই নিয়ম (EveryPartyListFollowsTheViewedBranchTest)
        $customers = Customer::query()->inViewedBranch()->where('is_active', true)->get()->keyBy('id')->all();
        $facts = app(CreditRisk::class)->factsFor($customers);
        $due = collect($facts)->filter(fn (array $f): bool => bccomp((string) $f['overdue'], '0', 4) > 0)
            ->sort(fn (array $x, array $y): int => bccomp((string) $y['overdue'], (string) $x['overdue'], 4))->take(self::CALL_LIST);

        if ($due->isEmpty()) {
            return null;
        }

        $total = $due->reduce(fn (string $c, array $f): string => bcadd($c, (string) $f['overdue'], 4), '0');

        return [
            'module' => 'credit_control',
            'title' => (string) __('assistant::suggest.call', ['n' => $due->count()]),
            'detail' => (string) __('assistant::suggest.call_detail', ['amount' => Money::format($total, 0)]),
            'rows' => $due->map(fn (array $f, int $id): array => [
                'label' => $customers[$id]->name(),
                'value' => '৳'.Money::format((string) $f['overdue'], 0).' · '.__('assistant::suggest.oldest', ['n' => $f['oldest']]),
                'href' => Route::has('customer.show') ? route('customer.show', $id) : '',
            ])->values()->all(),
            'href' => route('credit_control.report.show', ['slug' => 'positions']),
            'note' => (string) __('assistant::suggest.call_note'),
        ];
    }

    /** ⭐ বাকির সীমা কমানো ভালো — ঝুঁকির মাত্রা বেশি বা চরম, আর সীমা বসানো আছে */
    private function lowerLimits(): ?array
    {
        // ⓘ হেডারে বাছা শাখার গ্রাহক কেবল — ড্যাশবোর্ড আর তালিকার একই নিয়ম (EveryPartyListFollowsTheViewedBranchTest)
        $customers = Customer::query()->inViewedBranch()->where('is_active', true)->get()->keyBy('id')->all();
        $facts = app(CreditRisk::class)->factsFor($customers);
        $rates = app(CreditRisk::class)->rateAll($customers, $facts);
        $risky = collect($rates)->filter(fn (array $r, int $id): bool => in_array($r['level'], [CreditRisk::HIGH, CreditRisk::CRITICAL], true)
            && bccomp((string) ($facts[$id]['limit'] ?? '0'), '0', 4) > 0)
            ->sortByDesc(fn (array $r): int => $r['score'])->take(3);

        if ($risky->isEmpty()) {
            return null;
        }

        return [
            'module' => 'credit_control',
            'title' => (string) __('assistant::suggest.limit', ['n' => $risky->count()]),
            'detail' => (string) __('assistant::suggest.limit_detail'),
            'rows' => $risky->map(fn (array $r, int $id): array => [
                'label' => $customers[$id]->name(),
                'value' => (string) __('assistant::suggest.limit_row', ['limit' => Money::format((string) $facts[$id]['limit'], 0), 'score' => $r['score'],
                    'why' => implode(', ', array_slice($r['reasons'], 0, 2))]),
                'href' => Route::has('customer.show') ? route('customer.show', $id) : '',
            ])->values()->all(),
            'href' => route('credit_control.report.show', ['slug' => 'positions']),
            'note' => (string) __('assistant::suggest.limit_note'),
        ];
    }

    /** ⭐ মেয়াদ ফুরিয়ে আসছে — [[EXPIRY_DAYS]] দিনে; ছাড়ে আগে বিক্রির প্রস্তাব (মালিকের অনুমোদনে) */
    private function expiry(): ?array
    {
        $lots = collect(app(InventoryBoard::class)->expiringLots(self::EXPIRY_DAYS));

        if ($lots->isEmpty()) {
            return null;
        }

        $href = route('module.dashboard', ['module' => 'inventory']);
        $value = $lots->reduce(fn (string $c, array $l): string => bcadd($c, (string) $l['value'], 4), '0');

        return [
            'module' => 'inventory',
            'title' => (string) __('assistant::suggest.expiry', ['n' => $lots->count(), 'days' => self::EXPIRY_DAYS]),
            'detail' => (string) __('assistant::suggest.expiry_detail', ['value' => InventoryBoard::short($value)]),
            'rows' => $lots->take(5)->map(fn (array $l): array => [
                'label' => trim($l['product'].' · '.$l['batch'].($l['warehouse'] !== '' ? ' · '.$l['warehouse'] : '')),
                'value' => (string) __('assistant::suggest.expiry_row', ['days' => $l['days'], 'qty' => rtrim(rtrim((string) $l['qty'], '0'), '.')]),
                'href' => $href,
            ])->values()->all(),
            'href' => $href,
            'note' => (string) __('assistant::suggest.expiry_note'),
        ];
    }
}
