@php
    $result = $this->getResult();
    $checks = $result['checks'] ?? [];
    $problems = collect($checks)->where('ok', false)->count();
@endphp
<x-filament-widgets::widget>
    <x-filament::section
        heading="État du système"
        :description="$result
            ? ($problems ? $problems.' point(s) à vérifier' : 'Tout est au vert').' — vérifié '.$this->getCheckedAgo()
            : 'Pas encore vérifié'"
    >
        <x-slot name="afterHeader">
            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="runNow" wire:loading.attr="disabled">
                Vérifier maintenant
            </x-filament::button>
        </x-slot>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($checks as $check)
                <div class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    @if ($check['ok'])
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 shrink-0 text-success-500" />
                    @else
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $check['label'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
