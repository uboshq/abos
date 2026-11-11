<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\AssistantEvent;
use App\Models\Company;
use App\Models\User;
use App\Modules\Assistant\Watch\Watch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ⭐ কোপাইলটের সকালের খবর — সকাল ৮টায়, প্রত্যেকের নিজের চাবির নজর থেকে (পূর্ণ পরিকল্পনা অংশ ৩, ধাপ ১)।
 *
 * ⓘ খবর যায় বিজ্ঞপ্তি দিয়ে — পর্দার ঘণ্টা আর অ্যাপের পুশ; কেউ চাইলে বিজ্ঞপ্তির নিয়মে SMS বা WhatsApp-এও (ধরন `assistant.brief`,
 *   অন্য সব ধরনের মতো বন্ধ করা যায়)। একই দিনে একজনের কাছে একবারই (খবরের চাবি দিন ধরে)।
 * ⓘ প্রত্যেক মানুষের নিজের চোখে হিসাব: তাঁর হয়ে ঢুকে, তাঁর শাখার দেয়াল আর চাবি মেনে ([[Watch]]) — একজনের নজর আরেকজনের কাছে যায় না।
 * ⓘ কিছু অস্বাভাবিক না থাকলে খবর যায় না — রোজ "সব ঠিক" খবর মানুষ পড়া ছেড়ে দেন।
 */
final class CopilotMorning extends Command
{
    protected $signature = 'abos:copilot-morning';

    protected $description = 'Send each Copilot user their morning watch summary (own keys, own branches), once a day';

    public function handle(AssistantEngine $engine, NotificationService $notify): int
    {
        $told = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($engine, $notify, $companyId, &$told): void {
                if (! $engine->on('brief') || ! $engine->on('watch')) {
                    return;
                }

                $users = User::query()->whereHas('companies', fn ($q) => $q->whereKey($companyId))->get()
                    ->filter(fn (User $u): bool => $u->can('assistant.use'));

                foreach ($users as $user) {
                    try {
                        $told += $this->tell($user, $engine, $notify) ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        $this->info("told={$told}");

        return self::SUCCESS;
    }

    private function tell(User $user, AssistantEngine $engine, NotificationService $notify): bool
    {
        // ⓘ তাঁর হয়েই দেখা — দেখার শাখা আর ডিলারের দেয়াল তাঁর
        Auth::setUser($user);
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();

        $findings = app(Watch::class)->for($user);

        if ($findings === []) {
            return false;
        }

        $urgent = count(array_filter($findings, fn (array $f): bool => $f['severity'] === 'urgent'));
        $title = (string) __('assistant::morning.title', ['name' => $engine->name(), 'n' => count($findings), 'urgent' => $urgent]);
        $body = implode("\n", array_map(fn (array $f): string => '• '.$f['title'].' · '.$f['count'], array_slice($findings, 0, 3)));

        $notify->send($user, 'assistant.brief', $title, $body, route('assistant.today'), key: 'assistant:brief:'.$user->id.':'.now()->toDateString());
        $engine->record(AssistantEvent::BRIEF, $user, $title, ['outcome' => (string) count($findings)]);

        return true;
    }
}
