<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';
import { Toaster as SonnerPrimitive } from 'vue-sonner';
import 'vue-sonner/style.css';

const { theme } = useTheme();
</script>

<template>
    <SonnerPrimitive
        :theme="theme"
        class="toaster group"
        position="bottom-right"
        :style="{
            '--normal-bg': 'var(--popover)',
            '--normal-text': 'var(--popover-foreground)',
            '--normal-border': 'var(--border)',
        }"
    />
</template>
