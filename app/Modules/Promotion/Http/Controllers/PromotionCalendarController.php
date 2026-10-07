<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Promotion\Services\PromotionCalendar;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * অফারের ক্যালেন্ডার — স্পেক §১৬।
 *
 * ⓘ কোন অফার কোন রঙে, কোন দিনে, কোন সারিতে — সবটা
 * [[PromotionCalendar]] ঠিক করে। ⚠️ এই দরজা কেবল মাসটা পড়ে আর ছবিটা
 * পাতায় দেয়; ব্লেডে কোনো হিসাব নেই, কোনো `@php` নেই।
 */
final class PromotionCalendarController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly PromotionCalendar $calendar,
    ) {}

    /**
     * ⓘ `promotion.view` — তালিকা দেখার একই চাবি।
     *
     * ⚠️ আলাদা চাবি নয়: ⓘ ক্যালেন্ডার একই অফারগুলোই দেখায়, কেবল অন্য
     * আকারে। ⛔ নতুন চাবি বানালে যিনি তালিকা দেখেন তিনি ক্যালেন্ডার দেখতেন
     * না — একই তথ্য, দুই দরজা, দুই উত্তর।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:promotion.view')];
    }

    public function __invoke(Request $request): View
    {
        $month = $this->calendar->monthOf($request->string('month')->toString());

        return view('promotion::calendar', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * ⓘ মাসের নাম ভাষার ফাইল থেকে, সাল ইংরেজি অঙ্কে — এই বাড়ির নিয়ম।
             * ⚠️ `translatedFormat()` Carbon-এর নিজের অনুবাদ চায়, যা এই
             * বাড়ির ভাষা-বদলের সাথে বাঁধা কি না কেউ মেপে দেখেনি।
             */
            'title' => __('promotion::calendar.month.'.$month->month).' '.$month->year,
            'sheet' => $this->calendar->month($month),
            'legend' => array_map(fn (string $state) => [
                'state' => $state,
                'tone' => $this->calendar->tone($state),
                'label' => __('promotion::calendar.state.'.$state),
            ], $this->calendar->states()),
            'soonDays' => PromotionCalendar::SOON_DAYS,

            /* ⓘ [[PromotionCalendar::WEEK_STARTS]]-এর ক্রমে — নিচের ঘরগুলোর সাথে একই উৎস */
            'weekdays' => array_map(
                fn (string $d) => __('promotion::calendar.weekday.'.$d),
                $this->calendar->weekdayKeys(),
            ),
        ]);
    }
}
