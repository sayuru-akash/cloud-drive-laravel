<script setup lang="ts">
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { useTheme } from '@/composables/useTheme';

const { theme, updateThemePreference } = useTheme();

const options = [
    { value: 'light', Icon: Sun, label: 'Light theme' },
    { value: 'dark', Icon: Moon, label: 'Dark theme' },
    { value: 'system', Icon: Monitor, label: 'Use system theme' },
] as const;
</script>

<template>
    <div
        class="inline-flex rounded-full border border-line bg-white/82 p-1 shadow-sm dark:bg-white/10"
        aria-label="Theme"
    >
        <button
            v-for="{ value, Icon, label } in options"
            :key="value"
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-full transition"
            :class="
                theme === value
                    ? 'bg-ink-950 text-white shadow-sm dark:bg-white dark:text-ink-950'
                    : 'text-ink-600 hover:bg-ink-950/5 hover:text-ink-950 dark:text-ink-300 dark:hover:bg-white/10 dark:hover:text-white'
            "
            :aria-label="label"
            :aria-pressed="theme === value"
            :title="label"
            @click="updateThemePreference(value)"
        >
            <component :is="Icon" class="h-4 w-4" />
        </button>
    </div>
</template>
