{{-- বাজেটের তিন পাতার একই মাথা — শিরোনাম · বর্ণনা · [+ নতুন বাজেট] · রিপোর্ট --}}
<x-ui.page-header :title="__('finance::budget.title')" :subtitle="__('finance::budget.note')">
    <x-slot:actions>
        @can('finance.budget.view')
            <x-ui.button tone="secondary" icon="printer" :href="route('finance.budget.report', ['year' => $year])">
                {{ __('finance::budget.report') }}
            </x-ui.button>
        @endcan
        @can('finance.budget.create')
            <x-ui.button tone="primary" icon="plus" :href="route('finance.budget.create', ['year' => $year])">
                {{ __('finance::budget.new') }}
            </x-ui.button>
        @endcan
    </x-slot:actions>
</x-ui.page-header>
