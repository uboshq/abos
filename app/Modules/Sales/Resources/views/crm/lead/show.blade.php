{{--
    একটা লিড — তথ্য, তার সুযোগগুলো, আর গ্রাহক বানানোর ফর্ম।

    ⓘ রূপান্তরের ফর্মটা এখানেই, আলাদা পাতায় নয়: ইংরেজি নাম আর ধরন ঠিক
    করার সময় লিডের ফোন-ঠিকানা চোখের সামনে থাকা দরকার।
--}}
@php
    $canConvert = ! $lead->isConverted()
        && $lead->status !== \App\Modules\Sales\Models\Lead::LOST
        && auth()->user()->can('sales.lead.manage')
        && auth()->user()->can('customer.create');
    $hasBangla = (bool) preg_match('/\p{Bengali}/u', (string) $lead->name);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $lead->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$lead->document_no" :subtitle="$lead->name">
            <x-slot:actions>
                @unless ($lead->isConverted())
                    <x-ui.button tone="secondary" :href="route('sales.lead.edit', $lead->id)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endunless

                @can('sales.opportunity.view')
                    @unless ($lead->status === \App\Modules\Sales\Models\Lead::LOST)
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('sales.opportunity.create', ['lead' => $lead->id])">
                            {{ __('sales::crm.new_opportunity') }}
                        </x-ui.button>
                    @endunless
                @endcan
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

    <x-ui.errors />

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::crm.contact_person' => $lead->contact_person ?: '-',
                    'sales::crm.phone' => $lead->phone ?: '-',
                    'sales::crm.location' => $lead->location?->name() ?: '-',
                    'sales::crm.address' => $lead->address ?: '-',
                    'sales::crm.source_label' => __('sales::crm.source.' . $lead->source),
                    'sales::crm.owner' => $lead->owner?->name ?: '-',
                    'sales::crm.lost_reason' => $lead->lost_reason ?: '-',
                    'sales::crm.notes' => $lead->notes ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::crm.status') }}</dt>
                    <dd class="mt-0.5">@include('sales::crm.lead.partials.status', ['lead' => $lead])</dd>
                </div>

                @if ($lead->customer)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::crm.became_customer') }}</dt>
                        <dd class="mt-0.5">
                            @can('customer.view')
                                <a class="text-(--color-link) hover:underline"
                                   href="{{ route('customer.show', $lead->customer) }}">{{ $lead->customer->code }} — {{ $lead->customer->name() }}</a>
                            @else
                                {{ $lead->customer->code }} — {{ $lead->customer->name() }}
                            @endcan
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::format($lead->converted_at) }} · {{ $lead->converter?->name }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::crm.opportunities') }}</h2>

            @forelse ($opportunities as $opportunity)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-(--color-border) py-2 text-sm">
                    <a class="text-(--color-link) hover:underline"
                       href="{{ route('sales.opportunity.show', $opportunity->id) }}">{{ $opportunity->document_no }} — {{ $opportunity->title }}</a>
                    <span>{{ $opportunity->stage?->name() }} · {{ \App\Core\Support\Money::format($opportunity->estimated_value) }}</span>
                </div>
            @empty
                <p class="text-sm text-(--color-ink-muted)">{{ __('sales::crm.no_opportunities') }}</p>
            @endforelse
        </section>

        @if ($canConvert)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('sales::crm.convert_title') }}</h2>
                <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('sales::crm.convert_note') }}</p>

                <form method="POST" action="{{ route('sales.lead.convert', $lead->id) }}"
                      class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @csrf

                    <x-ui.field name="name_en" :label="__('sales::crm.customer_name_en')" required
                                :value="old('name_en', $hasBangla ? '' : $lead->name)" />

                    <x-ui.field name="name_bn" :label="__('sales::crm.customer_name_bn')"
                                :value="old('name_bn', $hasBangla ? $lead->name : '')" />

                    <x-ui.select name="party_type_id" :label="__('sales::crm.party_type')"
                                 :options="$partyTypes"
                                 :placeholder="__('core.form.choose')"
                                 :selected="old('party_type_id')" />

                    <x-ui.select name="location_id" :label="__('sales::crm.location')"
                                 :options="$locations"
                                 :placeholder="__('core.form.choose')"
                                 :selected="old('location_id', $lead->location_id)" />

                    <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" name="allow_duplicate" value="1" @checked(old('allow_duplicate')) class="mt-1 size-4">
                        <span>{{ __('sales::crm.allow_duplicate') }}</span>
                    </label>

                    <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                        <x-ui.button type="submit" tone="primary">{{ __('sales::crm.convert') }}</x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
