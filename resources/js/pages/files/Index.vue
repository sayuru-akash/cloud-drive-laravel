<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    Check,
    Copy,
    Download,
    File,
    Folder,
    Grid2X2,
    List,
    Pencil,
    Plus,
    Search,
    Share2,
    Trash2,
    UploadCloud,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { copyTextToClipboard } from '@/lib/clipboard';
import { formatBytes, formatDate } from '@/lib/format';

type FolderItem = {
    id: string;
    name: string;
    visibility: string;
    updated_at: string;
};
type FileItem = {
    id: string;
    display_name: string;
    visibility: string;
    size_bytes: number;
    mime_type: string;
    updated_at: string;
};
type RenameTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
type TrashTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
type ShareTarget = FileItem;
type AccessTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
type UploadQueueItem = {
    id: string;
    name: string;
    size: number;
    uploadedBytes: number;
    progress: number;
    status:
        | 'queued'
        | 'preparing'
        | 'uploading'
        | 'finalizing'
        | 'done'
        | 'error';
    message: string;
};

const props = defineProps<{
    folderId: string | null;
    breadcrumbs: Array<{ id: string; name: string }>;
    folders: FolderItem[];
    files: FileItem[];
    filters: { q?: string; visibility?: string; type?: string; sort?: string };
    settings: {
        maxUploadSizeBytes: number;
        blockedExtensions: string[];
        parallelFileUploads?: number;
        parallelPartUploads?: number;
        shareExpiryDays?: number;
    };
}>();

const view = ref(localStorage.getItem('cloud-drive-view') || 'list');
const uploadQueue = ref<UploadQueueItem[]>([]);
const dragging = ref(false);
const shareCopyState = ref<'idle' | 'copied' | 'failed'>('idle');
const renameTarget = ref<RenameTarget | null>(null);
const renameValue = ref('');
const trashTarget = ref<TrashTarget | null>(null);
const shareTarget = ref<ShareTarget | null>(null);
const accessTarget = ref<AccessTarget | null>(null);
const accessValue = ref('private');
const accessProcessing = ref(false);
const folderForm = useForm({
    name: '',
    parent_folder_id: props.folderId,
    visibility: 'private',
});
const shareForm = useForm({
    expires_days: props.settings.shareExpiryDays ?? 7,
    mode: 'download',
});
const page = usePage<{
    flash?: { shareUrl?: string; success?: string; error?: string };
}>();

const activeFilters = computed(
    () =>
        [props.filters.q, props.filters.visibility, props.filters.type].filter(
            Boolean,
        ).length,
);
const visibleUploads = computed(() =>
    uploadQueue.value.filter((item) => item.status !== 'done').slice(-5),
);
const flash = computed(() => page.props.flash ?? {});
const renameTitle = computed(
    () => `Rename ${renameTarget.value?.kind === 'folder' ? 'folder' : 'file'}`,
);
const trashDescription = computed(() => {
    if (! trashTarget.value) {
        return '';
    }

    if (trashTarget.value.kind === 'folder') {
        return `Move "${trashTarget.value.item.name}" and its contents to trash?`;
    }

    return `Move "${trashTarget.value.item.display_name}" to trash?`;
});
const accessResourceName = computed(() => {
    if (! accessTarget.value) {
        return '';
    }

    return accessTarget.value.kind === 'file'
        ? accessTarget.value.item.display_name
        : accessTarget.value.item.name;
});
const accessDescription = computed(() => {
    if (! accessTarget.value) {
        return '';
    }

    return accessTarget.value.kind === 'file'
        ? 'Choose who can find and download this file inside the signed-in workspace. Public share links are managed separately.'
        : 'Choose who can browse this folder and its contents inside the signed-in workspace.';
});

function setView(next: string) {
    view.value = next;
    localStorage.setItem('cloud-drive-view', next);
}

