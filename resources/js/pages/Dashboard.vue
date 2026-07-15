<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    FileUp,
    FolderKanban,
    Link2,
    LoaderCircle,
    Trash2,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FileTypeIcon from '@/components/cloud/FileTypeIcon.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import {
    uploadsChangedEvent,
    useUploadManager,
} from '@/composables/useUploadManager';
import { formatFileType } from '@/lib/file-types';
import { formatBytes, formatDate } from '@/lib/format';

defineProps<{
    stats: { files: number; shares: number; trash: number; pending: number };
    recentFiles: Array<{
        id: string;
        display_name: string;
        mime_type: string | null;
        size_bytes: number;
        updated_at: string;
        visibility: string;
    }>;
    recentUploads: Array<{
        id: string;
        file_id: string;
        upload_status: string;
        display_name: string;
        mime_type: string | null;
        size_bytes: number;
        created_at: string;
        completed_at: string | null;
        can_cancel: boolean;
    }>;
}>();

const cancellingUploadId = ref<string | null>(null);
const dashboardRefreshProps = ['stats', 'recentFiles', 'recentUploads'];
const { uploads: localUploads, cancelUpload: cancelLocalUpload } =
    useUploadManager();
const localUploadsByFileId = computed(
    () =>
        new Map(
            localUploads.value
                .filter((upload) => upload.remoteFileId)
                .map((upload) => [upload.remoteFileId as string, upload]),
        ),
);

usePoll(10_000, { only: dashboardRefreshProps });

function localUploadFor(fileId: string) {
    return localUploadsByFileId.value.get(fileId);
}

function refreshDashboardUploads(): void {
    router.reload({ only: dashboardRefreshProps });
}

function csrfToken() {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function cancelUpload(upload: {
    id: string;
    file_id: string;
}): Promise<void> {
    cancellingUploadId.value = upload.id;
    const localUpload = localUploadFor(upload.file_id);

    if (
        localUpload &&
        ['queued', 'preparing', 'uploading'].includes(localUpload.status)
    ) {
        cancelLocalUpload(localUpload.id);
        window.setTimeout(() => {
            cancellingUploadId.value = null;
        }, 1000);

        return;
    }

    let refreshQueued = false;

    try {
        const response = await fetch(
            `/api/files/${upload.file_id}/cancel-upload`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            },
        );

        if (response.ok) {
            refreshQueued = true;
            router.reload({
                only: dashboardRefreshProps,
                onFinish: () => {
                    cancellingUploadId.value = null;
                },
            });
        }
    } finally {
        if (!refreshQueued) {
            cancellingUploadId.value = null;
        }
    }
}

onMounted(() => {
    window.addEventListener(uploadsChangedEvent, refreshDashboardUploads);
});

onBeforeUnmount(() => {
    window.removeEventListener(uploadsChangedEvent, refreshDashboardUploads);
});
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
                        <div class="flex min-w-0 items-center gap-3">
                            <FileTypeIcon
                                :name="file.display_name"
                                :mime-type="file.mime_type"
                            />
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
                                    {{
                                        formatFileType(
                                            file.display_name,
                                            file.mime_type,
                                        )
                                    }}
                                    ·
                                    {{ formatDate(file.updated_at) }}
                                </p>
                            </div>
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

            <div class="cloud-panel min-w-0 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2
                            class="text-lg font-semibold text-ink-950 dark:text-white"
                        >
                            Upload activity
                        </h2>
                        <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                            Latest 5 upload records.
                        </p>
                    </div>
                    <Link
                        href="/files"
                        class="shrink-0 text-sm font-medium text-brand"
                        >Files</Link
                    >
                </div>
                <div class="mt-4 space-y-2">
                    <div
                        v-for="upload in recentUploads"
                        :key="upload.id"
                        class="min-w-0 rounded-[1.25rem] border border-line bg-white/70 p-4 dark:bg-white/10"
                    >
                        <div
                            class="flex min-w-0 items-start justify-between gap-3"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <FileTypeIcon
                                    :name="upload.display_name"
                                    :mime-type="upload.mime_type"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-medium text-ink-950 dark:text-white"
                                    >
                                        {{ upload.display_name }}
                                    </p>
                                    <p
                                        class="mt-1 truncate text-xs text-ink-600 dark:text-ink-300"
                                    >
                                        {{ formatBytes(upload.size_bytes) }} ·
                                        {{
                                            formatFileType(
                                                upload.display_name,
                                                upload.mime_type,
                                            )
                                        }}
                                        ·
                                        {{
                                            upload.completed_at
                                                ? formatDate(
                                                      upload.completed_at,
                                                  )
                                                : formatDate(upload.created_at)
                                        }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <StatusBadge :value="upload.upload_status" />
                                <button
                                    v-if="upload.can_cancel"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-500/10"
                                    :disabled="cancellingUploadId === upload.id"
                                    @click="cancelUpload(upload)"
                                >
                                    <LoaderCircle
                                        v-if="cancellingUploadId === upload.id"
                                        class="h-3.5 w-3.5 animate-spin"
                                    />
                                    <Trash2 v-else class="h-3.5 w-3.5" />
                                    Cancel
                                </button>
                            </div>
                        </div>
                        <div v-if="localUploadFor(upload.file_id)" class="mt-3">
                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10"
                                role="progressbar"
                                :aria-label="`${upload.display_name} upload progress`"
                                :aria-valuenow="
                                    localUploadFor(upload.file_id)?.progress
                                "
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="h-full rounded-full bg-brand transition-[width] duration-200"
                                    :style="{
                                        width: `${localUploadFor(upload.file_id)?.progress ?? 0}%`,
                                    }"
                                />
                            </div>
                            <p
                                class="mt-1.5 text-xs text-ink-600 dark:text-ink-300"
                            >
                                {{ localUploadFor(upload.file_id)?.message }} ·
                                {{ localUploadFor(upload.file_id)?.progress }}%
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="recentUploads.length === 0"
                        class="rounded-[1.25rem] border border-dashed border-line px-4 py-8 text-center text-sm text-ink-600 dark:text-ink-300"
                    >
                        No upload activity.
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
