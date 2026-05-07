<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandFooter from '@/components/BrandFooter.vue';
import SeoHead from '@/components/SeoHead.vue';
</script>

<template>
    <SeoHead
        title="Privacy"
        description="How Cloud Drive handles metadata, direct Backblaze B2 file transfers, signed download links, and audit logs."
        path="/privacy"
    />
    <main class="flex min-h-screen flex-col bg-background px-4 py-8">
        <article class="cloud-panel mx-auto max-w-3xl p-8">
            <Link
                href="/"
                class="inline-flex items-center gap-3 text-sm font-medium text-ink-950 dark:text-white"
            >
                <span
                    class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950"
                >
                    <AppLogoIcon class="h-8 w-8" />
                </span>
                Cloud Drive
            </Link>
            <h1
                class="mt-6 text-4xl font-semibold tracking-tight text-ink-950 dark:text-white"
            >
                Privacy
            </h1>
            <div
                class="mt-6 space-y-4 text-sm leading-7 text-ink-700 dark:text-ink-300"
            >
                <p>
                    Cloud Drive stores file metadata in PostgreSQL and file
                    bytes in a private Backblaze B2 bucket.
                </p>
                <p>
                    Uploads go directly from the browser to storage with
                    short-lived signed URLs. Downloads use short-lived signed
                    URLs and attachment headers.
                </p>
                <p>
                    Every file, folder, share, and admin action is written to
                    the audit log for operational visibility.
                </p>
            </div>
        </article>
        <BrandFooter class="mx-auto mt-auto max-w-3xl pt-8" />
    </main>
</template>
