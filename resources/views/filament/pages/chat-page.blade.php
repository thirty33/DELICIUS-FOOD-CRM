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
                        @if ($message['type'] === 'template')
                            <p class="text-[10px] opacity-70 italic mb-1">Plantilla enviada</p>
                        @endif
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

        {{-- Send input (conditional on window status) --}}
        <div class="bg-white dark:bg-gray-800 rounded-b-xl p-4 border-t">
            @if ($this->windowStatus === \App\Enums\WindowStatus::Active)
                <form wire:submit="sendMessage" class="flex gap-2">
                    <input
                        wire:model="newMessage"
                        type="text"
                        placeholder="Escribe un mensaje..."
                        class="flex-1 rounded-full border-gray-300 dark:border-gray-600
                               dark:bg-gray-700 dark:text-white px-4 py-2 text-sm
                               focus:ring-primary-500 focus:border-primary-500"
                    />
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            wire:target="sendMessage"
                            class="bg-primary-500 hover:bg-primary-600 text-white
                                   rounded-full px-4 py-2 text-sm font-medium
                                   transition-colors">
                        <span wire:loading.remove wire:target="sendMessage">Enviar</span>
                        <span wire:loading wire:target="sendMessage">Enviando...</span>
                    </button>
                </form>
                @if ($this->windowExpiresAt)
                    <p class="text-[10px] text-gray-400 mt-1 text-right">
                        Ventana activa hasta: {{ $this->windowExpiresAt }}
                    </p>
                @endif
            @elseif ($this->windowStatus === \App\Enums\WindowStatus::AwaitingResponse)
                <div class="flex flex-col items-center gap-2 py-2">
                    <div class="flex items-center text-sm text-gray-500">
                        <svg class="animate-spin h-4 w-4 mr-2 text-warning-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Esperando respuesta del cliente...
                    </div>
                    <button wire:click="resendTemplate"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            wire:target="resendTemplate"
                            class="text-primary-500 hover:text-primary-600 text-sm font-medium
                                   transition-colors underline">
                        <span wire:loading.remove wire:target="resendTemplate">Reenviar plantilla</span>
                        <span wire:loading wire:target="resendTemplate">Enviando...</span>
                    </button>
                </div>
            @else
                <div class="flex flex-col items-center gap-2 py-2">
                    <p class="text-sm text-danger-600 dark:text-danger-400">
                        Ventana de 24h expirada
                    </p>
                    <button wire:click="resendTemplate"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            wire:target="resendTemplate"
                            class="bg-primary-500 hover:bg-primary-600 text-white
                                   rounded-full px-6 py-2 text-sm font-medium
                                   transition-colors">
                        <span wire:loading.remove wire:target="resendTemplate">Reenviar plantilla</span>
                        <span wire:loading wire:target="resendTemplate">Enviando...</span>
                    </button>
                </div>
            @endif
        </div>
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
