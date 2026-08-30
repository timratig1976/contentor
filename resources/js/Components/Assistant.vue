<script setup>
import { ref, reactive, computed, nextTick } from 'vue';

const props = defineProps({ settings: Object });

const isOpen = ref(false);
const loading = ref(false);
const input = ref('');
const messages = ref([{ role: 'assistant', text: 'Hallo! Ich bin dein Content-Strategie-Assistant. Ich kann dir helfen, eine komplette Strategie zu erstellen, Personas zu definieren, oder Fragen zu Content-Marketing beantworten. Was möchtest du tun?' }]);
const chatContainer = ref(null);

const hasEdenAI = computed(() => !!props.settings?.llm_keys?.edenai_key);

async function send() {
    if (!input.value.trim() || loading.value) return;
    const msg = input.value;
    input.value = '';
    loading.value = true;

    messages.value.push({ role: 'user', text: msg });
    scrollToBottom();

    try {
        const res = await fetch('/api/assistant/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ message: msg, history: messages.value.map(m => ({ role: m.role, message: m.text })) }),
        });
        const data = await res.json();
        messages.value.push({ role: 'assistant', text: data.reply || 'Keine Antwort erhalten.' });
    } catch (e) {
        messages.value.push({ role: 'assistant', text: 'Fehler: ' + e.message });
    }
    loading.value = false;
    scrollToBottom();
}

function scrollToBottom() {
    nextTick(() => {
        if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    });
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        // Direkt senden, nicht auf await warten
        sendMessage();
    }
}

function sendMessage() {
    if (!input.value.trim() || loading.value) return;
    const msg = input.value;
    input.value = '';
    loading.value = true;

    messages.value.push({ role: 'user', text: msg });
    scrollToBottom();

    fetch('/api/assistant/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ message: msg, history: messages.value.map(m => ({ role: m.role, message: m.text })) }),
    })
    .then(res => res.json())
    .then(data => {
        messages.value.push({ role: 'assistant', text: data.reply || 'Keine Antwort erhalten.' });
    })
    .catch(e => {
        messages.value.push({ role: 'assistant', text: 'Fehler: ' + e.message });
    })
    .finally(() => {
        loading.value = false;
        scrollToBottom();
    });
}
</script>

<template>
  <!-- Toggle Button -->
  <button v-if="!isOpen" @click="isOpen = true"
    class="fixed bottom-6 right-6 z-50 w-14 h-14 bg-green-600 text-white rounded-full shadow-lg hover:bg-green-700 flex items-center justify-center text-2xl transition-all hover:scale-105">
    🤖
  </button>

  <!-- Assistant Panel -->
  <div v-if="isOpen" class="fixed bottom-0 right-0 w-96 h-[600px] bg-white border-l border-t border-gray-200 shadow-2xl z-50 flex flex-col rounded-tl-2xl overflow-hidden">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-gray-200 bg-gray-50">
      <div class="flex items-center gap-2">
        <span class="text-xl">🤖</span>
        <div>
          <h3 class="text-sm font-semibold text-gray-900">Assistant</h3>
          <p class="text-xs text-gray-500">Content-Strategie</p>
        </div>
      </div>
      <button @click="isOpen = false" class="text-gray-400 hover:text-gray-900">✕</button>
    </div>

    <!-- Chat -->
    <div ref="chatContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
      <div v-for="(msg, i) in messages" :key="i" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
        <div class="max-w-[85%] rounded-xl px-3 py-2 text-sm"
          :class="msg.role === 'user' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-900'">
          <p class="whitespace-pre-wrap">{{ msg.text }}</p>
        </div>
      </div>
      <div v-if="loading" class="flex justify-start">
        <div class="bg-gray-100 text-gray-900 rounded-xl px-3 py-2 text-sm">
          <span class="inline-flex gap-1"><span class="animate-bounce">.</span><span class="animate-bounce" style="animation-delay:0.1s">.</span><span class="animate-bounce" style="animation-delay:0.2s">.</span></span>
        </div>
      </div>
    </div>

    <!-- Input -->
    <div class="p-4 border-t border-gray-200">
      <div class="flex gap-2">
        <textarea v-model="input" @keydown="handleKey" rows="3"
          class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20 resize-none"
          placeholder="Frage oder Aufgabe eingeben..."></textarea>
        <div class="flex flex-col gap-2">
          <button @click="sendMessage" :disabled="loading || !input.trim()"
            class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
            Senden
          </button>
          <span class="text-xs text-gray-400 text-center">Enter</span>
        </div>
      </div>
    </div>
  </div>
</template>