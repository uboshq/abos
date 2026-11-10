<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Assistant;

use App\Core\Support\Money;
use App\Core\Engines\Assistant\AssistantTool;
use App\Models\User;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ কোপাইলটের দরজা — "কোন ডিলারের কাছে সবচেয়ে বেশি বাকি, কত দিন?" (পূর্ণ পরিকল্পনা অংশ ২)।
 * ⓘ বাকি নিয়ন্ত্রণের নিজের উপকরণ ([[CreditRisk::factsFor()]]) — মেয়াদ পেরোনো টাকা আর সবচেয়ে পুরনো বিল; গ্রাহকের দেয়াল মেনে
 *   (SR কেবল নিজের ডিলার)।
 */
final class OverdueTool implements AssistantTool
{
    public static function name(): string
    {
        return 'credit.overdue';
    }

    public static function permission(): string
    {
        return 'credit_control.view';
    }

    public static function describe(): array
    {
        return [
            'description' => ['bn' => 'মেয়াদ পেরোনো বাকি সবচেয়ে বেশি কাদের, আর সবচেয়ে পুরনো বিল কত দিনের', 'en' => 'Who owes the most overdue money and how old the oldest bill is'],
            'parameters' => ['limit' => ['type' => 'integer', 'description' => 'how many customers (1-20, default 10)']],
            'keywords' => ['বাকি', 'বকেয়া', 'পাওনা', 'baki', 'bokeya', 'due', 'overdue', 'owe'],
        ];
    }

    public function run(User $user, array $args): array
    {
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $customers = Customer::query()->where('is_active', true)->get()->keyBy('id')->all();
        $facts = collect(app(CreditRisk::class)->factsFor($customers))
            ->filter(fn (array $f): bool => bccomp((string) $f['overdue'], '0', 4) > 0)
            ->sortByDesc(fn (array $f): float => (float) $f['overdue'])->take($limit);

        $rows = $facts->map(fn (array $f, int $id): array => [
            'customer' => $customers[$id]->name(),
            'overdue' => '৳'.Money::format((string) $f['overdue'], 0),
            'oldest' => (string) __('credit_control::assistant.days', ['n' => $f['oldest']]),
            'href' => Route::has('customer.show') ? route('customer.show', $id) : '',
        ])->values()->all();

        return [
            'rows' => $rows,
            'columns' => ['customer', 'overdue', 'oldest'],
            'summary' => $rows === [] ? (string) __('credit_control::assistant.none')
                : (string) __('credit_control::assistant.summary', ['n' => count($rows), 'first' => $rows[0]['customer'], 'amount' => $rows[0]['overdue'], 'days' => $rows[0]['oldest']]),
            'sources' => [['label' => (string) __('credit_control::assistant.source'), 'href' => route('credit_control.report.show', ['slug' => 'positions'])]],
        ];
    }
}
