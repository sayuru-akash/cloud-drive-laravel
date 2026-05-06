<script setup lang="ts">
import { Heart } from 'lucide-vue-next';
</script>

<template>
    <footer
        class="flex flex-wrap items-center justify-center gap-1.5 text-xs text-ink-600 dark:text-ink-300"
    >
        <span>Developed with</span>
        <Heart
            class="h-3.5 w-3.5 fill-rose-500 text-rose-500"
            aria-label="love"
        />
        <span>by</span>
        <a
            href="https://codezela.com"
            target="_blank"
            rel="noopener noreferrer"
            class="font-semibold text-brand underline-offset-4 hover:underline"
        >
            Codezela Technologies
        </a>
    </footer>
</template>
