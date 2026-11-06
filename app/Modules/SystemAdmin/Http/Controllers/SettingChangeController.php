<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\SignedSettings;
use App\Http\Controllers\Controller;
use App\Models\SettingChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ জরুরি সেটিংয়ের বদলে দ্বিতীয় হাত — অনুমোদন দিন বা ফেরত দিন (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭; [[SignedSettings]])।
 *
 * ⓘ সারিটা কোম্পানির দেয়ালের ভিতরে খোঁজা ([[BelongsToCompany]]) — অন্য কোম্পানির বদল ৪০৪। ⛔ যিনি চেয়েছেন তিনি নন, সেবা নিজে দেখে।
 */
class SettingChangeController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SignedSettings $signed) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.SignedSettings::KEY)];
    }

    public function sign(Request $request, string $change): RedirectResponse
    {
        $row = SettingChange::query()->where('public_id', $change)->firstOrFail();
        $this->signed->sign($row, $request->user());

        return redirect()->to(route('system_admin.control-panel').'#setting-changes')
            ->with('saved', __('system_admin::settings.change_signed', ['what' => $this->signed->summaryOf($row->refresh())]));
    }

    public function reject(Request $request, string $change): RedirectResponse
    {
        $row = SettingChange::query()->where('public_id', $change)->firstOrFail();
        $note = $request->input('note');
        $this->signed->reject($row, $request->user(), is_string($note) ? $note : null);

        return redirect()->to(route('system_admin.control-panel').'#setting-changes')
            ->with('saved', __('system_admin::settings.change_rejected', ['what' => $this->signed->summaryOf($row->refresh())]));
    }
}
