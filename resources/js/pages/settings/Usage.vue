<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Files,
    FolderKanban,
    HardDrive,
    Info,
    Trash2,
    UploadCloud,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import FileTypeIcon from '@/components/cloud/FileTypeIcon.vue';
import Heading from '@/components/Heading.vue';
import { uploadsChangedEvent } from '@/composables/useUploadManager';
import { formatBytes, formatDate } from '@/lib/format';
import { index as usageIndex } from '@/routes/usage';

type Usage = {
    scope: 'workspace' | 'personal';
    storedBytes: number;
    activeBytes: number;
    trashBytes: number;
    activeFiles: number;
    activeFolders: number;
    trashItems: number;
    activeUploadBytes: number;
    largestFiles: Array<{
        id: string;
        display_name: string;
        mime_type: string | null;
        size_bytes: number;
        updated_at: string;
    }>;
};

const props = defineProps<{
    usage: Usage;
    policy: {
        maxUploadSizeBytes: number;
        multipartThresholdBytes: number;
        multipartChunkSizeBytes: number;
        parallelFileUploads: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Usage',
                href: usageIndex(),
            },
        ],
    },
});

const activeShare = computed(() => {
    if (props.usage.storedBytes === 0) {
        return 0;
    }

    return (props.usage.activeBytes / props.usage.storedBytes) * 100;
});
const trashShare = computed(() =>
    props.usage.storedBytes === 0 ? 0 : 100 - activeShare.value,
);

function refreshUsage(): void {
    router.reload({ only: ['usage'] });
}

onMounted(() => {
    window.addEventListener(uploadsChangedEvent, refreshUsage);
});

onBeforeUnmount(() => {
    window.removeEventListener(uploadsChangedEvent, refreshUsage);
});
</script>

<template>
    <Head title="Storage usage" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Storage usage"
            :description="
                usage.scope === 'workspace'
                    ? 'Current storage across the whole workspace'
                    : 'Storage used by files you own'
            "
        />

        <section class="cloud-panel overflow-hidden">
            <div class="p-5 sm:p-6">
                <div
                    class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <div class="flex items-center gap-2 text-brand">
                            <HardDrive class="h-4 w-4" />
                            <span class="text-xs font-semibold uppercase"
                                >Stored data</span
                            >
                        </div>
                        <p
                            class="mt-3 text-3xl font-semibold text-ink-950 tabular-nums dark:text-white"
                        >
                            {{ formatBytes(usage.storedBytes) }}
                        </p>
                        <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                            Active files and retained trash in private object
                            storage.
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-line bg-white/70 px-4 py-3 text-sm dark:bg-white/10"
                    >
                        <p class="font-medium text-ink-950 dark:text-white">
                            No workspace quota
                        </p>
                        <p class="mt-1 text-xs text-ink-600 dark:text-ink-300">
                            {{ formatBytes(policy.maxUploadSizeBytes) }} maximum
                            per file
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 flex h-2 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10"
                    aria-label="Active and trash storage distribution"
                >
                    <div
                        class="h-full bg-brand transition-[width] duration-500"
                        :style="{ width: `${activeShare}%` }"
                    />
                    <div
                        class="h-full bg-amber-400 transition-[width] duration-500"
                        :style="{ width: `${trashShare}%` }"
                    />
                </div>
                <div
                    class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-ink-600 dark:text-ink-300"
                >
                    <span class="inline-flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-brand" />
                        Active {{ formatBytes(usage.activeBytes) }}
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-amber-400" />
                        Trash {{ formatBytes(usage.trashBytes) }}
                    </span>
                </div>
            </div>

            <div
                class="grid gap-px border-t border-line bg-line sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="metric in [
                        {
                            label: 'Files',
                            value: usage.activeFiles,
                            icon: Files,
                        },
                        {
                            label: 'Folders',
                            value: usage.activeFolders,
                            icon: FolderKanban,
                        },
                        {
                            label: 'Trash items',
                            value: usage.trashItems,
                            icon: Trash2,
                        },
                        {
                            label: 'Uploading',
                            value: formatBytes(usage.activeUploadBytes),
                            icon: UploadCloud,
                        },
                    ]"
                    :key="metric.label"
                    class="bg-surface-strong p-4 dark:bg-ink-900"
                >
                    <component :is="metric.icon" class="h-4 w-4 text-brand" />
                    <p
                        class="mt-3 text-xl font-semibold text-ink-950 tabular-nums dark:text-white"
                    >
                        {{ metric.value }}
                    </p>
                    <p class="mt-1 text-xs text-ink-600 dark:text-ink-300">
                        {{ metric.label }}
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-[1.2fr_.8fr]">
            <div class="min-w-0">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-ink-950 dark:text-white">
                            Largest files
                        </h2>
                        <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                            Ready files currently using the most space.
                        </p>
                    </div>
                    <Link href="/files" class="text-sm font-medium text-brand">
                        View files
                    </Link>
                </div>
                <div class="mt-4 divide-y divide-line border-y border-line">
                    <div
                        v-for="file in usage.largestFiles"
                        :key="file.id"
                        class="flex min-w-0 items-center gap-3 py-3.5"
                    >
                        <FileTypeIcon
                            :name="file.display_name"
                            :mime-type="file.mime_type"
                        />
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-medium text-ink-950 dark:text-white"
                            >
                                {{ file.display_name }}
                            </p>
                            <p
                                class="mt-0.5 text-xs text-ink-600 dark:text-ink-300"
                            >
                                Updated {{ formatDate(file.updated_at) }}
                            </p>
                        </div>
                        <span
                            class="dark:text-ink-200 shrink-0 text-sm font-semibold text-ink-700 tabular-nums"
                        >
                            {{ formatBytes(file.size_bytes) }}
                        </span>
                    </div>
                    <p
                        v-if="usage.largestFiles.length === 0"
                        class="py-8 text-center text-sm text-ink-600 dark:text-ink-300"
                    >
                        No stored files yet.
                    </p>
                </div>
            </div>

            <div class="border-l-0 border-line lg:border-l lg:pl-6">
                <h2 class="font-semibold text-ink-950 dark:text-white">
                    Upload policy
                </h2>
                <dl class="mt-4 divide-y divide-line border-y border-line">
                    <div class="flex justify-between gap-4 py-3 text-sm">
                        <dt class="text-ink-600 dark:text-ink-300">
                            Maximum file
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatBytes(policy.maxUploadSizeBytes) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3 text-sm">
                        <dt class="text-ink-600 dark:text-ink-300">
                            Multipart from
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatBytes(policy.multipartThresholdBytes) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3 text-sm">
                        <dt class="text-ink-600 dark:text-ink-300">
                            Part size
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatBytes(policy.multipartChunkSizeBytes) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3 text-sm">
                        <dt class="text-ink-600 dark:text-ink-300">
                            Parallel files
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ policy.parallelFileUploads }}
                        </dd>
                    </div>
                </dl>
                <p
                    class="mt-4 flex items-start gap-2 text-xs leading-5 text-ink-600 dark:text-ink-300"
                >
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand" />
                    Trash still occupies storage until it is permanently removed
                    by retention cleanup or an administrator.
                </p>
            </div>
        </section>
    </div>
</template>
