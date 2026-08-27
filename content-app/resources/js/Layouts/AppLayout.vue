<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const nav = [
    { name: 'Dashboard', href: '/', icon: '📊' },
    { name: 'Quellen', href: '/quellen', icon: '📄' },
    { name: 'Angles', href: '/angles', icon: '🎯' },
    { name: 'Redaktionsplan', href: '/redaktionsplan', icon: '📋' },
    { name: 'Strategie', href: '/strategie', icon: '🧠' },
    { name: 'Medien', href: '/medien', icon: '🖼️' },
    { name: 'Newsletter', href: '/newsletter', icon: '📧' },
    { name: 'Einstellungen', href: '/einstellungen', icon: '⚙️' },
];
</script>

<template>
    <div class="min-h-screen bg-gray-950">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 w-64 bg-gray-900 border-r border-gray-800 flex flex-col">
            <div class="p-6 border-b border-gray-800">
                <h1 class="text-xl font-bold text-white">Content App</h1>
                <p class="text-xs text-gray-400 mt-1">viminds Content System</p>
            </div>
            <nav class="flex-1 p-4 space-y-1">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors"
                    :class="$page.url === item.href || ($page.url.startsWith(item.href) && item.href !== '/')
                        ? 'bg-indigo-600/20 text-indigo-400'
                        : 'text-gray-400 hover:text-white hover:bg-gray-800'"
                >
                    <span class="text-lg">{{ item.icon }}</span>
                    {{ item.name }}
                </Link>
            </nav>
            <div class="p-4 border-t border-gray-800 text-xs text-gray-500">
                Content App v1.0
            </div>
        </aside>

        <!-- Main Content -->
        <main class="ml-64 p-8">
            <slot />
        </main>
    </div>
</template>
