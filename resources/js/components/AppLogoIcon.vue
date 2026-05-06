<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" :class="className" v-bind="$attrs">
        <defs>
            <linearGradient id="cloud-drive-ring-app" x1="8" y1="52" x2="56" y2="12" gradientUnits="userSpaceOnUse">
                <stop stop-color="#197a68" />
                <stop offset="0.48" stop-color="#35c6a0" />
                <stop offset="1" stop-color="#7c3aed" />
            </linearGradient>
        </defs>
        <path fill="url(#cloud-drive-ring-app)" fill-rule="evenodd" d="M32 6a26 26 0 1 1 0 52 26 26 0 0 1 0-52Zm0 12a14 14 0 1 0 0 28 14 14 0 0 0 0-28Z" />
    </svg>
</template>
