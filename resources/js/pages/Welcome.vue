<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Lock, ShieldCheck, UploadCloud } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandFooter from '@/components/BrandFooter.vue';
import SeoHead from '@/components/SeoHead.vue';

defineProps<{ canRegister: boolean }>();
</script>

<template>
    <SeoHead
        title="Cloud Drive"
        description="Private team file management with direct Backblaze B2 uploads, download-only share links, retention, audit logs, and admin controls."
        path="/"
    />
    <main
        class="min-h-screen bg-background px-4 py-6 text-ink-950 dark:text-white"
    >
        <div class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-6xl flex-col">
            <header class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950"
                    >
                        <AppLogoIcon class="h-9 w-9" />
                    </span>
                    <span class="font-semibold">Cloud Drive</span>
                </div>
                <nav class="flex gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        href="/dashboard"
                        class="cloud-button border border-line bg-white/80"
                        >Dashboard</Link
                    >
                    <template v-else>
                        <Link
                            href="/login"
                            class="cloud-button border border-line bg-white/80 dark:bg-white/10"
                            >Log in</Link
                        >
                        <Link
                            v-if="canRegister"
                            href="/register"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                            >Create account</Link
                        >
                    </template>
                </nav>
            </header>
            <section
                class="grid flex-1 items-center gap-10 py-16 lg:grid-cols-[.95fr_1.05fr]"
            >
                <div>
                    <h1
                        class="max-w-3xl text-5xl font-semibold tracking-tight md:text-7xl"
                    >
                        Private team files, cleanly handled.
                    </h1>
                    <p
                        class="mt-6 max-w-xl text-lg leading-8 text-ink-600 dark:text-ink-300"
                    >
                        Direct uploads, download-only shares, trash retention,
                        audit logs, and admin controls in one quiet workspace.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link
                            href="/files"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                        >
                            Open workspace
                            <ArrowRight class="h-4 w-4" />
                        </Link>
                        <Link
                            href="/privacy"
                            class="cloud-button border border-line bg-white/70 dark:bg-white/10"
                            >Privacy</Link
                        >
                    </div>
                </div>
                <div class="cloud-panel p-4 md:p-6">
                    <div
                        class="rounded-[1.5rem] bg-white/80 p-5 dark:bg-white/10"
                    >
                        <div class="flex items-center justify-between">
                            <p class="font-semibold">Upload queue</p>
                            <span
                                class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800"
                                >Direct to B2</span
                            >
                        </div>
                        <div class="mt-6 space-y-3">
                            <div
                                v-for="item in [
                                    'brand-assets.zip',
                                    'student-records.csv',
                                    'policy.pdf',
                                ]"
                                :key="item"
                                class="flex items-center gap-3 rounded-[1.25rem] border border-line bg-white p-4 dark:bg-white/10"
                            >
                                <UploadCloud class="h-5 w-5 text-brand" />
                                <div class="flex-1">
                                    <p class="text-sm font-medium">
                                        {{ item }}
                                    </p>
                                    <div
                                        class="mt-2 h-2 rounded-full bg-ink-950/10"
                                    >
                                        <div
                                            class="h-2 rounded-full bg-brand"
                                            :style="{
                                                width:
                                                    item === 'policy.pdf'
                                                        ? '58%'
                                                        : '100%',
                                            }"
                                        />
                                    </div>
                                </div>
                                <ShieldCheck class="h-5 w-5 text-brand" />
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div
                            class="rounded-[1.25rem] bg-white/70 p-4 dark:bg-white/10"
                        >
                            <Lock class="h-5 w-5 text-brand" />
                            <p class="mt-4 text-sm font-semibold">
                                Private by default
                            </p>
                        </div>
                        <div
                            class="rounded-[1.25rem] bg-white/70 p-4 dark:bg-white/10"
                        >
                            <ShieldCheck class="h-5 w-5 text-brand" />
                            <p class="mt-4 text-sm font-semibold">
                                Audited actions
                            </p>
                        </div>
                        <div
                            class="rounded-[1.25rem] bg-white/70 p-4 dark:bg-white/10"
                        >
                            <ArrowRight class="h-5 w-5 text-brand" />
                            <p class="mt-4 text-sm font-semibold">
                                Download links
                            </p>
                        </div>
                    </div>
                </div>
            </section>
            <BrandFooter class="pb-2" />
        </div>
    </main>
</template>
