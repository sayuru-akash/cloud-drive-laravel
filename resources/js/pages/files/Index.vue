<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ChevronDown,
    Check,
    Copy,
    Download,
    FileUp,
    Folder,
    Grid2X2,
    List,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Share2,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FileTypeIcon from '@/components/cloud/FileTypeIcon.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import PaginationLinks from '@/components/cloud/PaginationLinks.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { copyTextToClipboard } from '@/lib/clipboard';
import { formatFileType } from '@/lib/file-types';
import { formatBytes, formatDate } from '@/lib/format';

type FolderItem = {
    id: string;
    name: string;
    visibility: string;
    updated_at: string;
    can_manage: boolean;
};
type FileItem = {
    id: string;
    display_name: string;
    visibility: string;
    size_bytes: number;
    mime_type: string;
    updated_at: string;
    can_manage: boolean;
};
type RenameTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
type TrashTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
type ShareTarget =
    | { kind: 'file'; item: FileItem }
    | { kind: 'folder'; item: FolderItem };
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
    mimeType: string | null;
    message: string;
    remoteFileId?: string;
};
type Paginated<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};
type FilterKey = 'q' | 'visibility' | 'type' | 'sort';

const props = defineProps<{
    folderId: string | null;
    breadcrumbs: Array<{ id: string; name: string }>;
    folders: Paginated<FolderItem>;
    files: Paginated<FileItem>;
    filters: { q?: string; visibility?: string; type?: string; sort?: string };
    settings: {
        maxUploadSizeBytes: number;
        blockedExtensions: string[];
        parallelFileUploads?: number;
        parallelPartUploads?: number;
        shareExpiryDays?: number;
    };
    canManageCurrentLocation: boolean;
}>();

