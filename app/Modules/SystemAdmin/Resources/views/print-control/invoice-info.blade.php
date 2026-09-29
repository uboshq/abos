{{--
    "Set Invoice Information" — ছাপার নিয়ন্ত্রণের ভেতরের একটা ভাগ (মালিক, ২৯ সেপ্টেম্বর ২০২৬:
    *"Print control er vitotre korte paro"*)।

    ⓘ ঘরগুলো ঘোষণা থেকে আঁকা ([[InvoiceInfoController::parts()]]) — কোনো সেটিংয়ের নাম এখানে
    হাতে লেখা নেই। ⚠️ `name="settings[key]"`, আর নিয়ন্ত্রক পুরো অ্যারেটা একবারে পড়ে: চাবিতে
    ডট আছে, আর `input('settings.a.b')` ডটকে পথ ধরে নিত।
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

    <p class="mb-4 text-sm text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_note') }}</p>

    {{-- ── লোগো আর নম্বর — এখানে দেখা, বদলানো নিজের পাতায় ─────────────── --}}
    <section data-boxed
             class="mb-4 grid gap-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4 sm:grid-cols-2">
        <div>
            <h2 class="mb-2 text-sm font-semibold">{{ __('system_admin::settings.invoice_info_logo') }}</h2>

            @if ($company->logoUrl())
                <img src="{{ $company->logoUrl() }}" alt="" class="mb-2 h-14 w-auto">
            @else
                <p class="mb-2 text-sm text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_no_logo') }}</p>
            @endif

            <p class="text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_logo_switch') }}</p>

            @can('system_admin.company.manage')
                <a href="{{ route('system_admin.company.edit', $company) }}"
                   class="mt-1 inline-block text-sm underline">{{ __('system_admin::settings.invoice_info_change_logo') }}</a>
            @endcan
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

    <form method="POST" action="{{ route('system_admin.print_control.invoice_info.update') }}" class="space-y-4">
        @csrf
        @method('PUT')

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
                        @php $old = old('settings.'.$field['key']); @endphp

                        @if ($field['type'] === 'boolean')
                            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                <input type="checkbox" name="settings[{{ $field['key'] }}]" value="1"
                                       @checked((bool) $field['value']) class="mt-1 size-4">
                                <span>{{ $field['name'] }}</span>
                            </label>
                        @elseif ($field['type'] === 'choice')
                            <label class="block text-sm">
                                <span class="mb-1 block">{{ $field['name'] }}</span>
                                <select name="settings[{{ $field['key'] }}]"
                                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                               border-(--color-border) bg-(--color-surface-card) px-3">
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}" @selected((string) $field['value'] === (string) $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @else
                            <label @class(['block text-sm', 'sm:col-span-2' => $part === 'note'])>
                                <span class="mb-1 block">{{ $field['name'] }}</span>
                                <input type="text" name="settings[{{ $field['key'] }}]" maxlength="300"
                                       value="{{ $old ?? $field['value'] }}"
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
