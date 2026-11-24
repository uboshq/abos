{{--
    ⭐ পার্টিকে বার্তা — খাতা: WhatsApp আর SMS এক তালিকায়, প্রতিটার অবস্থাসহ (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬; নমুনা
    HUXqpfhxng8823eEFrvH54 ট্যাব গ)।
    ⓘ মাথা: নাম · ট্যাব (সব / পৌঁছেছে / অপেক্ষায় / ব্যর্থ) · ছাঁকনি … নামান (Excel) — টুলবারের মান; শিরোনামের নিচে ব্যাখ্যার লাইন নেই
      (মালিক, ১৯ নভেম্বর ২০২৬ রাত)। তারপর আজকের চার সংখ্যা, তারপর তালিকা।
    ⓘ কলাম ঠিক নমুনার: কখন · পার্টি · নম্বর · কাগজ · পথ · কে/কীভাবে · অবস্থা · অ্যাকশন (⋯)।
    ⓘ শাখার দেয়ালের ভিতরে ([[ScopedToUserBranch]])।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Models\PartyMessage;
    use Illuminate\Support\HtmlString;

    $exporting = app(\App\Core\Services\ListExport::class)->wanted();
    $clock = fn ($t) => $t === null ? '' : \Illuminate\Support\Carbon::parse($t)->format('H:i');

    // ⓘ নমুনার মতো — আজকের সারিতে কেবল সময়, পুরনো সারিতে তারিখও
    $when = fn (PartyMessage $m) => $m->created_at === null ? ''
        : ($m->created_at->isToday() ? $clock($m->created_at) : DateFormat::format($m->created_at).' '.$clock($m->created_at));

    // ⓘ বাংলাদেশের মোবাইল দেশের ভিতরের রূপে ("01711-234567"), বিদেশের নম্বর +দেশের কোড সহ
    $phone = function (PartyMessage $m): string {
        $p = (string) $m->phone;

        return preg_match('/^880(1\d{3})(\d{6})$/', $p, $x) === 1 ? '0'.$x[1].'-'.$x[2] : ($p === '' ? '—' : '+'.$p);
    };

    // ⓘ কাগজের নম্বর; পার্টির হিসাবের সারিতে (বাকির তাগাদা) লেখার নাম, না থাকলে নিয়মের নাম
    $paper = fn (PartyMessage $m) => $m->document_type === PartyMessage::PARTY_ACCOUNT
        ? (string) ($m->template?->name ?? ($m->rule ? __('notification::party_log.rules.'.$m->rule) : '—'))
        : (string) ($m->document_no ?? '—');

    $roadName = fn (PartyMessage $m) => (string) __('notification::party.channels.'.($m->channel === 'sms' ? 'sms' : 'whatsapp'));

    $how = function (PartyMessage $m) use ($phones): string {
        if ($m->via === PartyMessage::AUTO) {
            $road = $m->route === PartyMessage::CLOUD
                ? __('notification::party_log.cloud')
                : (($phones[(int) $m->sms_outbox_id] ?? null) ?: __('notification::party_log.office_phone'));

            return (string) __('notification::party_log.auto', ['road' => $road]);
        }

        return (string) __('notification::party_log.button', ['who' => $m->sender?->name ?? '—']);
    };

    // ⭐ অবস্থা — পিলের লেখা, রং, আর ব্যর্থ হলে লাল কারণের লাইন ("এই নম্বরে WhatsApp নেই → SMS-এ গেছে")
    $state = function (PartyMessage $m) use ($clock, $fellBack): array {
        $why = null;
        [$tone, $text] = match ($m->status) {
            PartyMessage::OPENED => ['info', __('notification::party_log.states.opened')],
            PartyMessage::WAITING => ['pending', __('notification::party_log.states.'.($m->route === PartyMessage::PHONE ? 'waiting_phone' : 'waiting'))],
            PartyMessage::SENT => ['pending', __('notification::party_log.states.sent', ['time' => $clock($m->sent_at)])],
            PartyMessage::DELIVERED => ['success', __('notification::party_log.states.delivered', ['time' => $clock($m->delivered_at)])],
            PartyMessage::READ => ['success', __('notification::party_log.states.read', ['time' => $clock($m->read_at)])],
            PartyMessage::FAILED => ['danger', __('notification::party_log.states.failed')],
            default => ['draft', (string) $m->status],
        };

        if ($m->status === PartyMessage::FAILED) {
            $reason = trim((string) $m->error) !== '' ? (string) $m->error : (string) __('notification::party_log.no_reason');
            $why = isset($fellBack[(int) $m->id]) ? (string) __('notification::party_log.fell_back', ['reason' => $reason]) : $reason;
        }

        return ['tone' => $tone, 'text' => trim((string) $text), 'why' => $why];
    };

    $columns = [
        ['key' => 'when', 'label' => __('notification::party_log.when'), 'width' => '7rem',
            'render' => fn ($m) => $exporting ? $when($m) : new HtmlString('<span class="num whitespace-nowrap" data-party-message="'.(int) $m->id.'">'.e($when($m)).'</span>')],
        ['key' => 'party', 'label' => __('notification::party_log.party'), 'wrap' => true, 'render' => fn ($m) => $m->party_name ?? '—'],
        ['key' => 'phone', 'label' => __('notification::party_log.phone'), 'width' => '8.5rem',
            'render' => fn ($m) => $exporting ? $phone($m) : new HtmlString('<span class="num whitespace-nowrap">'.e($phone($m)).'</span>')],
        ['key' => 'paper', 'label' => __('notification::party_log.paper'), 'width' => '9rem',
            'render' => fn ($m) => $exporting || $m->document_type === PartyMessage::PARTY_ACCOUNT ? $paper($m)
                : new HtmlString('<span class="num" title="'.e(__('notification::party.types.'.$m->document_type)).'">'.e($paper($m)).'</span>')],
        ['key' => 'road', 'label' => __('notification::party_log.road'), 'width' => '7rem',
            'render' => fn ($m) => $exporting ? $roadName($m) : view('notification::party.partials.road', ['message' => $m, 'name' => $roadName($m)])],
        ['key' => 'how', 'label' => __('notification::party_log.how'), 'wrap' => true, 'render' => fn ($m) => $how($m)],
        ['key' => 'state', 'label' => __('notification::party_log.state'), 'wrap' => true,
            'render' => function ($m) use ($exporting, $state) {
                $s = $state($m);

                return $exporting
                    ? ($s['why'] !== null ? $s['text'].' — '.$s['why'] : $s['text'])
                    : view('notification::party.partials.state', ['message' => $m, 'state' => $s]);
            }],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    {{-- ⭐ সব · পৌঁছেছে · অপেক্ষায় · ব্যর্থ (`?tab=`); ABOS রূপে মাথার সারিতে নামের পাশে বসে ([[compactPageHead]]) --}}
    <x-ui.list-tabs :tabs="$tabs" :label="__('notification::party_log.tabs_label')" />

    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('notification::party_log.noun')">
        {{-- ⭐ আজকের চার সংখ্যা — আজ পাঠানো · পৌঁছেছে · অপেক্ষায় · ব্যর্থ (দেয়ালের ভিতরে, ছাঁকনি ছাড়া); তালিকার কার্ডের ছাঁচ, লিংক নয় --}}
        <div class="list-cards" data-list-cards data-party-today>
            @foreach ($todayCards as $key => [$a, $b, $icon])
                <div class="list-card" data-card="{{ $key }}" style="--a: {{ $a }}; --b: {{ $b }}">
                    <span class="list-card-icon" aria-hidden="true"><x-ui.icon :name="$icon" :size="14" /></span>
                    <span class="min-w-0">
                        <span class="list-card-label">{{ __('notification::party_log.today.'.$key) }}</span>
                        <span class="list-card-value num">{{ number_format($today[$key]) }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <div data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                @if ($tab !== 'all')
                    <input type="hidden" name="tab" value="{{ $tab }}">
                @endif
                <x-ui.toolbar :title="$title" :columns="$columns" :facets="$filters" :quiet="['tab']" :share="false"
                              :search-placeholder="__('notification::party_log.search')" />
            </form>

            {{-- ⓘ আবার পাঠানো বা অন্য পথে পাঠানোর ফল — কাজের উত্তর, ব্যাখ্যার লাইন নয় --}}
            @if (session('saved') || session('failed') || $errors->any())
                <div class="px-3 pt-2" data-party-resend-result>@include('notification::partials.flash')</div>
            @endif

            <x-ui.table
                :empty="__('notification::party_log.empty')"
                :rows="$rows"
                :columns="$columns"
                :compact="\App\Core\Support\Ui::compact()"
                :row-menu="fn ($m) => view('notification::party.partials.row-menu', ['message' => $m, 'roads' => $roads, 'partyRoutes' => $partyRoutes])" />

            <x-ui.pager :rows="$rows" />
            <x-ui.list-totals :rows="$rows" />
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
