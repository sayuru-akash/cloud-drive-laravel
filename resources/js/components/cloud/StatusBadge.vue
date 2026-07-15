<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value: string }>();

const classes: Record<string, string> = {
    private: 'bg-ink-950/8 text-ink-700 dark:bg-white/10 dark:text-white',
    workspace:
        'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    ready: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    pending:
        'bg-amber-50 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
    initiated:
        'bg-amber-50 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
    uploading: 'bg-sky-50 text-sky-800 dark:bg-sky-400/15 dark:text-sky-200',
    completed:
        'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    failed: 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    cancelled:
        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200',
    deleted: 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    active: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    revoked: 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    expired:
        'bg-amber-50 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
    unavailable:
        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200',
};

const label = computed(() =>
    props.value
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' '),
);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="classes[props.value] ?? classes.private"
    >
        {{ label }}
    </span>
</template>
