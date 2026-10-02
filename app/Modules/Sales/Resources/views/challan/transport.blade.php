{{-- "মাল কীভাবে যাবে" — নিশ্চিতের পরে, ছাপার আগে (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬; [[ChallanTransportController]])।
     ⓘ তালিকা থেকে চাপলে পপআপে খোলে; তিন পথের একটা বেছে রাখলে তবেই চালান আর গেট পাস ছাপা হয়। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::transport.title') }} — {{ $challan->document_no }}</x-slot:title>

    <section data-boxed class="mx-auto max-w-xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
             data-challan-transport>
        <h1 class="mb-1 text-lg font-semibold">{{ __('sales::transport.title') }}</h1>
        <p class="mb-4 text-sm text-(--color-ink-muted)">{{ $challan->document_no }} · {{ __('sales::transport.why') }}</p>

        @if ($locked)
            {{-- ⓘ গেট পাস হয়ে গেছে — মাল বেরিয়ে গেছে, কেবল দেখা --}}
            <p class="rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-sm" data-transport-locked>
                {{ __('sales::transport.locked') }}
            </p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('sales::transport.mode') }}</dt>
                <dd>{{ $mode ? __('sales::transport.mode_'.$mode) : '—' }}</dd>
                <dt class="text-(--color-ink-muted)">{{ __('sales::field.vehicle_no') }}</dt>
                <dd>{{ $challan->vehicle_no ?: '—' }}</dd>
                <dt class="text-(--color-ink-muted)">{{ __('sales::field.driver_name') }}</dt>
                <dd>{{ $challan->driver_name ?: '—' }}</dd>
            </dl>
        @else
            <form method="POST" action="{{ route('sales.challan.transport.update', $challan) }}" class="grid gap-3" data-no-peek>
                @csrf
                @method('PUT')

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">{{ __('sales::transport.mode') }}</legend>
                    @foreach (['vehicle', 'own', 'direct'] as $choice)
                        <label class="flex min-h-(--spacing-touch) items-start gap-2 rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm">
                            <input type="radio" name="mode" value="{{ $choice }}" class="mt-1 size-4" @checked($mode === $choice) required>
                            <span>
                                <span class="block font-medium">{{ __('sales::transport.mode_'.$choice) }}</span>
                                <span class="block text-2xs text-(--color-ink-muted)">{{ __('sales::transport.hint_'.$choice) }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                {{-- ⓘ গাড়ির ঘর — কেবল "গাড়িতে" বাছলে লাগে; বাকি দুই পথে সার্ভার এগুলো খালি করে --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    @if ($vehicles->isNotEmpty())
                        <x-ui.select name="vehicle_id" :label="__('sales::field.vehicle')"
                                     :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->code.' — '.($v->registration_no ?: ($v->name_bn ?: $v->name_en))])"
                                     :selected="old('vehicle_id', $challan->vehicle_id)" placeholder="-" />
                    @endif
                    <x-ui.field name="vehicle_no" :label="__('sales::field.vehicle_no')" :value="old('vehicle_no', $challan->vehicle_no)" />
                    <x-ui.field name="driver_name" :label="__('sales::field.driver_name')" :value="old('driver_name', $challan->driver_name)" />
                    <x-ui.field name="driver_phone" :label="__('sales::transport.driver_phone')" :value="old('driver_phone', $challan->driver_phone)" />
                </div>

                @error('transport')
                    <p class="text-sm text-(--color-danger)">{{ $message }}</p>
                @enderror

                <div class="flex gap-2">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    <x-ui.button tone="secondary" :href="route('sales.challan.show', $challan)">{{ __('core.action.cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </section>
</x-layouts.app>