const view = ref(localStorage.getItem('cloud-drive-view') || 'list');
const uploadQueue = ref<UploadQueueItem[]>([]);
const dragging = ref(false);
const createFolderOpen = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const shareCopyState = ref<'idle' | 'copied' | 'failed'>('idle');
const renameTarget = ref<RenameTarget | null>(null);
const renameValue = ref('');
const trashTarget = ref<TrashTarget | null>(null);
const shareTarget = ref<ShareTarget | null>(null);
const accessTarget = ref<AccessTarget | null>(null);
const accessValue = ref('private');
const accessProcessing = ref(false);
const activeUploadRequests = new Map<string, Set<XMLHttpRequest>>();
const failedUploadQueues = new Set<string>();
const driveRefreshProps = [
    'files',
    'folders',
    'breadcrumbs',
    'filters',
    'flash',
    'canManageCurrentLocation',
];
let revalidateTimer: number | null = null;
let filterTimer: number | null = null;
let lastRevalidatedAt = 0;
const filterValues = ref({
    q: props.filters.q ?? '',
    visibility: props.filters.visibility ?? '',
    type: props.filters.type ?? '',
    sort: props.filters.sort ?? 'updated-desc',
});
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
        [
            props.filters.q,
            props.filters.visibility,
            props.filters.type,
            props.filters.sort && props.filters.sort !== 'updated-desc'
                ? props.filters.sort
                : '',
        ].filter(Boolean).length,
);
const folderItems = computed(() => props.folders.data);
const fileItems = computed(() => props.files.data);
const unfinishedUploads = computed(() =>
    uploadQueue.value.filter((item) => item.status !== 'done'),
);
const visibleUploads = computed(() => {
    const active = unfinishedUploads.value.filter(
        (item) => item.status !== 'queued' && item.status !== 'error',
    );
    const errors = unfinishedUploads.value.filter(
        (item) => item.status === 'error',
    );
    const queued = unfinishedUploads.value.filter(
        (item) => item.status === 'queued',
    );

    return [...active, ...errors, ...queued].slice(0, 5);
});
const uploadSummary = computed(() => {
    const active = unfinishedUploads.value.filter((item) =>
        ['preparing', 'uploading', 'finalizing'].includes(item.status),
    ).length;
    const queued = unfinishedUploads.value.filter(
        (item) => item.status === 'queued',
    ).length;
    const errors = unfinishedUploads.value.filter(
        (item) => item.status === 'error',
    ).length;

    return [
        active ? `${active} active` : '',
        queued ? `${queued} queued` : '',
        errors ? `${errors} failed` : '',
    ]
        .filter(Boolean)
        .join(' · ');
});
const hiddenUploadCount = computed(() =>
    Math.max(0, unfinishedUploads.value.length - visibleUploads.value.length),
);
const flash = computed(() => page.props.flash ?? {});
const renameTitle = computed(
    () => `Rename ${renameTarget.value?.kind === 'folder' ? 'folder' : 'file'}`,
);
const trashDescription = computed(() => {
    if (!trashTarget.value) {
        return '';
    }

    if (trashTarget.value.kind === 'folder') {
        return `Move "${trashTarget.value.item.name}" and its contents to trash?`;
    }

    return `Move "${trashTarget.value.item.display_name}" to trash?`;
});
const accessResourceName = computed(() => {
    if (!accessTarget.value) {
        return '';
    }

    return accessTarget.value.kind === 'file'
        ? accessTarget.value.item.display_name
        : accessTarget.value.item.name;
});
const accessDescription = computed(() => {
    if (!accessTarget.value) {
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

function filterPayload() {
    return {
        q: filterValues.value.q || undefined,
        visibility: filterValues.value.visibility || undefined,
        type: filterValues.value.type || undefined,
        sort:
            filterValues.value.sort &&
            filterValues.value.sort !== 'updated-desc'
                ? filterValues.value.sort
                : undefined,
        folder: props.folderId || undefined,
    };
}

function visitWithFilters() {
    router.get('/files', filterPayload(), {
        only: driveRefreshProps,
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function queueFilterVisit() {
    if (filterTimer !== null) {
        window.clearTimeout(filterTimer);
    }

    filterTimer = window.setTimeout(visitWithFilters, 300);
}

function updateFilter(key: FilterKey, value: string) {
    filterValues.value[key] = value;

    if (key === 'q') {
        queueFilterVisit();

        return;
    }

    visitWithFilters();
}

function clearFilters() {
    filterValues.value = {
        q: '',
        visibility: '',
        type: '',
        sort: 'updated-desc',
    };

    router.get(
        '/files',
        { folder: props.folderId || undefined },
        {
            only: driveRefreshProps,
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function openFilePicker() {
    if (!props.canManageCurrentLocation) {
        return;
    }

    fileInput.value?.click();
}

function openCreateFolderDialog() {
    if (!props.canManageCurrentLocation) {
        return;
    }

    folderForm.parent_folder_id = props.folderId;
    folderForm.visibility = 'private';
    folderForm.clearErrors();
    createFolderOpen.value = true;
}

function closeCreateFolderDialog() {
    createFolderOpen.value = false;
    folderForm.reset('name');
    folderForm.clearErrors();
}

function createFolder() {
    if (!props.canManageCurrentLocation) {
        return;
    }

    folderForm.parent_folder_id = props.folderId;
    folderForm.post('/folders', {
        only: driveRefreshProps,
        preserveScroll: true,
        onSuccess: closeCreateFolderDialog,
    });
}

function updateFile(file: FileItem, changes: Record<string, string | null>) {
    router.patch(`/files/${file.id}`, changes, {
        only: driveRefreshProps,
        preserveScroll: true,
    });
}

function updateFolder(
    folder: FolderItem,
    changes: Record<string, string | null>,
) {
    router.patch(`/folders/${folder.id}`, changes, {
        only: driveRefreshProps,
        preserveScroll: true,
    });
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

    if (!target || !next) {
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

    if (!target) {
        return;
    }

    router.delete(
        target.kind === 'file'
            ? `/files/${target.item.id}`
            : `/folders/${target.item.id}`,
        {
            only: driveRefreshProps,
            preserveScroll: true,
        },
    );
    closeTrashDialog();
}

function manageAccess(target: AccessTarget) {
    accessTarget.value = target;
    accessValue.value = target.item.visibility;
}

function closeAccessDialog(force = false) {
    if (accessProcessing.value && !force) {
        return;
    }

    accessTarget.value = null;
    accessValue.value = 'private';
}

function submitAccess() {
    const target = accessTarget.value;

    if (!target) {
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
            only: driveRefreshProps,
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

function createShare(target: ShareTarget) {
    shareTarget.value = target;
    shareForm.expires_days = props.settings.shareExpiryDays ?? 7;
    shareForm.mode = 'download';
    shareForm.clearErrors();
}

function closeShareDialog() {
    shareTarget.value = null;
    shareForm.clearErrors();
}

function submitShare() {
    if (!shareTarget.value) {
        return;
    }

    const url =
        shareTarget.value.kind === 'file'
            ? `/files/${shareTarget.value.item.id}/shares`
            : `/folders/${shareTarget.value.item.id}/shares`;

    shareForm.post(url, {
        only: ['flash'],
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
    queueId: string,
    url: string,
    blob: Blob,
    contentType: string | null,
    onProgress: (loaded: number) => void,
): Promise<string> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const requests = activeUploadRequests.get(queueId) ?? new Set();
        requests.add(xhr);
        activeUploadRequests.set(queueId, requests);
        xhr.open('PUT', url);

        if (contentType) {
            xhr.setRequestHeader('Content-Type', contentType);
        }

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded);
            }
        };

        const removeRequest = () => {
            requests.delete(xhr);

            if (requests.size === 0) {
                activeUploadRequests.delete(queueId);
            }
        };

        xhr.onload = () => {
            removeRequest();

            if (xhr.status >= 200 && xhr.status < 300) {
                const etag = xhr.getResponseHeader('ETag');

                if (!etag) {
                    reject(
                        new Error(
                            'Storage did not return the uploaded part identifier.',
                        ),
                    );

                    return;
                }

                resolve(etag);

                return;
            }

            reject(new Error(`Upload failed with status ${xhr.status}`));
        };
        xhr.onerror = () => {
            removeRequest();
            reject(new Error('Upload failed before reaching storage.'));
        };
        xhr.onabort = () => {
            removeRequest();
            reject(new Error('Upload cancelled.'));
        };
        xhr.send(blob);
    });
}

function abortUploadRequests(queueId: string) {
    activeUploadRequests.get(queueId)?.forEach((request) => request.abort());
    activeUploadRequests.delete(queueId);
}

function retryDelay(attempt: number) {
    return new Promise((resolve) =>
        window.setTimeout(resolve, 750 * 2 ** (attempt - 1)),
    );
}

async function responseError(response: Response, fallback: string) {
    const body = (await response.json().catch(() => null)) as {
        message?: string;
    } | null;

    return body?.message || fallback;
}

async function cancelRemoteUpload(fileId: string) {
    const response = await fetch(`/api/files/${fileId}/cancel-upload`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    return response.ok;
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
        throw new Error(
            await responseError(
                response,
                'The app could not finalize this upload.',
            ),
        );
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
        throw new Error(
            await responseError(init, 'Upload rejected by workspace policy.'),
        );
    }

    const payload = await init.json();
    updateUpload(queueId, { remoteFileId: payload.fileId });

    if (!payload.multipart) {
        updateUpload(queueId, {
            status: 'uploading',
            message: 'Uploading to B2',
        });
        await uploadBlob(
            queueId,
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
        let etag = '';

        for (let attempt = 1; attempt <= 3; attempt += 1) {
            try {
                if (failedUploadQueues.has(queueId)) {
                    throw new Error(
                        'Upload stopped after another part failed.',
                    );
                }

                partProgress.set(partNumber, 0);
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
                    throw new Error(
                        await responseError(
                            partResponse,
                            `Could not sign part ${partNumber}.`,
                        ),
                    );
                }

                const partPayload = await partResponse.json();
                etag = await uploadBlob(
                    queueId,
                    partPayload.uploadUrl,
                    blob,
                    null,
                    (loaded) => {
                        partProgress.set(partNumber, loaded);
                        const uploadedBytes = Array.from(
                            partProgress.values(),
                        ).reduce((sum, current) => sum + current, 0);
                        updateUpload(queueId, {
                            uploadedBytes,
                            progress: Math.min(
                                95,
                                Math.round((uploadedBytes / file.size) * 95),
                            ),
                        });
                    },
                );
                updateUpload(queueId, {
                    message: `Uploading ${payload.totalParts} parts in parallel`,
                });

                break;
            } catch (error) {
                if (attempt === 3 || failedUploadQueues.has(queueId)) {
                    throw error;
                }

                updateUpload(queueId, {
                    message: `Retrying part ${partNumber} (${attempt + 1}/3)`,
                });
                await retryDelay(attempt);
            }
        }

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
    if (!props.canManageCurrentLocation) {
        return;
    }

    const files = Array.from(list);
    const queued = files.map((file) => {
        const item: UploadQueueItem = {
            id: `${file.name}-${file.size}-${crypto.randomUUID()}`,
            name: file.name,
            size: file.size,
            mimeType: file.type || null,
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
                failedUploadQueues.add(item.id);
                abortUploadRequests(item.id);
                const cleanedUp = item.remoteFileId
                    ? await cancelRemoteUpload(item.remoteFileId).catch(
                          () => false,
                      )
                    : false;
                updateUpload(item.id, {
                    status: 'error',
                    message:
                        (error instanceof Error
                            ? error.message
                            : 'Upload failed') +
                        (cleanedUp ? ' Upload session cleaned up.' : ''),
                });
            }
        },
    );

    router.reload({ only: driveRefreshProps });
}

function handleDrop(event: DragEvent) {
    dragging.value = false;

    if (props.canManageCurrentLocation && event.dataTransfer?.files) {
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

function revalidateDrive(force = false) {
    const now = Date.now();

    if (!force && now - lastRevalidatedAt < 15_000) {
        return;
    }

    lastRevalidatedAt = now;
    router.reload({ only: driveRefreshProps });
}

function revalidateWhenVisible() {
    if (document.visibilityState === 'visible') {
        revalidateDrive();
    }
}

function revalidateOnFocus() {
    revalidateDrive();
}

onMounted(() => {
    revalidateTimer = window.setTimeout(() => revalidateDrive(true), 350);
    window.addEventListener('focus', revalidateOnFocus);
    document.addEventListener('visibilitychange', revalidateWhenVisible);
});

onBeforeUnmount(() => {
    if (revalidateTimer !== null) {
        window.clearTimeout(revalidateTimer);
    }

    if (filterTimer !== null) {
        window.clearTimeout(filterTimer);
    }

    window.removeEventListener('focus', revalidateOnFocus);
    document.removeEventListener('visibilitychange', revalidateWhenVisible);
});

watch(
    () => props.filters,
    (filters) => {
        filterValues.value = {
            q: filters.q ?? '',
            visibility: filters.visibility ?? '',
            type: filters.type ?? '',
            sort: filters.sort ?? 'updated-desc',
        };
    },
);
</script>

<template>
    <Head title="Files" />
    <main
        class="space-y-6"
        @dragover.prevent="canManageCurrentLocation && (dragging = true)"
        @dragleave="dragging = false"
        @drop.prevent="handleDrop"
    >
        <PageHeader
            title="Files"
            description="Browse folders, upload directly to storage, share download links, and keep access tidy."
        >
            <template #actions>
                <DropdownMenu v-if="canManageCurrentLocation">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                        >
                            <Plus class="h-4 w-4" />
                            New
                            <ChevronDown class="h-4 w-4 opacity-70" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuItem @select="openFilePicker">
                            <FileUp class="h-4 w-4" />
                            Select files
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="openCreateFolderDialog">
                            <Folder class="h-4 w-4" />
                            Create folder
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>
        <input
            ref="fileInput"
            type="file"
            multiple
            class="hidden"
            @change="handleInput"
        />

        <section class="cloud-panel p-4 md:p-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                >
                    <Search class="h-4 w-4 text-brand" />
                    <input
                        :value="filterValues.q"
                        class="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        placeholder="Search files"
                        @input="
                            updateFilter(
                                'q',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
                <select
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                    :value="filterValues.visibility"
                    @change="
                        updateFilter(
                            'visibility',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">All access</option>
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                </select>
                <select
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                    :value="filterValues.type"
                    @change="
                        updateFilter(
                            'type',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">All types</option>
                    <option value="image/">Images</option>
                    <option value="application/pdf">PDFs</option>
                    <option value="video/">Videos</option>
                    <option value="text/">Text</option>
                </select>
                <select
                    class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                    :value="filterValues.sort"
                    @change="
                        updateFilter(
                            'sort',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="updated-desc">Newest</option>
                    <option value="updated-asc">Oldest</option>
                    <option value="name-asc">Name A-Z</option>
                    <option value="name-desc">Name Z-A</option>
                    <option value="size-desc">Largest files</option>
                    <option value="size-asc">Smallest files</option>
                </select>
                <button
                    v-if="activeFilters"
                    type="button"
                    class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                    @click="clearFilters"
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
            <div
                v-if="view === 'grid'"
                class="grid auto-rows-fr gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="folder in folderItems"
                    :key="folder.id"
                    class="flex min-h-48 flex-col rounded-2xl border border-line bg-white/70 p-4 dark:bg-white/10"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand/10 text-brand"
                        >
                            <Folder class="h-5 w-5" />
                        </span>
                        <div
                            v-if="folder.can_manage"
                            class="flex items-center gap-1"
                        >
                            <button
                                type="button"
                                class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                                title="Share"
                                @click="
                                    createShare({
                                        kind: 'folder',
                                        item: folder,
                                    })
                                "
                            >
                                <Share2 class="h-4 w-4" />
                            </button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                        aria-label="Folder actions"
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem
                                        @select="renameFolder(folder)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        Rename
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="
                                            manageAccess({
                                                kind: 'folder',
                                                item: folder,
                                            })
                                        "
                                    >
                                        <Users class="h-4 w-4" />
                                        Access
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="text-red-600 focus:text-red-600"
                                        @select="trashFolder(folder)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        Move to trash
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <Link
                        :href="`/files?folder=${folder.id}`"
                        class="mt-5 block min-w-0 truncate font-semibold text-ink-950 dark:text-white"
                        >{{ folder.name }}</Link
                    >
                    <div
                        class="mt-auto flex items-center justify-between gap-3 pt-5"
                    >
                        <StatusBadge :value="folder.visibility" />
                        <span class="dark:text-ink-400 text-xs text-ink-500">
                            {{ formatDate(folder.updated_at) }}
                        </span>
                    </div>
                </div>
                <div
                    v-for="file in fileItems"
                    :key="file.id"
                    class="flex min-h-56 flex-col rounded-2xl border border-line bg-white/70 p-4 dark:bg-white/10"
                >
                    <div class="flex items-start justify-between gap-3">
                        <FileTypeIcon
                            :name="file.display_name"
                            :mime-type="file.mime_type"
                        />
                        <div class="flex items-center gap-1">
                            <a
                                class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                                title="Download"
                                :href="`/api/files/${file.id}/download`"
                                ><Download class="h-4 w-4"
                            /></a>
                            <button
                                v-if="file.can_manage"
                                type="button"
                                class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                                title="Share"
                                @click="
                                    createShare({ kind: 'file', item: file })
                                "
                            >
                                <Share2 class="h-4 w-4" />
                            </button>
                            <DropdownMenu v-if="file.can_manage">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                        aria-label="File actions"
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem
                                        @select="renameFile(file)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        Rename
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="
                                            manageAccess({
                                                kind: 'file',
                                                item: file,
                                            })
                                        "
                                    >
                                        <Users class="h-4 w-4" />
                                        Access
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        variant="destructive"
                                        @select="trashFile(file)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        Move to trash
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <p
                        class="mt-5 min-w-0 truncate font-semibold text-ink-950 dark:text-white"
                    >
                        {{ file.display_name }}
                    </p>
                    <p
                        class="mt-1 truncate text-sm text-ink-600 dark:text-ink-300"
                    >
                        {{ formatBytes(file.size_bytes) }} ·
                        {{ formatFileType(file.display_name, file.mime_type) }}
                    </p>
                    <div
                        class="mt-auto flex items-center justify-between gap-3 pt-5"
                    >
                        <StatusBadge :value="file.visibility" />
                        <span class="dark:text-ink-400 text-xs text-ink-500">
                            {{ formatDate(file.updated_at) }}
                        </span>
                    </div>
                </div>
            </div>

            <div v-else class="divide-y divide-line">
                <div
                    v-for="folder in folderItems"
                    :key="folder.id"
                    class="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_auto_auto_auto] md:items-center"
                >
                    <Link
                        :href="`/files?folder=${folder.id}`"
                        class="flex min-w-0 items-center gap-3"
                    >
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand"
                        >
                            <Folder class="h-5 w-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate font-medium">
                                {{ folder.name }}
                            </span>
                            <span
                                class="block text-xs text-ink-600 dark:text-ink-300"
                            >
                                Folder · {{ formatDate(folder.updated_at) }}
                            </span>
                        </span>
                    </Link>
                    <StatusBadge :value="folder.visibility" />
                    <button
                        v-if="folder.can_manage"
                        type="button"
                        class="justify-self-start rounded-full p-2 text-brand hover:bg-ink-950/5 md:justify-self-end dark:hover:bg-white/10"
                        title="Share"
                        @click="
                            createShare({
                                kind: 'folder',
                                item: folder,
                            })
                        "
                    >
                        <Share2 class="h-4 w-4" />
                    </button>
                    <DropdownMenu v-if="folder.can_manage">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="justify-self-start rounded-full p-2 text-ink-600 hover:bg-ink-950/5 md:justify-self-end dark:text-ink-300 dark:hover:bg-white/10"
                                aria-label="Folder actions"
                            >
                                <MoreHorizontal class="h-4 w-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem @select="renameFolder(folder)">
                                <Pencil class="h-4 w-4" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                @select="
                                    manageAccess({
                                        kind: 'folder',
                                        item: folder,
                                    })
                                "
                            >
                                <Users class="h-4 w-4" />
                                Access
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                @select="trashFolder(folder)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Move to trash
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <div
                    v-for="file in fileItems"
                    :key="file.id"
                    class="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_7rem_auto_auto] md:items-center"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <FileTypeIcon
                            :name="file.display_name"
                            :mime-type="file.mime_type"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ file.display_name }}
                            </p>
                            <p class="text-xs text-ink-600 dark:text-ink-300">
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
                    <span class="text-sm text-ink-600 dark:text-ink-300">{{
                        formatBytes(file.size_bytes)
                    }}</span>
                    <StatusBadge :value="file.visibility" />
                    <div class="flex items-center gap-1 md:justify-end">
                        <a
                            class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                            title="Download"
                            :href="`/api/files/${file.id}/download`"
                            ><Download class="h-4 w-4"
                        /></a>
                        <button
                            v-if="file.can_manage"
                            type="button"
                            class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                            title="Share"
                            @click="createShare({ kind: 'file', item: file })"
                        >
                            <Share2 class="h-4 w-4" />
                        </button>
                        <DropdownMenu v-if="file.can_manage">
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10"
                                    aria-label="File actions"
                                >
                                    <MoreHorizontal class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-44">
                                <DropdownMenuItem @select="renameFile(file)">
                                    <Pencil class="h-4 w-4" />
                                    Rename
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @select="
                                        manageAccess({
                                            kind: 'file',
                                            item: file,
                                        })
                                    "
                                >
                                    <Users class="h-4 w-4" />
                                    Access
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="trashFile(file)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                    Move to trash
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <p
                    v-if="folderItems.length + fileItems.length === 0"
                    class="py-12 text-center text-sm text-ink-600 dark:text-ink-300"
                >
                    {{
                        canManageCurrentLocation
                            ? 'Drop files here or use New to add a folder.'
                            : 'No files are available in this view.'
                    }}
                </p>
            </div>
        </section>

        <section
            v-if="folders.links.length > 3 || files.links.length > 3"
            class="grid gap-3 md:grid-cols-2"
        >
            <div v-if="folders.links.length > 3" class="cloud-panel p-4">
                <p
                    class="mb-3 text-sm font-semibold text-ink-950 dark:text-white"
                >
                    Folders
                </p>
                <PaginationLinks :links="folders.links" />
            </div>
            <div v-if="files.links.length > 3" class="cloud-panel p-4">
                <p
                    class="mb-3 text-sm font-semibold text-ink-950 dark:text-white"
                >
                    Files
                </p>
                <PaginationLinks :links="files.links" />
            </div>
        </section>

        <Dialog
            :open="createFolderOpen"
            @update:open="($event) => !$event && closeCreateFolderDialog()"
        >
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="createFolder">
                    <DialogHeader>
                        <DialogTitle>Create folder</DialogTitle>
                        <DialogDescription>
                            Add a folder in the current drive location.
                        </DialogDescription>
                    </DialogHeader>
                    <label class="block space-y-2 text-sm font-medium">
                        <span>Name</span>
                        <input
                            v-model="folderForm.name"
                            class="w-full rounded-2xl border border-line bg-white px-4 py-3 text-sm transition outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-white/10"
                            autocomplete="off"
                            autofocus
                        />
                        <span
                            v-if="folderForm.errors.name"
                            class="text-xs text-red-600"
                            >{{ folderForm.errors.name }}</span
                        >
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-left transition"
                            :class="
                                folderForm.visibility === 'private'
                                    ? 'border-brand bg-brand/10 text-ink-950 dark:text-white'
                                    : 'dark:text-ink-200 border-line bg-white/70 text-ink-700 dark:bg-white/10'
                            "
                            @click="folderForm.visibility = 'private'"
                        >
                            <span class="block font-semibold">Private</span>
                            <span
                                class="mt-1 block text-sm text-ink-600 dark:text-ink-300"
                            >
                                Owner and admins.
                            </span>
                        </button>
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-left transition"
                            :class="
                                folderForm.visibility === 'workspace'
                                    ? 'border-brand bg-brand/10 text-ink-950 dark:text-white'
                                    : 'dark:text-ink-200 border-line bg-white/70 text-ink-700 dark:bg-white/10'
                            "
                            @click="folderForm.visibility = 'workspace'"
                        >
                            <span class="block font-semibold">Workspace</span>
                            <span
                                class="mt-1 block text-sm text-ink-600 dark:text-ink-300"
                            >
                                Signed-in members can view.
                            </span>
                        </button>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-2">
                        <button
                            type="button"
                            class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            :disabled="folderForm.processing"
                            @click="closeCreateFolderDialog"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                            :disabled="
                                folderForm.processing ||
                                folderForm.name.trim().length === 0
                            "
                        >
                            <Folder class="h-4 w-4" />
                            {{
                                folderForm.processing
                                    ? 'Creating'
                                    : 'Create folder'
                            }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

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
                            class="w-full rounded-2xl border border-line bg-white px-4 py-3 text-sm transition outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-white/10"
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
                                    : 'dark:text-ink-200 border-line bg-white/70 text-ink-700 dark:bg-white/10'
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
                                    : 'dark:text-ink-200 border-line bg-white/70 text-ink-700 dark:bg-white/10'
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
                            {{
                                shareTarget?.kind === 'file'
                                    ? shareTarget.item.display_name
                                    : shareTarget?.item.name
                            }}
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
                                    : 'dark:text-ink-200 border-line bg-white/70 text-ink-700 dark:bg-white/10'
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
                            class="w-full rounded-2xl border border-line bg-white px-4 py-3 text-sm transition outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-white/10"
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
                                    >Download access</span
                                >
                                <span class="text-ink-600 dark:text-ink-300">
                                    {{
                                        shareTarget?.kind === 'folder'
                                            ? 'Recipients can open this folder view and download ready files until the link expires or is revoked.'
                                            : 'Recipients can download this ready file until the link expires or is revoked.'
                                    }}
                                </span>
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
                            {{
                                shareForm.processing
                                    ? 'Creating'
                                    : 'Create link'
                            }}
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
                <span class="text-xs text-ink-600 dark:text-ink-300">
                    {{ uploadSummary }}
                </span>
            </div>
            <div class="mt-3 space-y-3">
                <div
                    v-for="upload in visibleUploads"
                    :key="upload.id"
                    class="rounded-[1.1rem] border border-line bg-white/70 p-3 dark:bg-white/10"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <FileTypeIcon
                                :name="upload.name"
                                :mime-type="upload.mimeType"
                            />
                            <div class="min-w-0">
                                <p
                                    class="truncate font-medium text-ink-950 dark:text-white"
                                >
                                    {{ upload.name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-ink-600 dark:text-ink-300"
                                >
                                    {{
                                        formatFileType(
                                            upload.name,
                                            upload.mimeType,
                                        )
                                    }}
                                    · {{ upload.message }} ·
                                    {{ formatBytes(upload.uploadedBytes) }} /
                                    {{ formatBytes(upload.size) }}
                                </p>
                            </div>
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
            <p
                v-if="hiddenUploadCount > 0"
                class="mt-3 text-xs text-ink-600 dark:text-ink-300"
            >
                +{{ hiddenUploadCount }} more in this batch
            </p>
        </div>
    </main>
</template>
