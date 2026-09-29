{{--
    ডকুমেন্ট ম্যানেজমেন্টের ড্যাশবোর্ড — গোটা পরিকল্পনা, প্রতিটা অংশের অবস্থা
    ([[PlanController::dashboard()]], ৩০ সেপ্টেম্বর ২০২৬)।

    ⛔ কোনো ফর্ম, কোনো বোতাম নেই — কেবল পড়ার পাতা। লিংকগুলো কেবল DOC-এর
    নিজের পরিকল্পনার পাতায় যায়।

    ⓘ `data-doc-page` আর `data-doc-page-end` পাতার নিজের অংশের দুই প্রান্ত —
    পরীক্ষা ([[TheDocumentModuleShowsItsPlanTest]]) ঐ দুইটার মাঝখানটাই দেখে,
    কারণ শেলের নিজের ফর্ম (লগআউট, কোম্পানি বদল) এই পাতার নয়।
--}}
@php
    use App\Modules\Documents\Support\DocumentPlan;

    $badge = [
        DocumentPlan::SHELL => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
        DocumentPlan::PLANNED => 'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)',
        DocumentPlan::RULE => 'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)',
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.dashboard') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::page.module_title')"
                          :subtitle="__('documents::page.dashboard_subtitle')" />
    </x-slot:header>

    <div data-doc-page="dashboard">
        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="inline-flex rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-1 text-sm
                      font-semibold text-(--color-badge-pending-ink)">
                {{ __('documents::page.coming_soon') }}
            </p>

            <p class="mt-4 max-w-3xl text-sm leading-relaxed text-(--color-ink-body)">
                {{ __('documents::page.purpose') }}
            </p>

            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-(--color-ink-muted)">
                {{ __('documents::page.nothing_works_yet') }}
            </p>
        </section>

        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-warning)
                        bg-(--color-badge-warning-bg) p-4 text-sm text-(--color-badge-warning-ink)">
            <h2 class="font-semibold">{{ __('documents::page.rules_heading') }}</h2>

            <ol class="mt-2 list-decimal space-y-1 ps-6">
                <li>{{ __('documents::page.rule_no_ai') }}</li>
                <li>{{ __('documents::page.rule_reuse') }}</li>
                <li>{{ __('documents::page.rule_wall') }}</li>
            </ol>
        </section>

        <h2 class="mb-2 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.sections_heading') }}</h2>

        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <table class="ui-list table-cards w-full border-collapse">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::page.col_no') }}</th>
                        <th class="text-start">{{ __('documents::page.col_section') }}</th>
                        <th class="text-start">{{ __('documents::page.col_status') }}</th>
                        <th class="text-start">{{ __('documents::page.col_built_on') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($sections as $no => $section)
                        <tr data-doc-section="{{ $no }}">
                            <td class="num">{{ __('documents::page.section_ref', ['no' => $no]) }}</td>

                            <td>
                                <span class="font-semibold">{{ __('documents::plan.s'.$no.'.title') }}</span>
                                <span class="mt-0.5 block text-2xs text-(--color-ink-muted)">
                                    {{ __('documents::plan.s'.$no.'.summary') }}
                                </span>
                            </td>

                            <td>
                                <span class="rounded-(--radius-pill) px-2 py-0.5 text-2xs {{ $badge[$section['status']] }}">
                                    {{ __('documents::page.status_'.$section['status']) }}
                                </span>
                            </td>

                            <td class="text-2xs">
                                @foreach ($section['systems'] as $system)
                                    <span class="block">
                                        {{ __('documents::system.'.$system) }}
                                        <code class="font-mono text-(--color-ink-muted)">{{ class_basename($systems[$system]) }}</code>
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h2 class="mb-2 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.screens_heading') }}</h2>

        <ul data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            @foreach ($screens as $slug => $screen)
                <li class="py-1">
                    <a href="{{ route('documents.screen', ['screen' => $slug]) }}"
                       class="text-(--color-link) hover:underline">{{ __('documents::menu.'.$slug) }}</a>
                    <span class="text-2xs text-(--color-ink-muted)">
                        @foreach ($screen['sections'] as $no)
                            {{ __('documents::page.section_ref', ['no' => $no]) }}
                        @endforeach
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
    <span data-doc-page-end></span>
</x-layouts.app>
