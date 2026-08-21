<x-filament-widgets::widget>
    <x-filament::section heading="Fonctionnalités de l'instance">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($this->getFeatures() as $feature)
                <div class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    @if ($feature['available'])
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 shrink-0 text-success-500" />
                    @else
                        <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 shrink-0 text-warning-500" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $feature['name'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $feature['description'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
