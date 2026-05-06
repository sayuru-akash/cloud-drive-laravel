<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileUp, FolderKanban, Link2, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { formatBytes, formatDate } from '@/lib/format';

defineProps<{
    stats: { files: number; shares: number; trash: number; pending: number };
    recentFiles: Array<{
        id: string;
        display_name: string;
        size_bytes: number;
        updated_at: string;
        visibility: string;
    }>;
    recentUploads: Array<{
        id: string;
        upload_status: string;
        created_at: string;
        file?: { display_name: string };
    }>;
}>();
</script>

<template>
    <Head title="Dashboard" />
    <div class="space-y-6">
        <PageHeader
            title="Workspace"
            description="A calm overview of files, shared links, trash, and active upload work."
        >
            <template #actions>
                <Link
                    href="/files"
                    class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                >
                    <FileUp class="h-4 w-4" />
                    Upload
                </Link>
            </template>
        </PageHeader>

        <section class="grid gap-4 md:grid-cols-4">
            <Link
                v-for="card in [
                    {
                        label: 'Files',
                        value: stats.files,
                        href: '/files',
                        icon: FolderKanban,
                    },
                    {
                        label: 'Links',
                        value: stats.shares,
                        href: '/shared',
                        icon: Link2,
                    },
                    {
                        label: 'Trash',
                        value: stats.trash,
                        href: '/deleted',
                        icon: Trash2,
                    },
                    {
                        label: 'Pending',
                        value: stats.pending,
                        href: '/files',
                        icon: FileUp,
                    },
                ]"
                :key="card.label"
                :href="card.href"
                class="cloud-panel block p-5 transition hover:-translate-y-0.5"
            >
                <component :is="card.icon" class="h-5 w-5 text-brand" />
                <p
                    class="mt-6 text-3xl font-semibold text-ink-950 dark:text-white"
                >
                    {{ card.value }}
                </p>
                <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                    {{ card.label }}
                </p>
            </Link>
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.3fr_.7fr]">
            <div class="cloud-panel p-5">
                <div class="flex items-center justify-between">
                    <h2
                        class="text-lg font-semibold text-ink-950 dark:text-white"
                    >
                        Recent files
                    </h2>
                    <Link href="/files" class="text-sm font-medium text-brand"
                        >View all</Link
                    >
                </div>
                <div class="mt-4 divide-y divide-line">
                    <div
                        v-for="file in recentFiles"
                        :key="file.id"
                        class="flex items-center justify-between gap-4 py-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-medium text-ink-950 dark:text-white"
                            >
                                {{ file.display_name }}
                            </p>
                            <p
                                class="mt-1 text-xs text-ink-600 dark:text-ink-300"
                            >
                                {{ formatBytes(file.size_bytes) }} ·
                                {{ formatDate(file.updated_at) }}
                            </p>
                        </div>
                        <StatusBadge :value="file.visibility" />
                    </div>
                    <p
                        v-if="recentFiles.length === 0"
                        class="py-8 text-sm text-ink-600 dark:text-ink-300"
                    >
                        No files yet.
                    </p>
                </div>
            </div>

            <div class="cloud-panel p-5">
                <h2 class="text-lg font-semibold text-ink-950 dark:text-white">
                    Upload activity
                </h2>
                <div class="mt-4 space-y-3">
                    <div
                        v-for="upload in recentUploads"
                        :key="upload.id"
                        class="rounded-[1.25rem] border border-line bg-white/70 p-4 dark:bg-white/10"
                    >
                        <p
                            class="truncate text-sm font-medium text-ink-950 dark:text-white"
                        >
                            {{ upload.file?.display_name ?? 'Upload' }}
                        </p>
                        <div class="mt-2 flex items-center justify-between">
                            <StatusBadge :value="upload.upload_status" />
                            <span
                                class="text-xs text-ink-600 dark:text-ink-300"
                                >{{ formatDate(upload.created_at) }}</span
                            >
                        </div>
                    </div>
                    <p
                        v-if="recentUploads.length === 0"
                        class="text-sm text-ink-600 dark:text-ink-300"
                    >
                        No upload activity.
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
