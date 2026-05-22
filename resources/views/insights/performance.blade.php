<x-layouts.app>
    <x-ui.toolbar title="{{ __('Holdings') }}" />

    <div x-data="{ tab: '{{ request()->query('tab', 'performance') }}' }">
        <style>
            .nav-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; overflow-x: auto; padding-bottom: 2px; }
            .nav-tab { padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; cursor: pointer; border: none; background: transparent; color: #9ca3af; transition: all 0.15s ease; white-space: nowrap; }
            .nav-tab:hover { background: rgba(99, 102, 241, 0.1); color: #c7d2fe; }
            .nav-tab.active { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; font-weight: 600; }
        </style>
        <div class="nav-tabs">
            <button class="nav-tab" :class="tab === 'performance' && 'active'" @click="tab = 'performance'">{{ __('Performance') }}</button>
            <button class="nav-tab" :class="tab === 'accounts' && 'active'" @click="tab = 'accounts'">{{ __('Accounts') }}</button>
            <button class="nav-tab" :class="tab === 'allocation' && 'active'" @click="tab = 'allocation'">{{ __('Allocation') }}</button>
        </div>

        <div x-show="tab === 'performance'" x-cloak>
            @livewire('performance-dashboard')
        </div>
        <div x-show="tab === 'accounts'" x-cloak>
            @livewire('accounts-overview')
        </div>
        <div x-show="tab === 'allocation'" x-cloak>
            @livewire('allocation-dashboard')
        </div>
    </div>
</x-layouts.app>