function updateFilters(key: string, value: string) {
    router.get(
        '/files',
        {
            ...props.filters,
            [key]: value || undefined,
            folder: props.folderId || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function createFolder() {
    folderForm.parent_folder_id = props.folderId;
    folderForm.post('/folders', {
        preserveScroll: true,
        onSuccess: () => folderForm.reset('name'),
    });
}

function updateFile(file: FileItem, changes: Record<string, string | null>) {
    router.patch(`/files/${file.id}`, changes, { preserveScroll: true });
}

function updateFolder(
    folder: FolderItem,
    changes: Record<string, string | null>,
) {
    router.patch(`/folders/${folder.id}`, changes, { preserveScroll: true });
}

function renameFile(file: FileItem) {
    renameTarget.value = { kind: 'file', item: file };
    renameValue.value = file.display_name;
}

function renameFolder(folder: FolderItem) {
    renameTarget.value = { kind: 'folder', item: folder };
    renameValue.value = folder.name;
}

function closeRenameDialog() {
    renameTarget.value = null;
    renameValue.value = '';
}

function submitRename() {
    const target = renameTarget.value;
    const next = renameValue.value.trim();

    if (! target || ! next) {
        return;
    }

    if (target.kind === 'file' && next !== target.item.display_name) {
        updateFile(target.item, { display_name: next });
    }

    if (target.kind === 'folder' && next !== target.item.name) {
        updateFolder(target.item, { name: next });
    }

    closeRenameDialog();
}

function trashFile(file: FileItem) {
    trashTarget.value = { kind: 'file', item: file };
}

function trashFolder(folder: FolderItem) {
    trashTarget.value = { kind: 'folder', item: folder };
}

function closeTrashDialog() {
    trashTarget.value = null;
}

function confirmTrash() {
    const target = trashTarget.value;

    if (! target) {
        return;
    }

    router.delete(
        target.kind === 'file'
            ? `/files/${target.item.id}`
            : `/folders/${target.item.id}`,
        { preserveScroll: true },
    );
    closeTrashDialog();
}

function manageAccess(target: AccessTarget) {
    accessTarget.value = target;
    accessValue.value = target.item.visibility;
}

function closeAccessDialog(force = false) {
    if (accessProcessing.value && ! force) {
        return;
    }

    accessTarget.value = null;
    accessValue.value = 'private';
}

function submitAccess() {
    const target = accessTarget.value;

    if (! target) {
        return;
    }

    if (accessValue.value === target.item.visibility) {
        closeAccessDialog();

        return;
    }

    const url =
        target.kind === 'file'
            ? `/files/${target.item.id}`
            : `/folders/${target.item.id}`;

    router.patch(
        url,
        { visibility: accessValue.value },
        {
            preserveScroll: true,
            onStart: () => {
                accessProcessing.value = true;
            },
            onFinish: () => {
                accessProcessing.value = false;
            },
            onSuccess: () => closeAccessDialog(true),
        },
    );
}

function createShare(file: FileItem) {
    shareTarget.value = file;
    shareForm.expires_days = props.settings.shareExpiryDays ?? 7;
    shareForm.mode = 'download';
    shareForm.clearErrors();
}

function closeShareDialog() {
    shareTarget.value = null;
    shareForm.clearErrors();
}

function submitShare() {
    if (! shareTarget.value) {
        return;
    }

    shareForm.post(`/files/${shareTarget.value.id}/shares`, {
        preserveScroll: true,
        onSuccess: closeShareDialog,
    });
}

async function copyShareUrl() {
    if (!flash.value.shareUrl) {
        return;
    }

    shareCopyState.value = (await copyTextToClipboard(flash.value.shareUrl))
        ? 'copied'
        : 'failed';
    window.setTimeout(() => {
        shareCopyState.value = 'idle';
    }, 4000);
}

function csrfToken() {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function updateUpload(id: string, patch: Partial<UploadQueueItem>) {
    const item = uploadQueue.value.find((upload) => upload.id === id);

    if (item) {
        Object.assign(item, patch);
    }
}

async function runPool<T>(
    items: T[],
    concurrency: number,
    worker: (item: T, index: number) => Promise<void>,
) {
    let nextIndex = 0;
    const workers = Array.from(
        { length: Math.min(concurrency, items.length) },
        async () => {
            while (nextIndex < items.length) {
                const index = nextIndex;
                nextIndex += 1;
                await worker(items[index], index);
            }
        },
    );

    await Promise.all(workers);
}

function uploadBlob(
    url: string,
    blob: Blob,
    contentType: string | null,
    onProgress: (loaded: number) => void,
): Promise<string> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);

        if (contentType) {
            xhr.setRequestHeader('Content-Type', contentType);
        }

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded);
            }
        };

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.getResponseHeader('ETag') ?? '');

                return;
            }

            reject(new Error(`Upload failed with status ${xhr.status}`));
        };
        xhr.onerror = () =>
            reject(new Error('Upload failed before reaching storage.'));
        xhr.onabort = () => reject(new Error('Upload cancelled.'));
        xhr.send(blob);
    });
}

