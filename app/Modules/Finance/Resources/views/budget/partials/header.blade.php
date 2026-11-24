{{-- বাজেটের তিন পাতার একই মাথা — শিরোনাম · বর্ণনা · [+ নতুন বাজেট] · রিপোর্ট --}}
<x-ui.page-header :title="__('finance::budget.title')" :subtitle="__('finance::budget.note')">
    <x-slot:actions>
        @include('finance::budget.partials.actions')
    </x-slot:actions>
</x-ui.page-header>
