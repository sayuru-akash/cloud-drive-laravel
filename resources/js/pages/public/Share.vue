<script setup lang="ts">
import { Download } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandFooter from '@/components/BrandFooter.vue';
import SeoHead from '@/components/SeoHead.vue';
import { formatBytes } from '@/lib/format';

defineProps<{ available: boolean; file: { display_name: string; size_bytes: number } | null; downloadUrl: string | null }>();
</script>

<template>
    <SeoHead title="Shared file" description="Secure download-only Cloud Drive share link." noindex />
    <main class="flex min-h-screen flex-col bg-background px-4 py-8">
        <div class="grid flex-1 place-items-center">
            <section class="cloud-panel w-full max-w-xl p-8 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950">
                    <AppLogoIcon class="h-11 w-11" />
                </div>
                <template v-if="available && file">
                    <h1 class="mt-6 text-3xl font-semibold tracking-tight">{{ file.display_name }}</h1>
                    <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">{{ formatBytes(file.size_bytes) }} · download-only share</p>
                    <a :href="downloadUrl ?? '#'" class="cloud-button mt-6 bg-ink-950 text-white dark:bg-white dark:text-ink-950">
                        <Download class="h-4 w-4" />
                        Download
                    </a>
                </template>
                <template v-else>
                    <h1 class="mt-6 text-3xl font-semibold tracking-tight">Link unavailable</h1>
                    <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">This share may be expired, revoked, or deleted.</p>
                </template>
            </section>
        </div>
        <BrandFooter />
    </main>
</template>
