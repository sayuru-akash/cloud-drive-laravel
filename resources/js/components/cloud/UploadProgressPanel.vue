<script setup lang="ts">
import {
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    CircleX,
    LoaderCircle,
    RefreshCw,
    UploadCloud,
    X,
} from 'lucide-vue-next';
import { computed } from 'vue';
import FileTypeIcon from '@/components/cloud/FileTypeIcon.vue';
import { useUploadManager } from '@/composables/useUploadManager';
import type { UploadQueueItem } from '@/composables/useUploadManager';
import { formatBytes } from '@/lib/format';

const {
    uploads,
    expanded,
    hasInProgress,
    canDismiss,
    aggregateProgress,
    summary,
    cancelUpload,
    retryUpload,
    removeUpload,
    dismissUploads,
    setExpanded,
} = useUploadManager();

const completedCount = computed(
    () => uploads.value.filter((upload) => upload.status === 'done').length,
);

function canCancel(upload: UploadQueueItem): boolean {
    return ['queued', 'preparing', 'uploading'].includes(upload.status);
}

function canRemove(upload: UploadQueueItem): boolean {
    return ['done', 'error', 'cancelled'].includes(upload.status);
}

function statusIcon(upload: UploadQueueItem) {
    if (upload.status === 'done') {
        return CheckCircle2;
    }

    if (upload.status === 'error' || upload.status === 'cancelled') {
        return CircleX;
    }

    return LoaderCircle;
}
</script>

<template>
    <aside
        v-if="uploads.length > 0"
        class="fixed right-3 bottom-3 z-50 w-[min(26rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl border border-line bg-surface-strong/96 text-sm shadow-[0_28px_80px_-30px_rgba(15,23,42,0.65)] backdrop-blur-xl sm:right-5 sm:bottom-5 dark:bg-ink-900/96"
        aria-label="Upload progress"
    >
        <header class="border-b border-line px-4 py-3.5">
            <div class="flex items-center gap-3">
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand"
                >
                    <UploadCloud class="h-4.5 w-4.5" />
                </span>
                <button
                    type="button"
                    class="min-w-0 flex-1 text-left"
                    :aria-expanded="expanded"
                    @click="setExpanded(!expanded)"
                >
                    <span
                        class="block font-semibold text-ink-950 dark:text-white"
                    >
                        {{
                            hasInProgress
                                ? 'Uploading files'
                                : 'Upload activity'
                        }}
                    </span>
                    <span
                        class="mt-0.5 block truncate text-xs text-ink-600 dark:text-ink-300"
                    >
                        {{ summary || `${completedCount} complete` }}
                    </span>
                </button>
                <button
                    type="button"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-600 transition hover:bg-ink-950/5 hover:text-ink-950 dark:text-ink-300 dark:hover:bg-white/10 dark:hover:text-white"
                    :title="expanded ? 'Collapse uploads' : 'Expand uploads'"
                    :aria-label="
                        expanded ? 'Collapse uploads' : 'Expand uploads'
                    "
                    @click="setExpanded(!expanded)"
                >
                    <ChevronDown v-if="expanded" class="h-4 w-4" />
                    <ChevronUp v-else class="h-4 w-4" />
                </button>
                <button
                    v-if="canDismiss"
                    type="button"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-600 transition hover:bg-ink-950/5 hover:text-ink-950 dark:text-ink-300 dark:hover:bg-white/10 dark:hover:text-white"
                    title="Close upload activity"
                    aria-label="Close upload activity"
                    @click="dismissUploads"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div
                class="mt-3 h-1.5 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10"
                role="progressbar"
                aria-label="Overall upload progress"
                :aria-valuenow="aggregateProgress"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-brand transition-[width] duration-200"
                    :style="{ width: `${aggregateProgress}%` }"
                />
            </div>
        </header>

        <div
            v-if="expanded"
            class="max-h-[min(28rem,58vh)] divide-y divide-line overflow-y-auto overscroll-contain"
        >
            <article
                v-for="upload in uploads"
                :key="upload.id"
                class="px-4 py-3.5"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <FileTypeIcon
                        :name="upload.name"
                        :mime-type="upload.mimeType"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="truncate font-medium text-ink-950 dark:text-white"
                                    :title="upload.name"
                                >
                                    {{ upload.name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-ink-600 dark:text-ink-300"
                                    :title="upload.message"
                                >
                                    {{ upload.message }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <component
                                    :is="statusIcon(upload)"
                                    class="h-4 w-4"
                                    :class="[
                                        upload.status === 'done'
                                            ? 'text-emerald-600'
                                            : upload.status === 'error' ||
                                                upload.status === 'cancelled'
                                              ? 'text-red-500'
                                              : 'animate-spin text-brand',
                                    ]"
                                />
                                <button
                                    v-if="canCancel(upload)"
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-ink-500 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                                    title="Cancel upload"
                                    :aria-label="`Cancel ${upload.name}`"
                                    @click="cancelUpload(upload.id)"
                                >
                                    <CircleX class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="upload.status === 'error'"
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-ink-500 transition hover:bg-brand-soft hover:text-brand"
                                    title="Retry upload"
                                    :aria-label="`Retry ${upload.name}`"
                                    @click="retryUpload(upload.id)"
                                >
                                    <RefreshCw class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="canRemove(upload)"
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-950/5 hover:text-ink-950 dark:hover:bg-white/10 dark:hover:text-white"
                                    title="Remove from activity"
                                    :aria-label="`Remove ${upload.name} from activity`"
                                    @click="removeUpload(upload.id)"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <div class="mt-2.5 flex items-center gap-3">
                            <div
                                class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10"
                                role="progressbar"
                                :aria-label="`${upload.name} upload progress`"
                                :aria-valuenow="upload.progress"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="h-full rounded-full transition-[width] duration-200"
                                    :class="
                                        upload.status === 'error' ||
                                        upload.status === 'cancelled'
                                            ? 'bg-red-500'
                                            : upload.status === 'done'
                                              ? 'bg-emerald-500'
                                              : 'bg-brand'
                                    "
                                    :style="{ width: `${upload.progress}%` }"
                                />
                            </div>
                            <span
                                class="dark:text-ink-200 w-9 text-right text-xs font-semibold text-ink-700 tabular-nums"
                            >
                                {{ upload.progress }}%
                            </span>
                        </div>
                        <p
                            class="mt-1.5 text-xs text-ink-500 tabular-nums dark:text-ink-300"
                        >
                            {{ formatBytes(upload.uploadedBytes) }} of
                            {{ formatBytes(upload.size) }}
                        </p>
                    </div>
                </div>
            </article>
        </div>

        <footer
            v-if="expanded && hasInProgress"
            class="border-t border-line bg-brand-soft/45 px-4 py-2.5 text-xs text-ink-600 dark:text-ink-300"
        >
            You can keep working in this tab. Closing or reloading it interrupts
            active uploads.
        </footer>
    </aside>
</template>
