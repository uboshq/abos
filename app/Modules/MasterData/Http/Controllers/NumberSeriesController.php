<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\NumberSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডকুমেন্ট নম্বর সিরিজ।
 *
 * সিরিজগুলো মডিউলের ঘোষণা থেকে নিজে থেকে তৈরি হয়
 * (NumberSeriesProvisioner), তাই এখানে "নতুন" বোতাম নেই — শুধু উপসর্গ
 * ও শূন্যের সংখ্যা বদলানো যায়।
 *
 * পরের নম্বরটা এখানে বদলানো যায় না, ইচ্ছাকৃতভাবে: পিছিয়ে দিলে একই
 * নম্বর দুইবার ইস্যু হত, আর এগিয়ে দিলে অডিটে একটা ফাঁক থাকত যার কোনো
 * ব্যাখ্যা নেই। দুইটাই এমন ভুল যা মাস পরে ধরা পড়ে।
 */
class NumberSeriesController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ModuleRegistry $registry,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:master_data.manage')];
    }

    public function index(Request $request): View
    {
        return view('master_data::series.index', [
            'menu' => $this->menu->forUser($request->user()),
            'series' => NumberSeries::query()
                ->orderBy('module')
                ->orderBy('doc_type')
                ->get(),
            'labels' => $this->docTypeLabels(),
        ]);
    }

    public function update(Request $request, NumberSeries $series): RedirectResponse
    {
        $validated = $request->validate([
            'prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]+$/'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        // next_number ইচ্ছাকৃতভাবে বাদ — উপরের মন্তব্য দেখুন
        $series->update($validated);

        return back()->with('saved', __('master_data::message.updated'));
    }

    /**
     * ডকুমেন্ট টাইপের পড়ার মতো নাম — মডিউলের ঘোষণা থেকে।
     *
     * "RV" দেখে কেউ বলতে পারে না কোন ডকুমেন্ট। module.php-তে প্রতিটার
     * অনুবাদের কী ঘোষিত আছে, আর সেটাই ব্যবহার হয় — এখানে আলাদা তালিকা
     * রাখলে নতুন ডকুমেন্ট টাইপে সেটা হালনাগাদ করতে কেউ ভুলত।
     *
     * @return array<string, string>
     */
    private function docTypeLabels(): array
    {
        $labels = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->docTypes as $code => $label) {
                $labels[$code] = $label;
            }
        }

        return $labels;
    }
}
