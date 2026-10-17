<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingOptions;
use App\Core\Services\SettingsService;
use App\Core\Support\PlainNumber;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
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
            'groups' => $this->grouped($request->user()),
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
        $ownerOnly = [];

        /*
         * ⛔ সংখ্যার ঘর আগে যাচাই, কিছু বসানোর আগেই — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
         *
         * ⓘ এই পাতায় `number` ধরনের শাখাই ছিল না, তাই সম্পদের সীমায় ("৳১০,০০০-এর নিচে খরচ") যা লেখা হত তাই বসত —
         * "50,000", "1e5" বা "abc"। ⛔ [[FixedAssetService]] ঐ লেখা `bccomp()`-এ দিত আর ভেঙে পড়ত, অর্থাৎ নিজের সীমা নেই এমন
         * শ্রেণির **প্রতিটা** সম্পদ নিবন্ধন ৫০০। ⭐ এখন কন্ট্রোল প্যানেলের একই নিয়ম ([[PlainNumber]]): কমা আর বাংলা অঙ্ক চলে,
         * সংখ্যা না হলে কিছুই বসে না আর পাতায় ঘরের নাম আসে।
         */
        $notNumbers = [];

        foreach ($this->definitions($request->user()) as $key => $definition) {
            $raw = $submitted[$key] ?? null;

            if (($definition['type'] ?? null) === 'number'
                && $raw !== null && trim((string) (is_scalar($raw) ? $raw : 'x')) !== '' && PlainNumber::from($raw) === null) {
                $notNumbers[] = __($definition['label']);
            }
        }

        if ($notNumbers !== []) {
            return back()->withInput()->withErrors(['settings' => __('system_admin::validation.setting_not_a_number', [
                'settings' => implode('; ', $notNumbers),
            ])]);
        }

        foreach ($this->definitions($request->user()) as $key => $definition) {
            /*
             * চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, তাই boolean
             * সেটিং সবসময় পড়া হয় "আছে কি নেই" হিসেবে। integer-এ সেটা
             * চলে না: ঘরটা ফাঁকা রাখা আর শূন্য লেখা এক জিনিস নয়, আর
             * ফাঁকা মানে "যা ছিল তাই থাক"।
             */
            $raw = $submitted[$key] ?? null;

            $value = match ($definition['type']) {
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                /*
                 * ⛔ সেটিং লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬: `(int) "10,000"` = 10, আর তালিকা এলে `(int) array` = 1 — কন্ট্রোল
                 * প্যানেলের একই নিয়ম ([[PlainNumber::wholeFrom()]]); পূর্ণসংখ্যা না হলে "যা ছিল তাই থাক"। লেখার ঘরে তালিকা এলেও তাই।
                 */
                'integer' => $raw === null || $raw === '' ? null : PlainNumber::wholeFrom($raw),
                // ⭐ "৫০,০০০" → "50000"; খালি মানে "যা ছিল তাই থাক" (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
                'number' => PlainNumber::from($raw),
                default => is_scalar($raw) || $definition['type'] === 'json' ? $raw : null,
            };

            /*
             * ⛔ তালিকার বাইরের মান নয় — ৩ অক্টোবর ২০২৬। ⓘ এই পাতা ভাউচারের কাগজ ও নকশার
             * বাছাইয়ের ঘরও লেখার ঘর হিসেবে আঁকে; লাইভে কন্ট্রোল প্যানেল থেকে ঠিক এমন অচেনা মান
             * (`"0"`) বসে মালিকের ছাপার নকশা নীরবে বন্ধ হয়েছিল। ভুল মান এলে "যা ছিল তাই থাক" —
             * না ছাঁকলে [[SettingsService::set()]]-এর পাহারা ব্যতিক্রম ছুঁড়ত।
             */
            if ($value === null || ! SettingOptions::allows($definition, $value)) {
                continue;
            }

            /*
             * ⛔ যা বদলায়নি তার জন্য সারি বসে না — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
             *
             * ⓘ এই পাতা প্রতিটা সংরক্ষণে **সব** সেটিং লিখত, বদলাক বা না বদলাক। ⛔ একটা override সারি মানে কোম্পানিটা আর পণ্যের
             * ডিফল্ট অনুসরণ করে না — কাল ডিফল্ট বদলালে এরা নীরবে পুরনোটাই ধরে রাখত। ⭐ কন্ট্রোল প্যানেল আর সেটিংস পাতার একই নিয়ম।
             */
            if ($this->asStored($this->settings->get($key)) === $this->asStored($value)) {
                continue;
            }

            /*
             * ⛔ কেবল-মালিকের সুইচ এই দরজাতেও — সেটিং লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬। ⓘ কন্ট্রোল প্যানেল আর সেটিংস
             * [[SettingsService::mayChange()]] দেখত, এই পাতা দেখত না — `accounts.manage` চাবিতেই ভাউচারের দ্বিতীয় যাচাই, পেছনের
             * তারিখের দিন, নগদের সীমা আর সম্পদের চারটা নিয়ম বদলানো যেত। ⓘ না-বদলানো মান কিছু বলে না (পুরো ফর্মই আসে)।
             */
            if (! $this->settings->mayChange($key, $request->user())) {
                if ($this->asStored($this->settings->get($key)) !== $this->asStored($value)) {
                    $ownerOnly[] = __($definition['label']);
                }

                continue;
            }

            $this->settings->set($key, $value);
        }

        $back = back()->with('saved', __('accounts::message.settings_saved'));

        return $ownerOnly === [] ? $back : $back->withErrors(['settings' => __('system_admin::validation.super_admin_only_switch', [
            'switches' => implode('; ', $ownerOnly),
        ])]);
    }

    /** ⓘ টেক্সট কলামে যেভাবে বসত — তুলনার জন্য ([[ControlPanelController]]-এর একই নিয়ম) */
    private function asStored(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_array($value), is_object($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * ঘোষিত সুইচগুলো, গ্রুপ অনুসারে সাজানো।
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grouped(?Authenticatable $user): array
    {
        $groups = [];

        foreach ($this->definitions($user) as $key => $definition) {
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
    private function definitions(?Authenticatable $user): array
    {
        /*
         * ⛔ মেনুর সুইচ (হিসাব মডিউল, তার দল আর প্রতিটা পর্দা) কেবল কন্ট্রোল প্যানেলের চাবিধারীর — ec, Core লাইন-নিরীক্ষা,
         * ১৫ অক্টোবর ২০২৬। ⓘ [[SettingsService::definitions()]] প্রতিটা মেনু সারির জন্য একটা সুইচ বানায় (`'menu' => true`), আর
         * এই পাতা সেগুলোও আঁকত — কেবল `accounts.manage` চাবিতে একজন হিসাবরক্ষক গোটা কোম্পানির জন্য হিসাবের যেকোনো পর্দা, এমনকি
         * পুরো মডিউল, বন্ধ করতে পারতেন। কন্ট্রোল প্যানেলে ঐ সুইচ `system_admin.settings.manage` চায়; এখানেও তাই — চাবি না থাকলে
         * পাতায় আসে না, আর পাঠালেও বসে না।
         */
        $mayHideScreens = $user instanceof Authorizable && $user->can('system_admin.settings.manage');

        return array_filter(
            $this->settings->definitions(),
            fn (array $d) => ($d['module'] ?? null) === 'accounts' && ($mayHideScreens || ! ($d['menu'] ?? false)),
        );
    }
}
