{{--
    নোটিশের তালিকা — এক পর্দা, দুই রকম পাঠক।

    ⓘ যাঁর চাবি আছে তিনি সবগুলো দেখেন (মেয়াদ শেষ হওয়াগুলোসহ), বাকিরা
    কেবল নিজেদেরগুলো। ⚠️ দুইটা আলাদা পর্দা বানালে একদিন একটায় নিয়ম
    বদলাত আর অন্যটায় নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::notice.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::notice.title')"
                          :subtitle="__('system_admin::notice.note')">
            @if ($canManage)
                <x-slot:actions>
                    <x-ui.button :href="route('system_admin.notice.create')" tone="primary">
                        {{ __('system_admin::notice.new') }}
                    </x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @php $rows = $canManage ? $notices : $mine; @endphp

    @if (count($rows) === 0)
        <x-ui.empty-state :message="$canManage
            ? __('system_admin::notice.none')
            : __('system_admin::notice.none_for_you')" />
    @else
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="table-responsive">
                <table class="ui-list table-cards w-full border-collapse">
                    <thead>
                        <tr class="border-b border-(--color-border)">
                            <th class="text-start">{{ __('system_admin::notice.field_title') }}</th>
                            <th class="text-start">{{ __('system_admin::notice.roles') }}</th>
                            <th class="text-start">{{ __('system_admin::notice.period') }}</th>
                            <th class="text-end"><span class="sr-only">{{ __('core.action.view') }}</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="hover:bg-(--color-surface-hover)">
                                <td data-label="{{ __('system_admin::notice.field_title') }}">
                                    <a href="{{ route('system_admin.notice.show', $row->id) }}"
                                       class="font-medium text-(--color-link) hover:underline">
                                        {{ $row->title }}
                                    </a>

                                    {{-- ⭐ না-পড়া নোটিশে একটা দাগ — নাহলে তালিকায়
                                         নতুনটা পুরনোগুলোর মতোই দেখাত। --}}
                                    @if (in_array($row->id, $unread, true))
                                        <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-info-bg)
                                                     px-2 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                            {{ __('system_admin::notice.unread_badge') }}
                                        </span>
                                    @endif

                                    @if (! $row->is_active)
                                        <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg)
                                                     px-2 py-0.5 text-2xs text-(--color-badge-draft-ink)">
                                            {{ __('system_admin::notice.off') }}
                                        </span>
                                    @endif
                                </td>

                                <td class="text-(--color-ink-muted)"
                                    data-label="{{ __('system_admin::notice.roles') }}">
                                    @php $who = $row->audience->pluck('role')->all(); @endphp
                                    {{ $who === [] ? __('system_admin::notice.everyone') : implode(' · ', $who) }}
                                </td>

                                <td class="text-(--color-ink-muted)"
                                    data-label="{{ __('system_admin::notice.period') }}">
                                    @if ($row->starts_on === null && $row->ends_on === null)
                                        {{ __('system_admin::notice.always') }}
                                    @else
                                        {{ $row->starts_on?->format('d M Y') ?? '—' }}
                                        →
                                        {{ $row->ends_on?->format('d M Y') ?? '—' }}
                                    @endif
                                </td>

                                <td class="text-end">
                                    <a href="{{ route('system_admin.notice.show', $row->id) }}"
                                       class="text-(--color-link) hover:underline">{{ __('core.action.view') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($canManage)
            <x-ui.pager :rows="$notices" />
        @endif
    @endif
</x-layouts.app>
