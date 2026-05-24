<?php

use App\Models\Holding;
use App\Traits\Toast;
use Livewire\Volt\Component;

new class extends Component
{
    use Toast;

    // props
    public Holding $holding;

    public bool $reinvest_dividends = false;

    public $quantity_override = null;

    public $avg_cost_override = null;

    // methods
    public function rules()
    {

        return [
            'reinvest_dividends' => ['required', 'boolean'],
            'quantity_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'avg_cost_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function mount()
    {

        $this->reinvest_dividends = $this->holding?->reinvest_dividends ?? false;
        $this->quantity_override = $this->holding?->quantity_override;
        $this->avg_cost_override = $this->holding?->avg_cost_override;
    }

    public function save()
    {
        $validated = $this->validate();

        $this->holding->update($validated);

        // Recalculate with overrides applied
        if ($this->quantity_override !== null || $this->avg_cost_override !== null) {
            $this->holding->syncTransactionsAndDividends();
        }

        $this->success(__('Holding options saved'));

        $this->dispatch('toggle-holding-options');
    }

    public function clearOverrides()
    {
        $this->quantity_override = null;
        $this->avg_cost_override = null;
        $this->holding->update(['quantity_override' => null, 'avg_cost_override' => null]);
        $this->holding->syncTransactionsAndDividends();

        $this->success(__('Overrides cleared — recalculated from transactions'));

        $this->dispatch('toggle-holding-options');
    }
}; ?>

<div class="" x-data="{ }">
    <x-ui.form wire:submit="save" class="">

        <x-ui.toggle 
            label="{{ __('Reinvest Dividends') }}" 
            wire:model="reinvest_dividends" 
            right 
            hint="{{ __('Automatically generate buy transactions for any dividends earned.') }}"
        />

        <div class="divider text-sm text-base-content/60">{{ __('Manual Overrides') }}</div>
        <p class="text-xs text-base-content/50 -mt-2 mb-3">{{ __('Override calculated values. Cleared automatically when a new transaction syncs for this symbol.') }}</p>

        <x-ui.input 
            type="number" 
            step="0.0001"
            label="{{ __('Quantity Override') }}" 
            wire:model="quantity_override"
            placeholder="{{ __('Leave blank to use calculated') }}"
        />

        <x-ui.input 
            type="number" 
            step="0.01"
            label="{{ __('Avg Cost Basis Override') }}" 
            wire:model="avg_cost_override"
            placeholder="{{ __('Leave blank to use calculated') }}"
        />

        @if($holding->quantity_override || $holding->avg_cost_override)
        <div class="mt-1">
            <x-ui.button 
                label="{{ __('Clear Overrides') }}" 
                wire:click="clearOverrides"
                class="btn-sm btn-ghost text-warning"
                spinner="clearOverrides"
            />
        </div>
        @endif

        <x-slot:actions>

            <x-ui.button 
                label="{{ __('Save') }}" 
                type="submit" 
                icon="o-paper-airplane" 
                class="btn-primary" 
                spinner="save"
            />
        </x-slot:actions>
    </x-ui.form>

</div>