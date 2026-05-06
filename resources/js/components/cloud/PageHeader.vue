<script setup lang="ts">
defineProps<{
    kicker?: string;
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="cloud-panel p-6 md:p-8">
        <p v-if="kicker" class="text-xs font-semibold uppercase tracking-[0.22em] text-brand">{{ kicker }}</p>
        <div class="mt-3 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-ink-950 md:text-4xl dark:text-white">{{ title }}</h1>
                <p v-if="description" class="mt-3 max-w-2xl text-sm leading-7 text-ink-600 dark:text-ink-300">{{ description }}</p>
            </div>
            <slot name="actions" />
        </div>
    </header>
</template>
