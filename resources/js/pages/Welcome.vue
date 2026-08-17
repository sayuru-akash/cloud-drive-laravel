<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    FileArchive,
    FileText,
    FolderKanban,
    History,
    Link2,
    LockKeyhole,
    Search,
    ShieldCheck,
    UploadCloud,
} from 'lucide-vue-next';
import SeoHead from '@/components/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

const workflow = [
    ['01', 'Upload files or folders'],
    ['02', 'Organize and move'],
    ['03', 'Share when needed'],
    ['04', 'Recover or remove'],
];

const features = [
    {
        title: 'Direct uploads',
        body: 'Files and nested folders move from the browser to private object storage with visible progress and multipart handling.',
        icon: UploadCloud,
    },
    {
        title: 'Structured workspace',
        body: 'Search, folders, list and grid views, file previews, moves, and ownership rules keep daily file work predictable.',
        icon: FolderKanban,
    },
    {
        title: 'Controlled sharing',
        body: 'Create expiring download links for files or folders, copy them clearly, and revoke access from one place.',
        icon: Link2,
    },
    {
        title: 'Operational recovery',
        body: 'Trash retention, restore flows, upload cancellation, audit history, and admin policy controls cover work after upload.',
        icon: History,
    },
];

const safeguards = [
    'Private files are not exposed through permanent public object URLs.',
    'Signed upload, preview, and download URLs expire after a short period.',
    'Roles separate member, admin, and protected super-admin access.',
    'Public share pages do not expose private file metadata to crawlers.',
];
</script>

