<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\DayCloseReminder;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ নিচের চলন্ত বারে দিন বা মাস বন্ধ হয়নি — পপআপের একই হিসাব ([[DayChecklist::openDays()]], [[DayChecklist::openMonth()]]) আর একই মানুষ
 * (দিন বন্ধের চাবি → দিন; দিন বা মাস বন্ধের চাবি → মাস; [[shell.day-check]])। পপআপ কাটার পরেও ঠিক না হওয়া পর্যন্ত চলে (মালিক, ১৮ নভেম্বর ২০২৬)।
 *
 * ⓘ নিয়ন্ত্রণ প্যানেলে "দেখাবে না" (`accounts.day_close.popup_every = off`) হলে বারেও নয় — একই সুইচ।
 * ⛔ কিছু আটকায় না — কেবল লাইন আর লিংক।
 */
final class TickerDayReminder implements DayCloseReminder
{
    public function __construct(private readonly DayChecklist $check) {}

    public function forTicker(User $user): ?array
    {
        $closesDays = $user->can('accounts.day_close.close');
        $closesMonth = $user->can('accounts.period.close');

        if ((! $closesDays && ! $closesMonth) || DayChecklist::popupEvery() === null) {
            return null;
        }

        $today = now()->startOfDay();

        if ($closesDays && ($days = $this->check->openDays($today)) !== []) {
            $latest = end($days);

            return [
                'text' => trans_choice('accounts::dayclose.ticker.days', count($days), [
                    'count' => count($days),
                    'dates' => implode(', ', array_map(fn ($d) => $d->format('d/m/Y'), $days)),
                ]),
                'url' => Route::has('executive.dayclose') ? route('executive.dayclose', ['date' => $latest->toDateString()]) : null,
                'tone' => 'danger',
            ];
        }

        if (($month = $this->check->openMonth($today)) !== null) {
            return [
                'text' => (string) __('accounts::dayclose.popup.month', ['month' => $month->copy()->locale(app()->getLocale())->translatedFormat('F Y')]),
                'url' => Route::has('accounts.period.index') && $closesMonth ? route('accounts.period.index') : null,
                'tone' => 'danger',
            ];
        }

        return null;
    }
}