async function completeUpload(fileId: string, body: Record<string, unknown>) {
    const response = await fetch(`/api/files/${fileId}/complete-upload`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('The app could not finalize this upload.');
    }
}

async function uploadOne(file: globalThis.File, queueId: string) {
    updateUpload(queueId, {
        status: 'preparing',
        message: 'Preparing signed URL',
    });
    const init = await fetch('/api/files/initiate-upload', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            name: file.name,
            size: file.size,
            type: file.type,
            folderId: props.folderId,
        }),
    });

    if (!init.ok) {
        throw new Error('Upload rejected by workspace policy.');
    }

    const payload = await init.json();

    if (!payload.multipart) {
        updateUpload(queueId, {
            status: 'uploading',
            message: 'Uploading to B2',
        });
        await uploadBlob(
            payload.uploadUrl,
            file,
            file.type || 'application/octet-stream',
            (loaded) => {
                updateUpload(queueId, {
                    uploadedBytes: loaded,
                    progress: Math.min(
                        95,
                        Math.round((loaded / file.size) * 95),
                    ),
                });
            },
        );
        updateUpload(queueId, {
            status: 'finalizing',
            message: 'Finalizing metadata',
            progress: 98,
        });
        await completeUpload(payload.fileId, {});
        updateUpload(queueId, {
            status: 'done',
            message: 'Done',
            progress: 100,
            uploadedBytes: file.size,
        });

        return;
    }

    updateUpload(queueId, {
        status: 'uploading',
        message: `Uploading ${payload.totalParts} parts in parallel`,
    });
    const partProgress = new Map<number, number>();
    const parts: Array<{ partNumber: number; etag: string }> = [];
    const partNumbers = Array.from(
        { length: payload.totalParts },
        (_, index) => index + 1,
    );
    const partConcurrency = Math.max(
        1,
        props.settings.parallelPartUploads ?? 4,
    );

    await runPool(partNumbers, partConcurrency, async (partNumber) => {
        const start = (partNumber - 1) * payload.chunkSize;
        const blob = file.slice(
            start,
            Math.min(start + payload.chunkSize, file.size),
        );
        const partResponse = await fetch(
            `/api/files/${payload.fileId}/multipart-part`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ partNumber }),
            },
        );

        if (!partResponse.ok) {
            throw new Error(`Could not sign part ${partNumber}.`);
        }

        const partPayload = await partResponse.json();
        const etag = await uploadBlob(
            partPayload.uploadUrl,
            blob,
            null,
            (loaded) => {
                partProgress.set(partNumber, loaded);
                const uploadedBytes = Array.from(partProgress.values()).reduce(
                    (sum, current) => sum + current,
                    0,
                );
                updateUpload(queueId, {
                    uploadedBytes,
                    progress: Math.min(
                        95,
                        Math.round((uploadedBytes / file.size) * 95),
                    ),
                });
            },
        );

        parts.push({ partNumber, etag });
    });

    updateUpload(queueId, {
        status: 'finalizing',
        message: 'Combining parts',
        progress: 98,
    });
    await completeUpload(payload.fileId, { parts });
    updateUpload(queueId, {
        status: 'done',
        message: 'Done',
        progress: 100,
        uploadedBytes: file.size,
    });
}

async function uploadFiles(list: FileList) {
    const files = Array.from(list);
    const queued = files.map((file) => {
        const item: UploadQueueItem = {
            id: `${file.name}-${file.size}-${crypto.randomUUID()}`,
            name: file.name,
            size: file.size,
            uploadedBytes: 0,
            progress: 0,
            status: 'queued',
            message: 'Queued',
        };
        uploadQueue.value.push(item);

        return { file, item };
    });

    await runPool(
        queued,
        Math.max(1, props.settings.parallelFileUploads ?? 2),
        async ({ file, item }) => {
            try {
                await uploadOne(file, item.id);
            } catch (error) {
                updateUpload(item.id, {
                    status: 'error',
                    message:
                        error instanceof Error
                            ? error.message
                            : 'Upload failed',
                });
            }
        },
    );

    router.reload({ only: ['files', 'folders'] });
}

