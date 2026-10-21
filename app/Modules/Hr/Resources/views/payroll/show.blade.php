{{--
    একটা বেতনের রান।

    উপরে মোট, তারপর কর্মী ধরে ধরে শিট। খসড়া অবস্থায় "আবার বানান" আছে
    (কাঠামো শুধরে), নিশ্চিত করার পর নেই — তখন অঙ্ক খাতায় বসে গেছে।
--}}
@php
    // ⛔ বেতনের অঙ্ক কেবল `hr.salary.view`-এ — মোট আর প্রতিটা স্লিপ; চাবি ছাড়া মানুষ আর পথ দেখা যায়, অঙ্ক ঢাকা (fe, ২১ অক্টোবর ২০২৬)
    $pay = $pay ?? auth()->user()?->can('hr.salary.view');
    $money = fn ($value) => $pay ? \App\Core\Support\Money::format($value) : \App\Core\Security\FieldSecurity::mask();
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $run->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$run->month->locale(app()->getLocale())->translatedFormat('F Y')"
            :subtitle="$run->document_no . ' · ' . __('core.status.' . $run->status)">
            <x-slot:actions>
                @can('hr.payroll.view')
                    <x-ui.button :href="route('hr.payroll.payslips', $run->id)">
                        {{ __('hr::action.print_payslips') }}
                    </x-ui.button>
                @endcan

                {{-- ব্যাংক ফাইল কেবল নিশ্চিত করা রানে। টাকা পাঠানোর
                     নির্দেশ যেন খাতায় না বসা বেতন থেকে না বেরোয়। --}}
                @if ($run->isConfirmed())
                    {{-- ⓘ ফাইলে ব্যাংক-নম্বর, তাই পরিচয়ের চাবিও — চাবি ছাড়া বোতামই নেই, চাপলে ৪০৩ নয় --}}
                    @can('hr.identity.view')
                        <x-ui.button tone="primary" :href="route('hr.payroll.bank_file', $run->id)">
                            {{ __('hr::action.bank_file') }}
                        </x-ui.button>
                    @endcan
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-4 grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div class="grid gap-3 sm:grid-cols-4">
            {{-- ⓘ নাগালের কর্মীদের মোট — সীমা না থাকলে রানের নিজের (অডিট HR ⛔২) --}}
            @foreach ([
                'hr::field.employee_count' => $summary['count'],
                'hr::field.gross' => $money($summary['gross']),
                'hr::field.deductions' => $money($summary['deductions']),
                'hr::field.net' => $money($summary['net']),
            ] as $label => $value)
                <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                        {{ __($label) }}
                    </p>
                    <p class="num mt-1 text-xl font-semibold">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @can('hr.payroll.manage')
            <aside class="rounded-(--radius-card) border border-(--color-border)
                          bg-(--color-surface-card) p-4">
                @if ($run->isDraft())
                    <p class="mb-3 text-2xs text-(--color-ink-muted)">
                        {{ __('hr::message.confirm_note') }}
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('hr.payroll.rebuild', $run->id) }}">
                            @csrf
                            <x-ui.button type="submit">{{ __('hr::action.rebuild') }}</x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('hr.payroll.confirm', $run->id) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('hr::action.confirm') }}</x-ui.button>
                        </form>
                    </div>
                @elseif ($run->isConfirmed())
                    <p class="mb-3 text-2xs text-(--color-ink-muted)">
                        {{ __('hr::message.bank_file_note', ['count' => $bankRows]) }}
                    </p>
                @endif

                @unless ($run->status === \App\Core\Support\DocumentStatus::CANCELLED)
                    <form method="POST" action="{{ route('hr.payroll.cancel', $run->id) }}"
                          class="mt-3 border-t border-(--color-border) pt-3">
                        @csrf
                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('hr::action.cancel_run') }}</span>
                            <input type="text" name="reason" required
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 text-sm">
                        </label>
                        <x-ui.button class="mt-2" type="submit">{{ __('hr::action.cancel_run') }}</x-ui.button>
                    </form>
                @endunless
            </aside>
        @endcan
    </div>

    {{-- ⭐ ব্যাংক ফাইলে কারা নেই আর কেন — সই-না-পড়া হিসাবে বেতন আটকে (fe, ২১ অক্টোবর ২০২৬; [[PayrollService::heldReason()]]) --}}
    @if (! empty($leftOut))
        <section data-bank-left-out role="status" data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-badge-warning-ink) bg-(--color-badge-warning-bg) p-4 text-sm">
            <p class="font-semibold text-(--color-badge-warning-ink)">{{ __('hr::payroll.left_out_title') }}</p>
            <ul class="mt-2 list-inside list-disc">
                @foreach ($leftOut as $row)
                    <li data-left-out="{{ $row['code'] }}">{{ $row['code'] }} · {{ $row['name'] }} — {{ $row['reason'] }}</li>
                @endforeach
            </ul>
            @if ($run->isConfirmed())
                @can('hr.identity.view')
                    <x-ui.button class="mt-3" :href="route('hr.payroll.bank_file_left_out', $run->id)">{{ __('hr::payroll.left_out_title') }} (CSV)</x-ui.button>
                @endcan
            @endif
        </section>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('hr::message.no_runs')"
            :rows="$run->payslips"
            :columns="[
                ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '8rem',
                 'render' => fn ($s) => $s->employee?->code ?? '—'],
                ['key' => 'name', 'label' => __('hr::field.name'),
                 'render' => fn ($s) => $s->employee?->name() ?? '—'],
                ['key' => 'method', 'label' => __('hr::field.payment_method'), 'width' => '9rem',
                 'render' => fn ($s) => __('hr::kind.' . $s->payment_method)],
                ['key' => 'gross', 'label' => __('hr::field.gross'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($s) => $money($s->gross)],
                ['key' => 'deductions', 'label' => __('hr::field.deductions'), 'numeric' => true,
                 'width' => '9rem', 'render' => fn ($s) => $money($s->deductions)],
                ['key' => 'net', 'label' => __('hr::field.net'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($s) => $money($s->net)],
                ['key' => 'print', 'label' => '—', 'width' => '6rem',
                 'render' => fn ($s) => view('hr::payroll.partials.slip_print', ['slip' => $s])],
            ]" />
    </div>
</x-layouts.app>
