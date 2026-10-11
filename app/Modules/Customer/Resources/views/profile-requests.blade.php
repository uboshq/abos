{{--
    ⭐ অফিসের দিক — পোর্টাল থেকে গ্রাহকের অনুরোধ (তথ্য বদল, কাগজ); গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬।

    ডিফল্টে কেবল অপেক্ষমাণ — এই পাতার একটাই কাজ: আজ কোনগুলো বসানো বাকি। ⓘ তথ্য বদলে "ছিল" আর "চাওয়া হলো"
    পাশাপাশি, যাতে মিলিয়ে গ্রাহকের পাতায় বসানো যায়; বসানোর পরে "বসানো হয়েছে", না হলে কারণসহ "হলো না" —
    গ্রাহক কারণটা পোর্টালেই দেখেন।
    ⛔ এখান থেকে গ্রাহকের রেকর্ড বদলায় না ([[ProfileRequestService::decide()]])।
--}}
@php
    $columns = [
        [
            'key' => 'customer',
            'label' => __('customer::profile_request.customer'),
            'width' => '14rem',
            'render' => fn ($r) => view('customer::partials.profile-request-customer', ['row' => $r]),
        ],
        [
            'key' => 'kind',
            'label' => __('customer::profile_request.kind_contact').' / '.__('customer::profile_request.kind_paper'),
            'render' => fn ($r) => view('customer::partials.profile-request-what', ['row' => $r]),
        ],
        [
            'key' => 'sent_at',
            'label' => __('customer::profile_request.sent_at'),
            'width' => '10rem',
            'render' => fn ($r) => \App\Core\Support\DateFormat::formatWithTime($r->created_at),
        ],
        [
            'key' => 'decide',
            'label' => '',
            'width' => '16rem',
            'render' => fn ($r) => view('customer::partials.profile-request-decide', ['row' => $r]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::profile_request.title') }}</x-slot:title>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui.page-header class="mb-3" :title="__('customer::profile_request.title')" :subtitle="__('customer::profile_request.subtitle')" />

    <div data-boxed data-profile-requests
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="flex flex-wrap gap-2 border-b border-(--color-border) px-3 py-2">
            <a href="{{ route('customer.profile_request.index') }}"
               @class([
                   'rounded-(--radius-field) border px-3 py-1.5 text-sm',
                   'border-(--color-brand-500) text-(--color-brand-500)' => ! $all,
                   'border-(--color-border)' => $all,
               ])>
                {{ __('customer::profile_request.only_open') }} ({{ $openCount }})
            </a>
            <a href="{{ route('customer.profile_request.index', ['show' => 'all']) }}"
               @class([
                   'rounded-(--radius-field) border px-3 py-1.5 text-sm',
                   'border-(--color-brand-500) text-(--color-brand-500)' => $all,
                   'border-(--color-border)' => ! $all,
               ])>
                {{ __('customer::profile_request.all') }}
            </a>
        </div>

        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('customer::profile_request.empty')" />
    </div>

    <x-ui.pager :rows="$rows" />
</x-layouts.app>
