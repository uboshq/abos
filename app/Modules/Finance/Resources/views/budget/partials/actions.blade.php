{{-- বাজেটের পাতাগুলোর একই দুই বোতাম — মাথায় ([[header]]) আর তালিকার টুলবারে --}}
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
