<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SmsOutboxService;
use App\Http\Controllers\Controller;
use App\Models\SmsGatewayPhone;
use App\Models\SmsOutbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ কন্ট্রোল প্যানেল → বার্তা → SMS-এর ফোন (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
 *
 * ⓘ অফিসের Android ফোন বসানো (নাম, সিমের নম্বর, দিনের সীমা), তার চাবি (একবারই দেখানো), চালু/বন্ধ, সংযুক্ত কি না, আজ কতটা
 *   গেল, সারিতে কতটা অপেক্ষায়, আর শেষ SMS-গুলো কী হলো।
 * ⛔ ফোন মুছে ফেলা যায় না, বন্ধ করা যায় — পুরনো SMS কোন ফোনে গিয়েছিল তা যেন হারিয়ে না যায়।
 */
class SmsPhoneController extends Controller
{
    private const RECENT = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SmsOutboxService $outbox,
    ) {}

    public function index(Request $request): View
    {
        $this->outbox->release();

        return view('notification::sms.phones', [
            'menu' => $this->menu->forUser($request->user()),
            'phones' => SmsGatewayPhone::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'summary' => $this->outbox->summary(),
            'recent' => SmsOutbox::query()->with('gatewayPhone')->latest('id')->limit(self::RECENT)->get(),
            'token' => session('sms_phone_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        [$plain, $hash] = SmsGatewayPhone::newToken();

        $phone = SmsGatewayPhone::query()->create($data + ['token_hash' => $hash, 'is_active' => true, 'created_by' => $request->user()?->id]);
        $phone->auditAction('sms_phone_token');

        // ⓘ চাবি একবারই — পাতা ফিরে এলে আর দেখা যায় না
        return redirect()->route('notification.sms_phones.index')->with('sms_phone_token', ['phone' => $phone->name, 'token' => $plain]);
    }

    public function update(Request $request, SmsGatewayPhone $phone): RedirectResponse
    {
        $data = $this->validated($request) + ['is_active' => $request->boolean('is_active')];
        $phone->update($data);

        return redirect()->route('notification.sms_phones.index')->with('saved', __('notification::sms.saved'));
    }

    /** ⭐ নতুন চাবি — পুরনোটা সাথে সাথে মরে (ফোন হারালে বা হাতবদল হলে) */
    public function token(SmsGatewayPhone $phone): RedirectResponse
    {
        [$plain, $hash] = SmsGatewayPhone::newToken();
        $phone->forceFill(['token_hash' => $hash])->save();
        $phone->auditAction('sms_phone_token');

        return redirect()->route('notification.sms_phones.index')->with('sms_phone_token', ['phone' => $phone->name, 'token' => $plain]);
    }

    /** @return array{name: string, sim_number: ?string, daily_limit: int} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sim_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9০-৯+\-\s]+$/u'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);

        return ['name' => trim($data['name']), 'sim_number' => $data['sim_number'] === null ? null : trim($data['sim_number']), 'daily_limit' => (int) $data['daily_limit']];
    }
}
