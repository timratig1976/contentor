<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Assistant from '../Components/Assistant.vue';

const page = usePage();

const activeCampaign = ref('viscale');
const campaigns = computed(() => page.props.strategies || []);

function switchCampaign(key) {
    activeCampaign.value = key;
    router.get(page.url, { strategy: key }, { preserveState: true, preserveScroll: true });
}

const navSections = [
    {
        title: 'Arbeit',
        items: [
            { name: 'Dashboard', href: '/', icon: '📊' },
            { name: 'Quick Input', href: '/quick-input', icon: '⚡' },
            { name: 'Angles', href: '/angles', icon: '🎯' },
            { name: 'Quellen', href: '/quellen', icon: '📄' },
            { name: 'Redaktionsplan', href: '/redaktionsplan', icon: '📋' },
            { name: 'Output', href: '/output', icon: '📤' },
        ],
    },
    {
        title: 'Konfiguration',
        items: [
            { name: 'Strategie', href: '/strategie', icon: '🧠' },
            { name: 'Personas', href: '/personas', icon: '👤' },
        ],
    },
];

const bottomNav = [
    { name: 'Agents', href: '/agents', icon: '🤖' },
    { name: 'Einstellungen', href: '/einstellungen', icon: '⚙️' },
];
</script>

<template>
    <div class="min-h-screen" style="background: #f0f2f5;">
        <aside class="fixed inset-y-0 left-0 w-64 flex flex-col bg-white" style="border-right: 1px solid #e5e7eb;">
            <!-- Logo -->
            <div class="p-4" style="border-bottom: 1px solid #e5e7eb;">
                <h1 class="text-lg font-semibold text-gray-900 tracking-tight">Contentor</h1>
                <p class="text-xs text-gray-400 mt-0.5">Content System</p>
            </div>

            <!-- Campaign Selector -->
            <div class="p-4" style="border-bottom: 1px solid #e5e7eb;">
                <select v-model="activeCampaign" @change="switchCampaign(activeCampaign)"
                    class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-medium focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                    <option v-for="c in campaigns" :key="c.key" :value="c.key">{{ c.name }}</option>
                </select>
            </div>

            <nav class="flex-1 p-4 overflow-y-auto">
                <div v-for="section in navSections" :key="section.title" class="mb-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-3">{{ section.title }}</p>
                    <Link v-for="item in section.items" :key="item.href" :href="item.href"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors mb-0.5"
                        :class="page.url === item.href || (page.url.startsWith(item.href) && item.href !== '/')
                            ? 'bg-gray-100 text-gray-900 font-medium'
                            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'">
                        <span class="text-gray-400">{{ item.icon }}</span>
                        {{ item.name }}
                    </Link>
                </div>
            </nav>

            <div class="p-4" style="border-top: 1px solid #e5e7eb;">
                <Link v-for="item in bottomNav" :key="item.href" :href="item.href"
                    class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors"
                    :class="page.url === item.href || page.url.startsWith(item.href)
                        ? 'bg-gray-100 text-gray-900 font-medium'
                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'">
                    <span class="text-gray-400">{{ item.icon }}</span>
                    {{ item.name }}
                </Link>
                <div class="text-xs text-gray-300 pt-2">Contentor v1.0</div>
            </div>
        </aside>

        <main class="ml-64 min-h-screen p-6" style="background: #f0f2f5;">
            <slot />
        </main>

        <Assistant :settings="page.props.settings" />
    </div>
</template>