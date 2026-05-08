<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, Folder, Home } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandFooter from '@/components/BrandFooter.vue';
import FileTypeIcon from '@/components/cloud/FileTypeIcon.vue';
import SeoHead from '@/components/SeoHead.vue';
import { formatFileType } from '@/lib/file-types';
import { formatBytes, formatDate } from '@/lib/format';

type PublicFolder = {
    id: string;
    name: string;
    updated_at: string;
};

type PublicFile = {
    id: string;
    display_name: string;
    size_bytes: number;
    mime_type?: string;
    updated_at?: string;
};

const props = defineProps<{
    available: boolean;
    status: 'active' | 'invalid' | 'revoked' | 'expired' | 'unavailable';
    resourceType: 'file' | 'folder' | null;
    file: PublicFile | null;
    folder: PublicFolder | null;
    currentFolderId: string | null;
    breadcrumbs: Array<{ id: string; name: string }>;
    folders: PublicFolder[];
    files: PublicFile[];
    downloadUrl: string | null;
    fileDownloadBaseUrl: string | null;
    expiresAt: string | null;
    folderDownload: {
        file_count: number;
        size_bytes: number;
        limit_exceeded: boolean;
        file_limit: number;
        size_limit_bytes: number;
    } | null;
}>();

const unavailableMessage = computed(() => {
    switch (props.status) {
        case 'expired':
            return 'This share link has expired.';
        case 'revoked':
            return 'This share link has been revoked.';
        case 'unavailable':
            return 'This shared item is no longer available.';
        default:
            return 'This share link is not valid.';
    }
});

const currentTitle = computed(() => {
    if (props.resourceType === 'folder') {
        return props.breadcrumbs.at(-1)?.name ?? props.folder?.name ?? 'Shared folder';
    }

    return props.file?.display_name ?? 'Shared file';
});

const token = computed(() =>
    typeof window === 'undefined'
        ? ''
        : (window.location.pathname.split('/').pop() ?? ''),
);

function folderHref(folderId: string) {
    return `/s/${token.value}?folder=${folderId}`;
}

function fileDownloadUrl(fileId: string) {
    return props.fileDownloadBaseUrl
        ? `${props.fileDownloadBaseUrl}/${fileId}/download`
        : '#';
}
</script>

