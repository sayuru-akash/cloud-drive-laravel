<script setup lang="ts">
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' },
);

const frameClass = computed(
    () =>
        ({
            sm: 'h-9 w-9 rounded-xl',
            md: 'h-11 w-11 rounded-2xl',
            lg: 'h-14 w-14 rounded-2xl',
        })[props.size],
);

const iconClass = computed(
    () =>
        ({
            sm: 'h-7 w-7',
            md: 'h-9 w-9',
            lg: 'h-11 w-11',
        })[props.size],
);
</script>

<template>
    <span
        v-bind="$attrs"
        :class="[
            'flex shrink-0 items-center justify-center bg-white shadow-sm ring-1 ring-line dark:bg-ink-950',
            frameClass,
            $attrs.class,
        ]"
    >
        <AppLogoIcon :class-name="iconClass" />
    </span>
</template>
