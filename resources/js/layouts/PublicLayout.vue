<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';

const page = usePage();
</script>

<template>
    <div class="min-h-svh bg-background text-foreground">
        <a
            href="#main"
            class="sr-only z-50 rounded-md bg-foreground px-4 py-2 text-background focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >
            Skip to content
        </a>

        <header class="border-b border-line bg-background/95">
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-5 sm:px-8"
            >
                <Link href="/" class="flex items-center gap-2.5 font-semibold">
                    <BrandMark size="sm" />
                    <span class="sr-only sm:not-sr-only">Cloud Drive</span>
                </Link>

                <nav
                    class="hidden items-center gap-7 text-sm text-muted-foreground md:flex"
                    aria-label="Primary"
                >
                    <Link href="/#workflow" class="hover:text-foreground"
                        >Workflow</Link
                    >
                    <Link href="/#control" class="hover:text-foreground"
                        >Control</Link
                    >
                    <Link href="/#trust" class="hover:text-foreground"
                        >Trust</Link
                    >
                    <Link href="/privacy" class="hover:text-foreground"
                        >Privacy</Link
                    >
                    <Link href="/terms" class="hover:text-foreground"
                        >Terms</Link
                    >
                </nav>

                <div class="flex items-center gap-2">
                    <ThemeToggle />
                    <Link
                        :href="page.props.auth.user ? '/dashboard' : '/login'"
                        class="inline-flex h-10 items-center gap-2 rounded-md border border-line bg-card px-4 text-sm font-semibold shadow-sm transition hover:bg-muted"
                    >
                        {{ page.props.auth.user ? 'Workspace' : 'Log in' }}
                        <ArrowRight class="hidden h-4 w-4 sm:block" />
                    </Link>
                </div>
            </div>
        </header>

        <main id="main"><slot /></main>

        <footer class="border-t border-line">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-sm text-muted-foreground sm:px-8 md:flex-row md:items-center md:justify-between"
            >
                <p>Cloud Drive by Codezela Technologies</p>
                <nav class="flex flex-wrap gap-5" aria-label="Legal">
                    <Link href="/privacy" class="hover:text-foreground"
                        >Privacy</Link
                    >
                    <Link href="/terms" class="hover:text-foreground"
                        >Terms</Link
                    >
                    <Link href="/login" class="hover:text-foreground"
                        >Log in</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