<template>
    <SeoHead
        :title="resourceType === 'folder' ? 'Shared folder' : 'Shared file'"
        description="Secure Cloud Drive share link."
        path="/"
    />
    <main class="flex min-h-screen flex-col bg-background px-4 py-8">
        <section
            class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6"
        >
            <div
                class="flex flex-col gap-4 rounded-[1.75rem] border border-line bg-white/85 p-5 shadow-soft backdrop-blur dark:bg-white/10 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white text-background shadow-sm ring-1 ring-line dark:bg-ink-950 dark:text-ink-950"
                    >
                        <AppLogoIcon class="h-11 w-11" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-brand">
                            Cloud Drive share
                        </p>
                        <h1 class="truncate text-2xl font-semibold tracking-tight text-ink-950 dark:text-white">
                            {{ available ? currentTitle : 'Link unavailable' }}
                        </h1>
                        <p
                            v-if="available"
                            class="mt-1 text-sm text-ink-600 dark:text-ink-300"
                        >
                            Expires {{ formatDate(expiresAt) }}
                        </p>
                    </div>
                </div>
                <a
                    v-if="
                        available &&
                        resourceType === 'file' &&
                        file &&
                        downloadUrl
                    "
                    :href="downloadUrl"
                    class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                >
                    <Download class="h-4 w-4" />
                    Download
                </a>
                <a
                    v-else-if="
                        available &&
                        resourceType === 'folder' &&
                        downloadUrl &&
                        folderDownload &&
                        folderDownload.file_count > 0 &&
                        !folderDownload.limit_exceeded
                    "
                    :href="downloadUrl"
                    class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                >
                    <Download class="h-4 w-4" />
                    Download folder
                </a>
            </div>

            <section
                v-if="available && resourceType === 'file' && file"
                class="cloud-panel mx-auto w-full max-w-xl p-8 text-center"
            >
                <FileTypeIcon
                    :name="file.display_name"
                    :mime-type="file.mime_type ?? null"
                    class="mx-auto h-12 w-12 rounded-2xl"
                />
                <h2 class="mt-6 text-3xl font-semibold tracking-tight">
                    {{ file.display_name }}
                </h2>
                <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">
                    {{ formatBytes(file.size_bytes) }} ·
                    {{
                        formatFileType(
                            file.display_name,
                            file.mime_type ?? null,
                        )
                    }}
                </p>
            </section>

            <section
                v-else-if="available && resourceType === 'folder' && folder"
                class="cloud-panel p-4 md:p-5"
            >
                <div
                    class="flex flex-col gap-4 border-b border-line pb-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="min-w-0">
                        <div
                            class="flex flex-wrap items-center gap-2 text-sm text-ink-600 dark:text-ink-300"
                        >
                            <Link
                                :href="`/s/${token}`"
                                class="inline-flex items-center gap-1 font-medium text-brand"
                            >
                                <Home class="h-4 w-4" />
                                Home
                            </Link>
                            <template
                                v-for="crumb in breadcrumbs"
                                :key="crumb.id"
                            >
                                <span>/</span>
                                <Link
                                    :href="folderHref(crumb.id)"
                                    class="font-medium text-brand"
                                >
                                    {{ crumb.name }}
                                </Link>
                            </template>
                        </div>
                    </div>
                    <p
                        v-if="folderDownload"
                        class="text-sm text-ink-600 dark:text-ink-300"
                    >
                        {{ folderDownload.file_count }} files ·
                        {{ formatBytes(folderDownload.size_bytes) }}
                    </p>
                </div>

                <div
                    v-if="
                        folderDownload?.limit_exceeded &&
                        folderDownload.file_count > 0
                    "
                    class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-400/25 dark:bg-amber-400/10 dark:text-amber-100"
                >
                    This folder can be browsed here, but it is too large for one
                    zip download. Download files individually from the list.
                </div>

                <div class="mt-4 divide-y divide-line">
                    <Link
                        v-for="child in folders"
                        :key="child.id"
                        :href="folderHref(child.id)"
                        class="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
                    >
                        <span class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand"
                            >
                                <Folder class="h-5 w-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium">
                                    {{ child.name }}
                                </span>
                                <span
                                    class="block text-xs text-ink-600 dark:text-ink-300"
                                >
                                    Folder · {{ formatDate(child.updated_at) }}
                                </span>
                            </span>
                        </span>
                    </Link>

                    <div
                        v-for="sharedFile in files"
                        :key="sharedFile.id"
                        class="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_7rem_auto] md:items-center"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <FileTypeIcon
                                :name="sharedFile.display_name"
                                :mime-type="sharedFile.mime_type ?? null"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ sharedFile.display_name }}
                                </p>
                                <p
                                    class="text-xs text-ink-600 dark:text-ink-300"
                                >
                                    {{
                                        formatFileType(
                                            sharedFile.display_name,
                                            sharedFile.mime_type ?? null,
                                        )
                                    }}
                                    · {{ formatDate(sharedFile.updated_at) }}
                                </p>
                            </div>
                        </div>
                        <span class="text-sm text-ink-600 dark:text-ink-300">
                            {{ formatBytes(sharedFile.size_bytes) }}
                        </span>
                        <a
                            class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10 md:justify-self-end"
                            title="Download"
                            :href="fileDownloadUrl(sharedFile.id)"
                        >
                            <Download class="h-4 w-4" />
                        </a>
                    </div>

                    <p
                        v-if="folders.length + files.length === 0"
                        class="py-12 text-center text-sm text-ink-600 dark:text-ink-300"
                    >
                        No downloadable files are available in this folder.
                    </p>
                </div>
            </section>

            <section v-else class="cloud-panel mx-auto w-full max-w-xl p-8 text-center">
                <h2 class="text-3xl font-semibold tracking-tight">
                    Link unavailable
                </h2>
                <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">
                    {{ unavailableMessage }}
                </p>
            </section>
        </section>
        <BrandFooter class="mt-8" />
    </main>
</template>
