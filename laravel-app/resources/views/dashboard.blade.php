<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:flex-row md:items-start md:p-6">
        <div class="w-full md:w-80 md:shrink-0">
            <livewire:pages::dashboard.upload />
        </div>

        <div class="min-w-0 flex-1">
            <livewire:pages::dashboard.video-list />
        </div>
    </div>
</x-layouts::app>
