<x-layouts.app>
    <div x-data="{ tab: '{{ request()->query('tab', 'activity') }}' }">

        <x-ui.modal
            key="add-cash-flow"
            title="{{ __('Add Cash Flow') }}"
        >
            @livewire('manage-cash-flow-form')
        </x-ui.modal>

        <x-ui.toolbar title="{{ __('Activity') }}">
            <x-ui.flex-spacer />
            <div x-show="tab === 'activity'">
                <x-ui.button
                    label="{{ __('Add Cash Flow') }}"
                    icon="o-plus"
                    class="btn-sm btn-primary whitespace-nowrap"
                    @click="$dispatch('toggle-add-cash-flow')"
                />
            </div>
        </x-ui.toolbar>

        <style>
            .nav-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; overflow-x: auto; padding-bottom: 2px; }
            .nav-tab { padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; cursor: pointer; border: none; background: transparent; color: #9ca3af; transition: all 0.15s ease; white-space: nowrap; }
            .nav-tab:hover { background: rgba(99, 102, 241, 0.1); color: #c7d2fe; }
            .nav-tab.active { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; font-weight: 600; }
        </style>
        <div class="nav-tabs">
            <button class="nav-tab" :class="tab === 'activity' && 'active'" @click="tab = 'activity'">{{ __('Transactions') }}</button>
            <button class="nav-tab" :class="tab === 'income' && 'active'" @click="tab = 'income'">{{ __('Income') }}</button>
            <button class="nav-tab" :class="tab === 'options' && 'active'" @click="tab = 'options'">{{ __('Options') }}</button>
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            @livewire('activity-feed')
        </div>
        <div x-show="tab === 'income'" x-cloak>
            @livewire('income-dashboard')
        </div>
        <div x-show="tab === 'options'" x-cloak>
            @livewire('options-income-dashboard')
        </div>
    </div>
</x-layouts.app>
