<x-filament-panels::page>
    <div class="flex flex-col h-[calc(100vh-12rem)]">

        {{-- Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-t-xl p-4 border-b flex items-center gap-3">
            <a href="{{ \App\Filament\Resources\ConversationResource::getUrl() }}"
               class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <x-heroicon-o-arrow-left class="w-5 h-5" />
            </a>
            <div class="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white font-bold">
                {{ strtoupper(substr($conversation->client_name ?? '?', 0, 1)) }}
            </div>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">
                    {{ $conversation->client_name ?? 'Sin nombre' }}
                </p>
                <p class="text-sm text-gray-500">
                    +{{ $conversation->phone_number }}
                </p>
            </div>
        </div>

        {{-- Messages area with polling --}}
        <div
            wire:poll.2s="loadMessages"
            class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50 dark:bg-gray-900"
            id="chat-messages"
        >
            @forelse ($messages as $message)
                <div class="flex {{ $message['direction'] === 'outbound' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[70%] px-4 py-2 rounded-2xl text-sm
                        {{ $message['direction'] === 'outbound'
                            ? 'bg-primary-500 text-white rounded-br-md'
                            : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white rounded-bl-md shadow-sm' }}">
                        <p>{{ $message['body'] }}</p>
                        <p class="text-[10px] mt-1 opacity-70">
                            {{ \Carbon\Carbon::parse($message['created_at'])->format('H:i') }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex items-center justify-center h-full text-gray-400">
                    <p>No hay mensajes aún</p>
                </div>
            @endforelse
        </div>

        {{-- Send input --}}
        <form wire:submit="sendMessage"
              class="bg-white dark:bg-gray-800 rounded-b-xl p-4 border-t flex gap-2">
            <input
                wire:model="newMessage"
                type="text"
                placeholder="Escribe un mensaje..."
                class="flex-1 rounded-full border-gray-300 dark:border-gray-600
                       dark:bg-gray-700 dark:text-white px-4 py-2 text-sm
                       focus:ring-primary-500 focus:border-primary-500"
            />
            <button type="submit"
                    class="bg-primary-500 hover:bg-primary-600 text-white
                           rounded-full px-4 py-2 text-sm font-medium
                           transition-colors">
                Enviar
            </button>
        </form>
    </div>

    {{-- Auto-scroll to bottom --}}
    <script>
        document.addEventListener('livewire:navigated', () => scrollToBottom());
        document.addEventListener('livewire:morph', () => scrollToBottom());

        function scrollToBottom() {
            const el = document.getElementById('chat-messages');
            if (el) el.scrollTop = el.scrollHeight;
        }
    </script>
</x-filament-panels::page>