<template>
    <SeoHead
        title="Private Team File Workspace"
        description="Cloud Drive is a private team file workspace for direct uploads, nested folders, controlled sharing, previews, retention, audit logs, and admin access."
        path="/"
    />

    <PublicLayout>
        <section class="border-b border-line">
            <div class="mx-auto max-w-7xl px-5 pt-16 pb-14 sm:px-8 sm:pt-24">
                <div class="max-w-4xl">
                    <p class="mb-5 text-sm font-semibold text-brand">
                        Private workspace file management
                    </p>
                    <h1
                        class="text-5xl leading-[1.08] font-semibold sm:text-6xl lg:text-7xl"
                    >
                        Keep team files organized, controlled, and ready to
                        share.
                    </h1>
                    <p
                        class="mt-6 max-w-3xl text-lg leading-8 text-muted-foreground"
                    >
                        Cloud Drive brings direct uploads, nested folders,
                        previews, expiring share links, retention, audit
                        history, and role-based administration into one focused
                        workspace.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link
                            href="/login"
                            class="inline-flex h-11 items-center gap-2 rounded-md bg-foreground px-5 text-sm font-semibold text-background transition hover:opacity-90"
                        >
                            Log in <ArrowRight class="h-4 w-4" />
                        </Link>
                        <a
                            href="#workflow"
                            class="inline-flex h-11 items-center rounded-md border border-line bg-card px-5 text-sm font-semibold transition hover:bg-muted"
                        >
                            View workspace overview
                        </a>
                    </div>
                    <p class="mt-7 text-sm text-muted-foreground">
                        Built for Codezela Technologies employees with approved
                        access.
                    </p>
                </div>

                <div
                    class="mt-12 overflow-hidden rounded-lg border border-line bg-card shadow-xl shadow-black/5"
                    aria-label="Cloud Drive workspace preview"
                >
                    <div
                        class="flex items-center justify-between border-b border-line px-5 py-4"
                    >
                        <div>
                            <p class="font-semibold">Workspace files</p>
                            <p class="text-sm text-muted-foreground">
                                Team operations
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <div
                                class="hidden items-center gap-2 rounded-md border border-line px-3 py-2 text-sm text-muted-foreground sm:flex"
                            >
                                <Search class="h-4 w-4" /> Search files
                            </div>
                            <span
                                class="rounded-md bg-brand px-3 py-2 text-sm font-semibold text-white"
                                >Upload</span
                            >
                        </div>
                    </div>
                    <div class="grid lg:grid-cols-[220px_1fr]">
                        <div class="hidden border-r border-line p-5 lg:block">
                            <p
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                WORKSPACE
                            </p>
                            <div class="mt-4 space-y-1 text-sm">
                                <p
                                    class="rounded-md bg-brand-soft px-3 py-2 font-semibold text-brand"
                                >
                                    All files
                                </p>
                                <p class="px-3 py-2 text-muted-foreground">
                                    Shared links
                                </p>
                                <p class="px-3 py-2 text-muted-foreground">
                                    Trash
                                </p>
                            </div>
                        </div>
                        <div class="min-w-0">
                            <div
                                class="grid grid-cols-[1fr_auto] border-b border-line px-5 py-3 text-xs font-semibold text-muted-foreground sm:grid-cols-[1fr_140px_120px]"
                            >
                                <span>Name</span
                                ><span class="hidden sm:block">Access</span
                                ><span>Updated</span>
                            </div>
                            <div
                                class="grid grid-cols-[1fr_auto] items-center gap-3 border-b border-line px-5 py-4 sm:grid-cols-[1fr_140px_120px]"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <FolderKanban
                                        class="h-5 w-5 shrink-0 text-brand"
                                    /><span class="truncate font-medium"
                                        >Brand library</span
                                    >
                                </div>
                                <span
                                    class="hidden text-sm text-muted-foreground sm:block"
                                    >Workspace</span
                                ><span class="text-sm text-muted-foreground"
                                    >Today</span
                                >
                            </div>
                            <div
                                class="grid grid-cols-[1fr_auto] items-center gap-3 border-b border-line px-5 py-4 sm:grid-cols-[1fr_140px_120px]"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <FileText
                                        class="h-5 w-5 shrink-0 text-brand"
                                    /><span class="truncate font-medium"
                                        >Operations handbook.pdf</span
                                    >
                                </div>
                                <span
                                    class="hidden text-sm text-muted-foreground sm:block"
                                    >Private</span
                                ><span class="text-sm text-muted-foreground"
                                    >Yesterday</span
                                >
                            </div>
                            <div
                                class="grid grid-cols-[1fr_auto] items-center gap-3 px-5 py-4 sm:grid-cols-[1fr_140px_120px]"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <FileArchive
                                        class="h-5 w-5 shrink-0 text-brand"
                                    /><span class="truncate font-medium"
                                        >Campaign assets.zip</span
                                    >
                                </div>
                                <span
                                    class="hidden text-sm text-muted-foreground sm:block"
                                    >Private</span
                                ><span class="text-sm text-muted-foreground"
                                    >Jul 16</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="workflow" class="border-b border-line bg-muted/30">
            <div
                class="mx-auto grid max-w-7xl gap-2 px-5 py-10 sm:grid-cols-2 sm:px-8 lg:grid-cols-4"
            >
                <div
                    v-for="item in workflow"
                    :key="item[0]"
                    class="rounded-lg border border-line bg-card p-5"
                >
                    <p class="text-xs text-muted-foreground">{{ item[0] }}</p>
                    <p class="mt-4 font-medium">{{ item[1] }}</p>
                </div>
            </div>
        </section>

        <section id="control" class="border-b border-line">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <h2 class="text-3xl font-semibold sm:text-4xl">
                    The full file workflow in one place.
                </h2>
                <p class="mt-4 max-w-2xl text-lg text-muted-foreground">
                    Keep file operations close to the permissions, upload state,
                    and storage policy that govern them.
                </p>
                <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-lg border border-line bg-card p-6"
                    >
                        <component
                            :is="feature.icon"
                            class="h-5 w-5 text-brand"
                        />
                        <h3 class="mt-8 text-lg font-semibold">
                            {{ feature.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-muted-foreground">
                            {{ feature.body }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section id="trust" class="border-b border-line bg-muted/30">
            <div
                class="mx-auto grid max-w-7xl gap-12 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[0.8fr_1.2fr] lg:items-start"
            >
                <div>
                    <ShieldCheck class="h-7 w-7 text-brand" />
                    <h2 class="mt-6 text-3xl font-semibold sm:text-4xl">
                        Access and recovery are part of the design.
                    </h2>
                    <p class="mt-4 text-lg leading-8 text-muted-foreground">
                        Cloud Drive treats storage as an operational system, not
                        a public folder with permanent links.
                    </p>
                </div>
                <div
                    class="divide-y divide-line rounded-lg border border-line bg-card"
                >
                    <div
                        v-for="safeguard in safeguards"
                        :key="safeguard"
                        class="flex gap-3 p-5"
                    >
                        <CheckCircle2
                            class="mt-0.5 h-5 w-5 shrink-0 text-brand"
                        />
                        <p class="text-sm leading-6">{{ safeguard }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <div
                    class="flex flex-col gap-8 rounded-lg border border-line bg-card p-7 sm:p-10 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="max-w-2xl">
                        <LockKeyhole class="h-6 w-6 text-brand" />
                        <h2 class="mt-6 text-3xl font-semibold">
                            Bring file work back under control.
                        </h2>
                        <p class="mt-3 text-muted-foreground">
                            Upload, organize, preview, share, recover, and audit
                            from one private workspace.
                        </p>
                    </div>
                    <Link
                        href="/login"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-foreground px-5 text-sm font-semibold text-background"
                        >Log in to Cloud Drive <ArrowRight class="h-4 w-4"
                    /></Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
