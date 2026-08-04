<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Accounts মডিউলের সেটিংস।
 *
 * সুইচগুলো module.php-তে ঘোষিত, এখানে নয় (নিয়ম ৭)। ফলে নতুন একটা
 * ঐচ্ছিক ফিল্ড যোগ করার সময় তার সুইচটা একই ফাইলে লেখা হয়, আর এই
 * পর্দাটা নিজে থেকেই সেটা দেখায় — দুই জায়গায় দুইবার লিখতে হয় না,
 * তাই একটা বাদ পড়ার সুযোগও থাকে না।
 */
class AccountsSettingsController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.manage')];
    }

    public function edit(Request $request): View
    {
        return view('accounts::settings.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'groups' => $this->grouped(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /*
         * পুরো অ্যারেটা একবারে নেওয়া হয়, input('settings.'.$key) দিয়ে নয়।
         *
         * সেটিং-এর কী-তে ডট আছে ("accounts.backdate_days"), আর Laravel-এর
         * input() ডটকে পথ ধরে নেয় — সে খুঁজত settings['accounts']['backdate_days'],
         * অথচ ফর্ম পাঠায় settings['accounts.backdate_days']। ফলে প্রতিটা
         * মান null আসত আর কিছুই সেভ হত না, নীরবে।
         *
         * @var array<string, mixed> $submitted
         */
        $submitted = (array) $request->input('settings', []);

        foreach ($this->definitions() as $key => $definition) {
            /*
             * চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, তাই boolean
             * সেটিং সবসময় পড়া হয় "আছে কি নেই" হিসেবে। integer-এ সেটা
             * চলে না: ঘরটা ফাঁকা রাখা আর শূন্য লেখা এক জিনিস নয়, আর
             * ফাঁকা মানে "যা ছিল তাই থাক"।
             */
            $raw = $submitted[$key] ?? null;

            $value = match ($definition['type']) {
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'integer' => $raw === null || $raw === '' ? null : (int) $raw,
                default => $raw,
            };

            if ($value !== null) {
                $this->settings->set($key, $value);
            }
        }

        return back()->with('saved', __('accounts::message.settings_saved'));
    }

    /**
     * ঘোষিত সুইচগুলো, গ্রুপ অনুসারে সাজানো।
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grouped(): array
    {
        $groups = [];

        foreach ($this->definitions() as $key => $definition) {
            $groups[$definition['group'] ?? 'general'][] = [
                ...$definition,
                'key' => $key,
                'value' => $this->settings->get($key),
            ];
        }

        return $groups;
    }

    /**
     * শুধু এই মডিউলের সুইচ, কী দিয়ে চাবিকৃত।
     *
     * মডিউল ধরে ফিল্টার করা হয় 'module' দেখে, কী-এর উপসর্গ দেখে নয়:
     * ModuleDefinition এমনিতেই দাবি করে সেটিং-এর কী মডিউলের কোড দিয়ে
     * শুরু হবে, কিন্তু এখানে সেই নিয়মটার উপর নির্ভর না করাই ভালো —
     * দুইটা নিয়ম একই কথা বললে একদিন একটা বদলায়।
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_filter(
            $this->settings->definitions(),
            fn (array $d) => ($d['module'] ?? null) === 'accounts',
        );
    }
}
