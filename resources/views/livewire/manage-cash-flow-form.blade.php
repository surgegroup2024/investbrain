<?php

use App\Models\CashFlow;
use App\Models\Portfolio;
use App\Traits\Toast;
use Livewire\Volt\Component;

new class extends Component
{
    use Toast;

    // form fields
    public string $portfolio_id = '';

    public string $type = 'DEPOSIT';

    public $amount = 0;

    public string $date = '';

    public string $description = '';

    // state
    public ?array $duplicateWarning = null;

    protected $listeners = [
        'toggle-add-cash-flow' => 'resetForm',
    ];

    public function rules(): array
    {
        return [
            'portfolio_id' => 'required|exists:portfolios,id',
            'type' => 'required|in:DEPOSIT,WITHDRAWAL',
            'amount' => 'required|numeric|gt:0',
            'date' => 'required|date_format:Y-m-d|before_or_equal:'.now()->toDateString(),
            'description' => 'nullable|string|max:255',
        ];
    }

    public function updatedDate(): void
    {
        $this->checkForDuplicate();
    }

    public function updatedAmount(): void
    {
        $this->checkForDuplicate();
    }

    public function updatedPortfolioId(): void
    {
        $this->checkForDuplicate();
    }

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function resetForm(): void
    {
        $this->reset(['portfolio_id', 'type', 'amount', 'date', 'description', 'duplicateWarning']);
        $this->date = now()->toDateString();
        $this->amount = 0;
    }

    public function checkForDuplicate(): void
    {
        $this->duplicateWarning = null;

        if (! $this->portfolio_id || ! $this->amount || ! $this->date) {
            return;
        }

        $existing = CashFlow::where('portfolio_id', $this->portfolio_id)
            ->where('amount', $this->amount)
            ->whereDate('date', $this->date)
            ->first();

        if ($existing) {
            $this->duplicateWarning = [
                'amount' => $existing->amount,
                'date' => $existing->date->format('M j, Y'),
                'type' => $existing->type,
            ];
        }
    }

    public function save(): void
    {
        $this->validate();

        // Verify user owns this portfolio
        $portfolio = Portfolio::findOrFail($this->portfolio_id);
        if (! auth()->user()->can('fullAccess', $portfolio)) {
            $this->error(__('You do not have permission to manage this portfolio'));

            return;
        }

        CashFlow::create([
            'portfolio_id' => $this->portfolio_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => 'USD',
            'date' => $this->date,
            'description' => $this->description ?: ($this->type === 'DEPOSIT' ? 'Manual deposit' : 'Manual withdrawal'),
        ]);

        $this->success(__('Cash flow saved'));
        $this->dispatch('cash-flow-saved');
        $this->dispatch('toggle-add-cash-flow');
        $this->resetForm();
    }
}; ?>

<div>
    <form wire:submit="save" class="grid grid-flow-row auto-rows-min gap-3">
        <div class="space-y-4">
            {{-- Portfolio selector --}}
            <div>
                <label class="label"><span class="label-text font-medium">{{ __('Account') }}</span></label>
                <select wire:model.live="portfolio_id" class="select select-bordered w-full">
                    <option value="">{{ __('Select account...') }}</option>
                    @foreach(auth()->user()->portfolios as $portfolio)
                        <option value="{{ $portfolio->id }}">{{ $portfolio->title }}</option>
                    @endforeach
                </select>
                @error('portfolio_id') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>

            {{-- Type toggle --}}
            <div>
                <label class="label"><span class="label-text font-medium">{{ __('Type') }}</span></label>
                <div class="flex gap-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" wire:model="type" value="DEPOSIT" class="radio radio-sm radio-success" />
                        <span>{{ __('Deposit') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer ml-4">
                        <input type="radio" wire:model="type" value="WITHDRAWAL" class="radio radio-sm radio-warning" />
                        <span>{{ __('Withdrawal') }}</span>
                    </label>
                </div>
            </div>

            {{-- Amount --}}
            <div>
                <label class="label"><span class="label-text font-medium">{{ __('Amount') }}</span></label>
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    wire:model.live.debounce.500ms="amount"
                    class="input input-bordered w-full"
                    placeholder="0.00"
                />
                @error('amount') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>

            {{-- Date --}}
            <div>
                <label class="label"><span class="label-text font-medium">{{ __('Date') }}</span></label>
                <input
                    type="date"
                    wire:model.live="date"
                    class="input input-bordered w-full"
                    max="{{ now()->toDateString() }}"
                />
                @error('date') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>

            {{-- Note --}}
            <div>
                <label class="label"><span class="label-text font-medium">{{ __('Note (optional)') }}</span></label>
                <input
                    type="text"
                    wire:model="description"
                    class="input input-bordered w-full"
                    placeholder="{{ __('e.g., Monthly contribution') }}"
                />
            </div>

            {{-- Duplicate warning --}}
            @if($duplicateWarning)
                <div class="alert alert-warning text-sm">
                    <x-ui.icon name="o-exclamation-triangle" class="w-5 h-5" />
                    <span>
                        {{ __('Similar entry exists:') }}
                        {{ Number::currency($duplicateWarning['amount'], 'USD') }}
                        {{ strtolower($duplicateWarning['type']) }}
                        {{ __('on') }} {{ $duplicateWarning['date'] }}
                    </span>
                </div>
            @endif
        </div>

        <div class="flex justify-end gap-2 mt-6">
            <x-ui.button
                label="{{ __('Cancel') }}"
                class="btn-ghost"
                @click="$dispatch('toggle-add-cash-flow')"
            />
            <x-ui.button
                label="{{ __('Save Cash Flow') }}"
                class="btn-primary"
                type="submit"
                wire:loading.attr="disabled"
            />
        </div>
    </form>
</div>
