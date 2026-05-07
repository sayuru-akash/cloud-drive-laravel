<script setup lang="ts">
import { router } from '@inertiajs/vue3';

defineProps<{
    links: Array<{ url: string | null; label: string; active: boolean }>;
}>();

function label(value: string): string {
    return value
        .replace('&laquo; Previous', 'Previous')
        .replace('Next &raquo;', 'Next');
}

function visit(url: string | null): void {
    if (! url) {
        return;
    }

    router.visit(url, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination">
        <button
            v-for="link in links"
            :key="link.label"
            type="button"
            class="rounded-full border border-line px-3 py-1.5 text-sm transition disabled:cursor-not-allowed disabled:opacity-45"
            :class="
                link.active
                    ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                    : 'bg-white text-ink-700 hover:border-brand hover:text-ink-950 dark:bg-white/10 dark:text-white'
            "
            :disabled="link.url === null || link.active"
            @click="visit(link.url)"
        >
            {{ label(link.label) }}
        </button>
    </nav>
</template>
