<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    FolderKanban,
    LayoutDashboard,
    Link2,
    LogOut,
    Settings2,
    Shield,
    Trash2,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandFooter from '@/components/BrandFooter.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import type { User } from '@/types';

const page = usePage();
const user = computed(
    () => page.props.auth?.user as User | null,
);
const canManageAdmin = computed(() =>
    ['admin', 'super_admin'].includes(user.value?.role ?? ''),
);
const currentPath = computed(() => page.url.split('?')[0]);

const navItems = computed(() =>
    [
        { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
        { href: '/files', label: 'Files', icon: FolderKanban },
        { href: '/shared', label: 'Shared', icon: Link2 },
        { href: '/deleted', label: 'Trash', icon: Trash2 },
        { href: '/settings/profile', label: 'Settings', icon: Settings2 },
        { href: '/admin', label: 'Admin', icon: Shield, adminOnly: true },
        { href: '/audit', label: 'Audit', icon: Activity, adminOnly: true },
    ].filter((item) => !item.adminOnly || canManageAdmin.value),
);

function signOut() {
    router.post('/logout');
}
</script>

<template>
    <Head>
        <meta
            head-key="robots"
            name="robots"
            content="noindex,nofollow,noarchive"
        />
    </Head>
    <div class="min-h-screen bg-background">
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-4 md:px-6 lg:flex-row lg:px-10"
        >
            <div
                class="mb-2 flex flex-col gap-4 rounded-[1.75rem] border border-line bg-white/78 p-4 shadow-[0_24px_80px_-52px_rgba(15,23,42,0.52)] backdrop-blur lg:hidden dark:bg-white/10"
            >
                <div class="flex items-center justify-between">
                    <Link href="/dashboard" class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950"
                        >
                            <AppLogoIcon class="h-8 w-8" />
                        </span>
                        <span
                            class="text-sm font-semibold text-ink-950 dark:text-white"
                            >Cloud Drive</span
                        >
                    </Link>
                    <div class="flex items-center gap-2">
                        <ThemeToggle />
                        <button
                            class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            title="Sign out"
                            aria-label="Sign out"
                            @click="signOut"
                        >
                            <LogOut class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm transition"
                        :class="
                            currentPath === item.href
                                ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                                : 'border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white'
                        "
                    >
                        <component :is="item.icon" class="h-4 w-4 text-brand" />
                        {{ item.label }}
                    </Link>
                </div>
            </div>

            <aside
                class="sticky top-4 hidden h-[calc(100vh-2rem)] w-72 shrink-0 flex-col rounded-[2rem] border border-line bg-white/78 p-5 shadow-[0_24px_80px_-52px_rgba(15,23,42,0.52)] backdrop-blur lg:flex dark:bg-white/10"
            >
                <Link href="/dashboard" class="flex items-center gap-3">
                    <span
                        class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950"
                    >
                        <AppLogoIcon class="h-9 w-9" />
                    </span>
                    <span>
                        <span
                            class="block text-sm font-semibold text-ink-950 dark:text-white"
                            >Cloud Drive</span
                        >
                        <span class="text-xs text-ink-600 dark:text-ink-300"
                            >Workspace files</span
                        >
                    </span>
                </Link>

                <nav class="mt-10 space-y-1">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center justify-between rounded-[1.25rem] px-4 py-3 text-sm transition"
                        :class="
                            currentPath === item.href
                                ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                                : 'text-ink-700 hover:bg-ink-950/5 hover:text-ink-950 dark:text-ink-300 dark:hover:bg-white/10 dark:hover:text-white'
                        "
                    >
                        <span class="flex items-center gap-3">
                            <component
                                :is="item.icon"
                                class="h-4 w-4 text-brand"
                            />
                            {{ item.label }}
                        </span>
                        <span
                            class="h-2 w-2 rounded-full"
                            :class="
                                currentPath === item.href
                                    ? 'bg-emerald-300'
                                    : 'bg-transparent'
                            "
                        />
                    </Link>
                </nav>

                <ThemeToggle class="mt-6" />

                <div
                    class="mt-auto rounded-[1.5rem] border border-line bg-white p-4 dark:bg-white/10"
                >
                    <p class="text-sm font-medium text-ink-950 dark:text-white">
                        {{ user?.name ?? 'Workspace user' }}
                    </p>
                    <p
                        class="mt-1 truncate text-sm text-ink-600 dark:text-ink-300"
                    >
                        {{ user?.email ?? 'Signed in session' }}
                    </p>
                    <p
                        class="mt-1 text-xs tracking-[0.18em] text-brand uppercase"
                    >
                        {{ user?.role ?? 'member' }}
                    </p>
                    <button
                        class="cloud-button mt-4 w-full bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                        @click="signOut"
                    >
                        <LogOut class="h-4 w-4" />
                        Sign out
                    </button>
                </div>
            </aside>

            <main class="min-w-0 flex-1">
                <slot />
                <BrandFooter class="mt-10 pb-2" />
            </main>
        </div>
    </div>
</template>
