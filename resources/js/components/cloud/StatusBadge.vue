<script setup lang="ts">
const props = defineProps<{ value: string }>();

const classes: Record<string, string> = {
    private: 'bg-ink-950/8 text-ink-700 dark:bg-white/10 dark:text-white',
    workspace:
        'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    ready: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    pending:
        'bg-amber-50 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
    deleted: 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    active: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200',
    revoked: 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    expired:
        'bg-amber-50 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
    unavailable:
        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="classes[props.value] ?? classes.private"
    >
        {{ props.value }}
    </span>
</template>
