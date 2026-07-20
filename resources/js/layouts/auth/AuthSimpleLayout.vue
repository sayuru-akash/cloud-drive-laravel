<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BrandFooter from '@/components/BrandFooter.vue';
import BrandMark from '@/components/BrandMark.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <Head>
        <meta
            head-key="robots"
            name="robots"
            content="noindex,nofollow,noarchive"
        />
    </Head>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <BrandMark class="mb-1" size="md" />
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
                <BrandFooter />
            </div>
        </div>
    </div>
</template>