function handleDrop(event: DragEvent) {
    dragging.value = false;

    if (event.dataTransfer?.files) {
        void uploadFiles(event.dataTransfer.files);
    }
}

function handleInput(event: Event) {
    const input = event.target as HTMLInputElement | null;

    if (input?.files) {
        void uploadFiles(input.files);
        input.value = '';
    }
}
</script>

<template>
    <Head title="Files" />
    <main
        class="space-y-6"
        @dragover.prevent="dragging = true"
        @dragleave="dragging = false"
        @drop.prevent="handleDrop"
    >
        <PageHeader
            title="Files"
            description="Browse folders, upload directly to storage, share download links, and keep access tidy."
        >
            <template #actions>
                <label
                    class="cloud-button cursor-pointer bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                >
                    <UploadCloud class="h-4 w-4" />
                    Upload
                    <input
                        type="file"
                        multiple
                        class="hidden"
                        @change="handleInput"
                    />
                </label>
            </template>
        </PageHeader>

        <section class="cloud-panel p-4 md:p-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                >
                    <Search class="h-4 w-4 text-brand" />
                    <input
                        :value="filters.q"
                        class="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        placeholder="Search files"
                        @input="
                            updateFilters(
                                'q',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
                <select
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                    :value="filters.visibility"
                    @change="
                        updateFilters(
                            'visibility',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">All access</option>
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                </select>
                <button
                    v-if="activeFilters"
                    type="button"
                    class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                    @click="
                        router.get('/files', { folder: folderId || undefined })
                    "
                >
                    Clear
                </button>
                <div
                    class="flex rounded-full border border-line bg-white p-1 dark:bg-white/10"
                >
                    <button
                        type="button"
                        class="rounded-full p-2"
                        :class="
                            view === 'list'
                                ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                                : 'text-ink-600'
                        "
                        @click="setView('list')"
                    >
                        <List class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-full p-2"
                        :class="
                            view === 'grid'
                                ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                                : 'text-ink-600'
                        "
                        @click="setView('grid')"
                    >
                        <Grid2X2 class="h-4 w-4" />
                    </button>
                </div>
            </div>
            <div
                class="mt-4 flex flex-wrap items-center gap-2 text-sm text-ink-600 dark:text-ink-300"
            >
                <Link href="/files" class="font-medium text-brand">Home</Link>
                <template v-for="crumb in breadcrumbs" :key="crumb.id">
                    <span>/</span>
                    <Link
                        :href="`/files?folder=${crumb.id}`"
                        class="font-medium text-brand"
                        >{{ crumb.name }}</Link
                    >
                </template>
            </div>
        </section>

        <section
            v-if="flash.shareUrl"
            class="cloud-panel flex flex-col gap-4 p-4 text-sm md:flex-row md:items-center md:justify-between"
        >
            <div class="min-w-0">
                <p class="font-semibold text-ink-950 dark:text-white">
                    Share link ready
                </p>
                <a
                    class="mt-1 block min-w-0 truncate font-medium text-brand"
                    :href="flash.shareUrl"
                    target="_blank"
                    rel="noreferrer"
                    >{{ flash.shareUrl }}</a
                >
            </div>
            <div class="flex flex-col items-start gap-2 md:items-end">
                <div class="flex flex-wrap gap-2">
                    <a
                        :href="flash.shareUrl"
                        target="_blank"
                        rel="noreferrer"
                        class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                    >
                        Open
                    </a>
                    <button
                        type="button"
                        class="cloud-button transition"
                        :class="
                            shareCopyState === 'copied'
                                ? 'bg-emerald-600 text-white'
                                : shareCopyState === 'failed'
                                  ? 'bg-red-600 text-white'
                                  : 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                        "
                        @click="copyShareUrl"
                    >
                        <Check
                            v-if="shareCopyState === 'copied'"
                            class="h-4 w-4"
                        />
                        <AlertCircle
                            v-else-if="shareCopyState === 'failed'"
                            class="h-4 w-4"
                        />
                        <Copy v-else class="h-4 w-4" />
                        {{
                            shareCopyState === 'copied'
                                ? 'Copied'
                                : shareCopyState === 'failed'
                                  ? 'Copy failed'
                                  : 'Copy link'
                        }}
                    </button>
                </div>
                <p
                    v-if="shareCopyState !== 'idle'"
                    class="text-xs font-medium"
                    :class="
                        shareCopyState === 'copied'
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-red-600 dark:text-red-300'
                    "
                >
                    {{
                        shareCopyState === 'copied'
                            ? 'Copied to clipboard.'
                            : 'Your browser blocked clipboard access.'
                    }}
                </p>
            </div>
        </section>

        <section
            class="cloud-panel p-4 md:p-5"
            :class="
                dragging
                    ? 'ring-2 ring-brand ring-offset-4 ring-offset-background'
                    : ''
            "
        >
            <form
                class="mb-5 flex flex-col gap-3 md:flex-row"
                @submit.prevent="createFolder"
            >
                <input
                    v-model="folderForm.name"
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm outline-none dark:bg-white/10"
                    placeholder="New folder"
                />
                <select
                    v-model="folderForm.visibility"
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                >
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                </select>
                <button
                    type="submit"
                    class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                    :disabled="folderForm.processing"
                >
                    <Plus class="h-4 w-4" />
                    Create
                </button>
            </form>

            <div
                v-if="view === 'grid'"
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="folder in folders"
                    :key="folder.id"
                    class="rounded-[1.5rem] border border-line bg-white/70 p-4 dark:bg-white/10"
                >
                    <Folder class="h-6 w-6 text-brand" />
                    <Link
                        :href="`/files?folder=${folder.id}`"
                        class="mt-5 block truncate font-semibold text-ink-950 dark:text-white"
                        >{{ folder.name }}</Link
                    >
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <StatusBadge :value="folder.visibility" />
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                title="Rename"
                                @click="renameFolder(folder)"
                            >
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                title="Manage access"
                                @click="
                                    manageAccess({ kind: 'folder', item: folder })
                                "
                            >
                                <Users class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
                                title="Move to trash"
                                @click="trashFolder(folder)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-for="file in files"
                    :key="file.id"
                    class="rounded-[1.5rem] border border-line bg-white/70 p-4 dark:bg-white/10"
                >
                    <File class="h-6 w-6 text-brand" />
                    <p
                        class="mt-5 truncate font-semibold text-ink-950 dark:text-white"
                    >
                        {{ file.display_name }}
                    </p>
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                        {{ formatBytes(file.size_bytes) }}
                    </p>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <StatusBadge :value="file.visibility" />
                        <div class="flex items-center gap-2">
                            <a
                                class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                                title="Download"
                                :href="`/api/files/${file.id}/download`"
                                ><Download class="h-4 w-4"
                            /></a>
                            <button
                                type="button"
                                class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                title="Share"
                                @click="createShare(file)"
                            >
                                <Share2 class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                title="Rename"
                                @click="renameFile(file)"
                            >
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                title="Manage access"
                                @click="manageAccess({ kind: 'file', item: file })"
                            >
                                <Users class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
                                title="Move to trash"
                                @click="trashFile(file)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="divide-y divide-line">
                <div
                    v-for="folder in folders"
                    :key="folder.id"
                    class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto] md:items-center"
                >
                    <Link
                        :href="`/files?folder=${folder.id}`"
                        class="flex min-w-0 items-center gap-3"
                        ><Folder class="h-5 w-5 text-brand" /><span
                            class="truncate font-medium"
                            >{{ folder.name }}</span
                        ></Link
                    >
                    <StatusBadge :value="folder.visibility" />
                    <div
                        class="flex flex-wrap justify-start gap-3 text-sm md:justify-end"
                    >
                        <button
                            type="button"
                            class="text-brand"
                            @click="renameFolder(folder)"
                        >
                            Rename
                        </button>
                        <button
                            type="button"
                            class="text-brand"
                            @click="
                                manageAccess({ kind: 'folder', item: folder })
                            "
                        >
                            Access
                        </button>
                        <button
                            type="button"
                            class="text-red-600"
                            @click="trashFolder(folder)"
                        >
                            Trash
                        </button>
                    </div>
                </div>
                <div
                    v-for="file in files"
                    :key="file.id"
                    class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto_auto] md:items-center"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <File class="h-5 w-5 text-brand" />
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ file.display_name }}
                            </p>
                            <p class="text-xs text-ink-600 dark:text-ink-300">
                                {{ file.mime_type }} ·
                                {{ formatDate(file.updated_at) }}
                            </p>
                        </div>
                    </div>
                    <span class="text-sm text-ink-600 dark:text-ink-300">{{
                        formatBytes(file.size_bytes)
                    }}</span>
                    <StatusBadge :value="file.visibility" />
                    <div class="flex flex-wrap gap-3 text-sm">
                        <a
                            class="text-brand"
                            :href="`/api/files/${file.id}/download`"
                            >Download</a
                        >
                        <button
                            type="button"
                            class="text-brand"
                            @click="createShare(file)"
                        >
                            Share
                        </button>
                        <button
                            type="button"
                            class="text-brand"
                            @click="renameFile(file)"
                        >
                            Rename
                        </button>
                        <button
                            type="button"
                            class="text-brand"
                            @click="manageAccess({ kind: 'file', item: file })"
                        >
                            Access
                        </button>
                        <button
                            type="button"
                            class="text-red-600"
                            @click="trashFile(file)"
                        >
                            Trash
                        </button>
                    </div>
                </div>
                <p
                    v-if="folders.length + files.length === 0"
                    class="py-12 text-center text-sm text-ink-600 dark:text-ink-300"
                >
                    Drop files here or create a folder.
                </p>
            </div>
        </section>

        <Dialog
            :open="renameTarget !== null"
            @update:open="($event) => !$event && closeRenameDialog()"
        >
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitRename">
                    <DialogHeader>
                        <DialogTitle>{{ renameTitle }}</DialogTitle>
                        <DialogDescription>
                            Update the display name used across the workspace.
                        </DialogDescription>
                    </DialogHeader>
                    <label class="block space-y-2 text-sm font-medium">
                        <span>Name</span>
                        <input
                            v-model="renameValue"
                            class="w-full rounded-2xl border border-line bg-white px-4 py-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-white/10"
                            autocomplete="off"
                        />
                    </label>
                    <DialogFooter class="gap-2 sm:gap-2">
                        <button
                            type="button"
                            class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            @click="closeRenameDialog"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                            :disabled="renameValue.trim().length === 0"
                        >
                            Save
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="trashTarget !== null"
            @update:open="($event) => !$event && closeTrashDialog()"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Move to trash</DialogTitle>
                    <DialogDescription>
                        {{ trashDescription }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-2">
                    <button
                        type="button"
                        class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                        @click="closeTrashDialog"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="cloud-button bg-red-600 text-white hover:bg-red-700"
                        @click="confirmTrash"
                    >
                        Move to trash
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="accessTarget !== null"
            @update:open="($event) => !$event && closeAccessDialog()"
        >
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-5" @submit.prevent="submitAccess">
                    <DialogHeader>
                        <DialogTitle>Manage access</DialogTitle>
                        <DialogDescription>
                            {{ accessResourceName }}
                        </DialogDescription>
                    </DialogHeader>
                    <p class="text-sm text-ink-600 dark:text-ink-300">
                        {{ accessDescription }}
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-left transition"
                            :class="
                                accessValue === 'private'
                                    ? 'border-brand bg-brand/10 text-ink-950 dark:text-white'
                                    : 'border-line bg-white/70 text-ink-700 dark:bg-white/10 dark:text-ink-200'
                            "
                            @click="accessValue = 'private'"
                        >
                            <span class="block font-semibold">Private</span>
                            <span
                                class="mt-1 block text-sm text-ink-600 dark:text-ink-300"
                            >
                                Only the owner and admins can open it.
                            </span>
                        </button>
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-left transition"
                            :class="
                                accessValue === 'workspace'
                                    ? 'border-brand bg-brand/10 text-ink-950 dark:text-white'
                                    : 'border-line bg-white/70 text-ink-700 dark:bg-white/10 dark:text-ink-200'
                            "
                            @click="accessValue = 'workspace'"
                        >
                            <span class="block font-semibold">Workspace</span>
                            <span
                                class="mt-1 block text-sm text-ink-600 dark:text-ink-300"
                            >
                                Signed-in workspace members can view it.
                            </span>
                        </button>
                    </div>
                    <div
                        class="rounded-2xl border border-line bg-white/70 p-4 text-sm text-ink-600 dark:bg-white/10 dark:text-ink-300"
                    >
                        Share links remain separate. Revoking workspace access
                        does not revoke already-created public links.
                    </div>
                    <DialogFooter class="gap-2 sm:gap-2">
                        <button
                            type="button"
                            class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            :disabled="accessProcessing"
                            @click="closeAccessDialog()"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                            :disabled="accessProcessing"
                        >
                            <Users class="h-4 w-4" />
                            {{ accessProcessing ? 'Saving' : 'Save access' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="shareTarget !== null"
            @update:open="($event) => !$event && closeShareDialog()"
        >
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-5" @submit.prevent="submitShare">
                    <DialogHeader>
                        <DialogTitle>Create share link</DialogTitle>
                        <DialogDescription>
                            {{ shareTarget?.display_name }}
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <button
                            v-for="days in [1, 7, 30]"
                            :key="days"
                            type="button"
                            class="rounded-2xl border px-4 py-3 text-left text-sm transition"
                            :class="
                                shareForm.expires_days === days
                                    ? 'border-brand bg-brand/10 text-ink-950 dark:text-white'
                                    : 'border-line bg-white/70 text-ink-700 dark:bg-white/10 dark:text-ink-200'
                            "
                            @click="shareForm.expires_days = days"
                        >
                            <span class="block font-semibold"
                                >{{ days }} day{{ days === 1 ? '' : 's' }}</span
                            >
                            <span class="text-xs text-ink-600 dark:text-ink-300"
                                >Expires automatically</span
                            >
                        </button>
                    </div>
                    <label class="block space-y-2 text-sm font-medium">
                        <span>Custom expiry in days</span>
                        <input
                            v-model.number="shareForm.expires_days"
                            type="number"
                            min="1"
                            max="90"
                            class="w-full rounded-2xl border border-line bg-white px-4 py-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-white/10"
                        />
                        <span
                            v-if="shareForm.errors.expires_days"
                            class="text-xs text-red-600"
                            >{{ shareForm.errors.expires_days }}</span
                        >
                    </label>
                    <div
                        class="rounded-2xl border border-line bg-white/70 p-4 text-sm dark:bg-white/10"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <span>
                                <span
                                    class="block font-semibold text-ink-950 dark:text-white"
                                    >Download-only</span
                                >
                                <span class="text-ink-600 dark:text-ink-300"
                                    >Recipients can download this ready file until the link expires or is revoked.</span
                                >
                            </span>
                            <StatusBadge value="active" />
                        </div>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-2">
                        <button
                            type="button"
                            class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            @click="closeShareDialog"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                            :disabled="shareForm.processing"
                        >
                            <Share2 class="h-4 w-4" />
                            {{ shareForm.processing ? 'Creating' : 'Create link' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <div
            v-if="visibleUploads.length > 0"
            class="fixed right-4 bottom-4 w-[min(24rem,calc(100vw-2rem))] rounded-[1.5rem] border border-line bg-white/92 p-4 text-sm shadow-2xl backdrop-blur dark:bg-ink-900/92"
        >
            <div class="flex items-center justify-between">
                <p class="font-semibold text-ink-950 dark:text-white">
                    Uploads
                </p>
                <span class="text-xs text-ink-600 dark:text-ink-300"
                    >Parallel</span
                >
            </div>
            <div class="mt-3 space-y-3">
                <div
                    v-for="upload in visibleUploads"
                    :key="upload.id"
                    class="rounded-[1.1rem] border border-line bg-white/70 p-3 dark:bg-white/10"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="truncate font-medium text-ink-950 dark:text-white"
                            >
                                {{ upload.name }}
                            </p>
                            <p
                                class="mt-0.5 text-xs text-ink-600 dark:text-ink-300"
                            >
                                {{ upload.message }} ·
                                {{ formatBytes(upload.uploadedBytes) }} /
                                {{ formatBytes(upload.size) }}
                            </p>
                        </div>
                        <span class="text-xs font-semibold text-brand"
                            >{{ upload.progress }}%</span
                        >
                    </div>
                    <div
                        class="mt-3 h-2 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10"
                    >
                        <div
                            class="h-full rounded-full transition-all duration-200"
                            :class="
                                upload.status === 'error'
                                    ? 'bg-red-500'
                                    : 'bg-brand'
                            "
                            :style="{ width: `${upload.progress}%` }"
                        />
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>
