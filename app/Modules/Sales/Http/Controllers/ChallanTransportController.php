<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\TransportRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "মাল কীভাবে যাবে" — নিশ্চিতের পরে, ছাপার আগে (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কী বদলাল ──────────────────────────────────────────────────────────
 * আগে প্রশ্নটা ছিল নিশ্চিত করার দরজায় ([[TransportRule::assertNamed()]]) — কাউন্টারে ক্রেতা দাঁড়িয়ে, অথচ গাড়ি
 * তখনো ঠিক হয়নি, তাই বিক্রিই আটকে থাকত। ⓘ এখন বিক্রি নিশ্চিত হয়, আর মাল বেরোনোর কাগজ (চালান, গেট পাস) ছাপার
 * আগে প্রশ্নটা আসে ([[RequireTransportBeforePrint]])। ⛔ বাকির দেয়াল বদলায়নি — সীমা পেরোলে চালান বা মাল কখনোই নয়।
 *
 * ── তিন পথ ─────────────────────────────────────────────────────────────
 *   গাড়িতে          বহরের গাড়ি বা নম্বর, চালকের নাম, চালকের ফোন
 *   ক্রেতা নিজে      এখনই নিয়ে যাবেন — `own_transport`
 *   সরাসরি ডেলিভারি  হেঁটে, হাতে হাতে — বাহকের নামে "সরাসরি ডেলিভারি" (নতুন কলাম নয়, সমন্বয়কের শর্ত)
 * ⓘ বদলানো যায় যতক্ষণ চালানের গেট পাস হয়নি; গেট পাস হলে মাল বেরিয়ে গেছে, তখন কেবল দেখা।
 * ⓘ অনুমতি: যিনি চালান নিশ্চিত করতে পারেন (`sales.challan.create`, চালানের নিশ্চিতের একই চাবি)।
 * ⓘ ঠিকানাগুলোর শেষে নম্বর — তাই তালিকা থেকে চাপলে মালিকের নিয়মে পপআপে খোলে ([[peek]])।
 */
final class ChallanTransportController extends Controller implements HasMiddleware
{
    /** "সরাসরি ডেলিভারি" — বাহকের নামে বসে; ছাপায় হুবহু এই লেখা */
    public const DIRECT = 'সরাসরি ডেলিভারি';

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    /** ইনভয়েস থেকে — বিলের প্রথম সারির চালান ([[TransportRule::assertNamedForHeld()]]-এর একই নিয়ম) */
    public function forInvoice(Request $request, SalesInvoice $invoice): View
    {
        $invoice->loadMissing(['lines.challanLine']);
        $challanId = $invoice->lines->first()?->challanLine?->delivery_challan_id;

        abort_if($challanId === null, 404, __('sales::transport.no_challan'));

        return $this->form($request, DeliveryChallan::query()->findOrFail($challanId));
    }

    public function forChallan(Request $request, DeliveryChallan $challan): View
    {
        return $this->form($request, $challan);
    }

    public function update(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        if (self::locked($challan)) {
            throw ValidationException::withMessages(['transport' => __('sales::transport.locked')]);
        }

        abort_if($challan->status === DocumentStatus::CANCELLED, 422, __('sales::transport.cancelled'));

        $data = $request->validate([
            'mode' => ['required', Rule::in(['vehicle', 'own', 'direct'])],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('mdm_vehicles', 'id')->where('company_id', \App\Core\Support\CompanyContext::id())],
            // ⓘ কেবল "গাড়িতে" পথে, আর বহরের গাড়ি না বাছলে — বাকি দুই পথে নম্বরের প্রশ্নই নেই
            'vehicle_no' => ['nullable', 'string', 'max:32',
                Rule::requiredIf(fn () => $request->input('mode') === 'vehicle' && blank($request->input('vehicle_id')))],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_phone' => ['nullable', 'string', 'max:32'],
        ], [], [
            'vehicle_no' => __('sales::field.vehicle_no'),
        ]);

        $fields = match ($data['mode']) {
            'own' => ['own_transport' => true, 'vehicle_id' => null, 'vehicle_no' => null, 'driver_name' => null,
                'driver_phone' => null, 'carrier_id' => null, 'carrier_name' => null],
            'direct' => ['own_transport' => false, 'vehicle_id' => null, 'vehicle_no' => null, 'driver_name' => null,
                'driver_phone' => null, 'carrier_id' => null, 'carrier_name' => self::DIRECT],
            default => ['own_transport' => false, 'vehicle_id' => $data['vehicle_id'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? (isset($data['vehicle_id']) ? (Vehicle::query()->whereKey($data['vehicle_id'])->value('registration_no') ?: Vehicle::query()->whereKey($data['vehicle_id'])->value('code')) : null),
                'driver_name' => $data['driver_name'] ?? null, 'driver_phone' => $data['driver_phone'] ?? null,
                'carrier_id' => null, 'carrier_name' => null],
        };

        $challan->forceFill($fields)->save();

        // ⓘ পরিবহন বরাদ্দের তালিকা থেকে এলে সেখানেই ফেরা — পরের চালানটা ধরতে ([[TransportAssignmentController]])
        if ($request->input('from') === 'transport') {
            return redirect()->route('sales.transport.index')->with('saved', __('sales::transport.saved_for', ['no' => $challan->document_no]));
        }

        return redirect()->route('sales.challan.show', $challan)->with('saved', __('sales::transport.saved'));
    }

    /**
     * চালানের গেট পাস হয়ে গেছে (বাতিল নয়) — মাল বেরিয়ে গেছে, পরিবহন আর বদলায় না।
     * ⚠️ কিন্তু একবারও বলা না থাকলে খোলা: নইলে পরিবহন ছাড়া রওনা হওয়া মালের গেট পাস ছাপার দরজা
     * চাইত পরিবহন, আর ফর্ম বলত "বন্ধ" — কাগজটা আর কোনোদিন ছাপা হত না।
     */
    public static function locked(DeliveryChallan $challan): bool
    {
        return TransportRule::named($challan) && GatePass::query()
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();
    }

    /** কোন পথ এখন বাছা — ফর্মের আগে থেকে বসানো ঘর */
    public static function mode(DeliveryChallan $challan): ?string
    {
        return match (true) {
            (bool) $challan->own_transport => 'own',
            $challan->carrier_name === self::DIRECT => 'direct',
            filled($challan->vehicle_id) || filled($challan->vehicle_no) || filled($challan->carrier_id) || filled($challan->carrier_name) => 'vehicle',
            default => null,
        };
    }

    private function form(Request $request, DeliveryChallan $challan): View
    {
        return view('sales::challan.transport', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => $challan,
            'locked' => self::locked($challan),
            'mode' => old('mode', self::mode($challan)),
            'vehicles' => $this->settings->get('master_data.vehicle_enabled')
                ? Vehicle::query()->active()->orderBy('code')->get()
                : collect(),
        ]);
    }
}
