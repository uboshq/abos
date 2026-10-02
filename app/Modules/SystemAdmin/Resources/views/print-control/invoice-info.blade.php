{{--
    "Set Your Invoice Information" — ছাপার নিয়ন্ত্রণের ভেতরের একটা ভাগ (মালিক, ২৯ সেপ্টেম্বর ২০২৬:
    *"Print control er vitotre korte paro"*)।

    ⭐ ৩০ সেপ্টেম্বর ২০২৬: শাখা ধরে আলাদা (*"protiti branch ER JONNO ALADA ALADA HOBE"*), আর বিলের লোগো আলাদা
    তোলা (*"INVOICE LOGO ALADA UPLOAD MUST"*)। ⓘ শাখা বাছলে প্রতিটা ঘরের একটা "কোম্পানির মতো" মান থাকে — খালি ঘর,
    বা বাছাইয়ের প্রথম বিকল্প; তখন কোম্পানির সেটিংই চলে।

    ⓘ ঘরগুলো ঘোষণা থেকে আঁকা ([[InvoiceInfoController::parts()]]) — কোনো সেটিংয়ের নাম এখানে হাতে লেখা নেই।
    ⚠️ `name="settings[key]"`, আর নিয়ন্ত্রক পুরো অ্যারেটা একবারে পড়ে: চাবিতে ডট আছে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::settings.invoice_info_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::settings.print_title')"
                          :subtitle="__('system_admin::settings.print_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @include('system_admin::control-panel.partials.tabs')
    @include('system_admin::print-control.partials.papers', ['current' => 'invoice_info'])

    {{-- ── কোন শাখার — প্রতিটার নিজের ঠিকানা ────────────────────────────── --}}
    <nav aria-label="{{ __('system_admin::settings.invoice_info_for') }}" data-branch-picker
         class="mb-4 flex flex-wrap items-center gap-1 border-b border-(--color-border)">
        <span class="me-2 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_for') }}</span>
        @foreach ([null, ...$branches] as $one)
            @php $id = $one?->id; @endphp
            <a href="{{ route('system_admin.print_control.invoice_info', array_filter(['branch' => $id])) }}"
               @class([
                   'min-h-(--spacing-touch) px-3 py-2 text-sm transition-colors',
                   'border-b-2 border-(--color-brand-600) font-semibold' => $branch === $id,
                   'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $branch !== $id,
               ])
               @if ($branch === $id) aria-current="page" @endif>
                {{ $one === null ? __('system_admin::settings.invoice_info_company') : $one->name() }}
            </a>
        @endforeach
    </nav>

    <p class="mb-4 text-sm text-(--color-ink-muted)">
        {{ $branch === null ? __('system_admin::settings.invoice_info_note') : __('system_admin::settings.invoice_info_branch_note') }}
    </p>

    <form method="POST" action="{{ route('system_admin.print_control.invoice_info.update') }}"
          enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')
        <input type="hidden" name="branch" value="{{ $branch }}">

        {{-- ── বিলের লোগো আর নম্বর ─────────────────────────────────────────── --}}
        <section data-boxed data-invoice-logo
                 class="grid gap-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4 sm:grid-cols-2">
            <div>
                <h2 class="mb-2 text-sm font-semibold">{{ __('system_admin::settings.invoice_logo') }}</h2>

                @if ($invoiceLogo)
                    <img src="{{ $invoiceLogo }}" alt="" class="mb-2 h-14 w-auto">
                @elseif ($company->logoUrl())
                    <img src="{{ $company->logoUrl() }}" alt="" class="mb-2 h-14 w-auto opacity-60">
                    <p class="mb-2 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_logo_using_profile') }}</p>
                @else
                    <p class="mb-2 text-sm text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_no_logo') }}</p>
                @endif

                <label class="block text-sm">
                    <span class="mb-1 block">{{ __('system_admin::settings.invoice_logo_upload') }}</span>
                    <input type="file" name="invoice_logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                </label>
                @error('invoice_logo')
                    <span class="mt-1 block text-xs text-(--color-danger)">{{ $message }}</span>
                @enderror

                @if ($ownLogo)
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" name="remove_invoice_logo" value="1" class="size-4">
                        <span>{{ $branch === null ? __('system_admin::settings.invoice_logo_remove') : __('system_admin::settings.invoice_logo_remove_branch') }}</span>
                    </label>
                @endif

                <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_logo_note') }}</p>
            </div>

            <div>
                <h2 class="mb-2 text-sm font-semibold">{{ __('system_admin::settings.invoice_info_next') }}</h2>

                @if ($next !== null)
                    <p class="mb-2 font-mono text-lg" data-next-number>{{ $next }}</p>
                @else
                    <p class="mb-2 text-sm text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_no_series') }}</p>
                @endif

                @if (Route::has('master_data.series.index'))
                    <a href="{{ route('master_data.series.index') }}"
                       class="text-sm underline">{{ __('system_admin::settings.invoice_info_series') }}</a>
                @endif
            </div>
        </section>

        @foreach ($parts as $part => $fields)
            <section data-boxed data-part="{{ $part }}"
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ __('system_admin::settings.invoice_info_part.'.$part) }}</h2>

                <div @class([
                    'grid gap-3',
                    'sm:grid-cols-2 lg:grid-cols-3' => $part === 'show',
                    'sm:grid-cols-2' => $part !== 'show',
                ])>
                    @foreach ($fields as $field)
                        @php
                            $old = old('settings.'.$field['key']);
                            $inherit = __('system_admin::settings.invoice_info_inherit');
                        @endphp

                        @if ($field['type'] === 'boolean' && $branch === null)
                            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                <input type="checkbox" name="settings[{{ $field['key'] }}]" value="1"
                                       @checked((bool) $field['value']) class="mt-1 size-4">
                                <span>{{ $field['name'] }}</span>
                            </label>
                        @elseif ($field['type'] === 'boolean')
                            {{-- ⓘ শাখায় তিন মান: কোম্পানির মতো · চালু · বন্ধ --}}
                            <label class="block text-sm">
                                <span class="mb-1 block">{{ $field['name'] }}</span>
                                <select name="settings[{{ $field['key'] }}]" data-branch-field
                                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                               border-(--color-border) bg-(--color-surface-card) px-3">
                                    <option value="" @selected($field['value'] === null)>
                                        {{ $inherit }} ({{ $field['inherited'] ? __('system_admin::settings.on') : __('system_admin::settings.off') }})
                                    </option>
                                    <option value="1" @selected($field['value'] === true)>{{ __('system_admin::settings.on') }}</option>
                                    <option value="0" @selected($field['value'] === false)>{{ __('system_admin::settings.off') }}</option>
                                </select>
                            </label>
                        @elseif ($field['type'] === 'choice')
                            <label class="block text-sm">
                                <span class="mb-1 block">{{ $field['name'] }}</span>
                                <select name="settings[{{ $field['key'] }}]"
                                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                               border-(--color-border) bg-(--color-surface-card) px-3">
                                    @if ($branch !== null)
                                        <option value="" @selected($field['value'] === null)>{{ $inherit }} ({{ $field['inherited'] }})</option>
                                    @endif
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}" @selected($field['value'] !== null && (string) $field['value'] === (string) $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @else
                            <label @class(['block text-sm', 'sm:col-span-2' => $part === 'note'])>
                                <span class="mb-1 block">{{ $field['name'] }}</span>
                                <input type="text" name="settings[{{ $field['key'] }}]" maxlength="300"
                                       value="{{ $old ?? $field['value'] }}"
                                       @if ($branch !== null && filled($field['inherited'])) placeholder="{{ $field['inherited'] }}" @endif
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3">
                                @error('settings.'.$field['key'])
                                    <span class="mt-1 block text-xs text-(--color-danger)">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

            {{-- ⓘ নতুন ট্যাবে — একই পাতায় খুললে না-সংরক্ষিত বদল হারাত --}}
            @if ($sample !== null)
                <a href="{{ $sample }}" target="_blank" rel="noopener"
                   class="text-sm underline">{{ __('system_admin::settings.invoice_info_sample') }}</a>
            @endif
        </div>
    </form>
</x-layouts.app>
