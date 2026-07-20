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
    FolderUp,
    Grid2X2,
    List,
    LoaderCircle,
    MoreHorizontal,
    Pencil,
    Play,
    Plus,
    Search,
    Share2,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import {
    uploadsChangedEvent,
    useUploadManager,
} from '@/composables/useUploadManager';
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
const dragging = ref(false);
const createFolderOpen = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const folderInput = ref<HTMLInputElement | null>(null);
const folderPreparing = ref(false);
const shareCopyState = ref<'idle' | 'copied' | 'failed'>('idle');
const createdShareUrl = ref<string | null>(null);
const renameTarget = ref<RenameTarget | null>(null);
const renameValue = ref('');
const trashTarget = ref<TrashTarget | null>(null);
const shareTarget = ref<ShareTarget | null>(null);
const accessTarget = ref<AccessTarget | null>(null);
const accessValue = ref('private');
const accessProcessing = ref(false);
const previewTarget = ref<FileItem | null>(null);
const previewUrl = ref<string | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
const previewVideo = ref<HTMLVideoElement | null>(null);
const { queueFiles } = useUploadManager();
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
let uploadRefreshTimer: number | null = null;
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

function openFolderPicker() {
    if (!props.canManageCurrentLocation || folderPreparing.value) {
        return;
    }

    folderInput.value?.click();
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
    createdShareUrl.value = null;
    shareCopyState.value = 'idle';
    shareForm.expires_days = props.settings.shareExpiryDays ?? 7;
    shareForm.mode = 'download';
    shareForm.clearErrors();
}

function closeShareDialog() {
    if (shareForm.processing) {
        return;
    }

    shareTarget.value = null;
    createdShareUrl.value = null;
    shareCopyState.value = 'idle';
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
        onSuccess: () => {
            createdShareUrl.value = page.props.flash?.shareUrl ?? null;
        },
    });
}

async function copyShareUrl() {
    if (!createdShareUrl.value) {
        return;
    }

    shareCopyState.value = (await copyTextToClipboard(createdShareUrl.value))
        ? 'copied'
        : 'failed';
    window.setTimeout(() => {
        shareCopyState.value = 'idle';
    }, 4000);
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function relativePath(file: File): string {
    return (
        (
            file as File & {
                webkitRelativePath?: string;
            }
        ).webkitRelativePath?.replace(/^\/+/, '') ?? ''
    );
}

function directoryPath(file: File): string {
    const segments = relativePath(file).split('/');

    return segments.slice(0, -1).join('/');
}

async function uploadFolderFiles(list: FileList): Promise<void> {
    if (!props.canManageCurrentLocation || folderPreparing.value) {
        return;
    }

    const files = Array.from(list);

    if (files.length === 0) {
        toast.info('The selected folder does not contain uploadable files.');

        return;
    }

    const folderPaths = new Set<string>();

    for (const file of files) {
        const path = relativePath(file);
        const segments = path.split('/');

        if (!path || segments.length < 2) {
            toast.error('The browser did not provide this folder structure.');

            return;
        }

        for (let index = 1; index < segments.length; index += 1) {
            folderPaths.add(segments.slice(0, index).join('/'));
        }
    }

    folderPreparing.value = true;

    try {
        const response = await fetch('/api/folders/upload-tree', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                parent_folder_id: props.folderId,
                paths: Array.from(folderPaths),
            }),
        });
        const body = (await response.json().catch(() => null)) as {
            folders?: Record<string, string>;
            folderCount?: number;
            message?: string;
        } | null;

        if (!response.ok || !body?.folders) {
            throw new Error(
                body?.message ?? 'The selected folder could not be prepared.',
            );
        }

        const missingDestination = files.some(
            (file) => !body.folders?.[directoryPath(file)],
        );

        if (missingDestination) {
            throw new Error(
                'A nested upload destination could not be created.',
            );
        }

        queueFiles(files, {
            folderId: props.folderId,
            folderIdForFile: (file) =>
                body.folders?.[directoryPath(file)] ?? null,
            displayPathForFile: relativePath,
            maxUploadSizeBytes: props.settings.maxUploadSizeBytes,
            blockedExtensions: props.settings.blockedExtensions,
            parallelFileUploads: props.settings.parallelFileUploads,
            parallelPartUploads: props.settings.parallelPartUploads,
        });
        toast.success(
            `${body.folderCount ?? folderPaths.size} folder${(body.folderCount ?? folderPaths.size) === 1 ? '' : 's'} prepared. Uploads have started.`,
        );
        revalidateDrive(true);
    } catch (error) {
        toast.error(
            error instanceof Error
                ? error.message
                : 'The selected folder could not be prepared.',
        );
    } finally {
        folderPreparing.value = false;
    }
}

function uploadFiles(list: FileList) {
    if (!props.canManageCurrentLocation) {
        return;
    }

    queueFiles(Array.from(list), {
        folderId: props.folderId,
        maxUploadSizeBytes: props.settings.maxUploadSizeBytes,
        blockedExtensions: props.settings.blockedExtensions,
        parallelFileUploads: props.settings.parallelFileUploads,
        parallelPartUploads: props.settings.parallelPartUploads,
    });
}

function handleDrop(event: DragEvent) {
    dragging.value = false;

    if (props.canManageCurrentLocation && event.dataTransfer?.files) {
        uploadFiles(event.dataTransfer.files);
    }
}

function handleInput(event: Event) {
    const input = event.target as HTMLInputElement | null;

    if (input?.files) {
        uploadFiles(input.files);
        input.value = '';
    }
}

function handleFolderInput(event: Event) {
    const input = event.target as HTMLInputElement | null;

    if (input?.files) {
        void uploadFolderFiles(input.files);
        input.value = '';
    }
}

async function openVideoPreview(file: FileItem): Promise<void> {
    if (!file.mime_type.toLowerCase().startsWith('video/')) {
        return;
    }

    previewTarget.value = file;
    previewUrl.value = null;
    previewError.value = null;
    previewLoading.value = true;

    try {
        const response = await fetch(`/api/files/${file.id}/preview`, {
            headers: { Accept: 'application/json' },
        });
        const body = (await response.json().catch(() => null)) as {
            url?: string;
            message?: string;
        } | null;

        if (!response.ok || !body?.url) {
            throw new Error(
                body?.message ?? 'This video preview could not be prepared.',
            );
        }

        if (previewTarget.value?.id === file.id) {
            previewUrl.value = body.url;
        }
    } catch (error) {
        previewError.value =
            error instanceof Error
                ? error.message
                : 'This video preview could not be prepared.';
    } finally {
        previewLoading.value = false;
    }
}

function closeVideoPreview() {
    previewVideo.value?.pause();

    if (previewVideo.value) {
        previewVideo.value.removeAttribute('src');
        previewVideo.value.load();
    }

    previewTarget.value = null;
    previewUrl.value = null;
    previewError.value = null;
    previewLoading.value = false;
}

function markPreviewPlaybackError() {
    previewError.value =
        'This browser could not play the video format, or the storage connection was interrupted.';
}

function refreshAfterUploadChange() {
    if (uploadRefreshTimer !== null) {
        window.clearTimeout(uploadRefreshTimer);
    }

    uploadRefreshTimer = window.setTimeout(() => revalidateDrive(true), 600);
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
    window.addEventListener(uploadsChangedEvent, refreshAfterUploadChange);
    document.addEventListener('visibilitychange', revalidateWhenVisible);
});

onBeforeUnmount(() => {
    if (revalidateTimer !== null) {
        window.clearTimeout(revalidateTimer);
    }

    if (filterTimer !== null) {
        window.clearTimeout(filterTimer);
    }

    if (uploadRefreshTimer !== null) {
        window.clearTimeout(uploadRefreshTimer);
    }

    window.removeEventListener('focus', revalidateOnFocus);
    window.removeEventListener(uploadsChangedEvent, refreshAfterUploadChange);
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
                        <DropdownMenuItem
                            :disabled="folderPreparing"
                            @select="openFolderPicker"
                        >
                            <LoaderCircle
                                v-if="folderPreparing"
                                class="h-4 w-4 animate-spin"
                            />
                            <FolderUp v-else class="h-4 w-4" />
                            {{
                                folderPreparing
                                    ? 'Preparing folder'
                                    : 'Select folder'
                            }}
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
        <input
            ref="folderInput"
            type="file"
            multiple
            webkitdirectory
            class="hidden"
            @change="handleFolderInput"
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
                            <button
                                v-if="
                                    file.mime_type
                                        .toLowerCase()
                                        .startsWith('video/')
                                "
                                type="button"
                                class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                                title="Preview video"
                                @click="openVideoPreview(file)"
                            >
                                <Play class="h-4 w-4" />
                            </button>
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
                        <button
                            v-if="
                                file.mime_type
                                    .toLowerCase()
                                    .startsWith('video/')
                            "
                            type="button"
                            class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10"
                            title="Preview video"
                            @click="openVideoPreview(file)"
                        >
                            <Play class="h-4 w-4" />
                        </button>
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
            :open="previewTarget !== null"
            @update:open="($event) => !$event && closeVideoPreview()"
        >
            <DialogContent class="overflow-hidden p-0 sm:max-w-4xl">
                <DialogHeader class="px-6 pt-6 pr-14">
                    <DialogTitle>Video preview</DialogTitle>
                    <DialogDescription class="truncate">
                        {{ previewTarget?.display_name }}
                    </DialogDescription>
                </DialogHeader>
                <div
                    class="mx-4 mb-2 flex aspect-video items-center justify-center overflow-hidden rounded-lg bg-black sm:mx-6"
                >
                    <div
                        v-if="previewLoading"
                        class="flex flex-col items-center gap-3 text-sm text-white/75"
                    >
                        <LoaderCircle class="h-6 w-6 animate-spin" />
                        Preparing secure preview
                    </div>
                    <div
                        v-else-if="previewError"
                        role="alert"
                        class="max-w-md px-6 text-center text-sm text-white/80"
                    >
                        <AlertCircle
                            class="mx-auto mb-3 h-7 w-7 text-amber-300"
                        />
                        {{ previewError }}
                    </div>
                    <video
                        v-else-if="previewUrl"
                        ref="previewVideo"
                        :key="previewUrl"
                        :src="previewUrl"
                        class="h-full w-full bg-black object-contain"
                        controls
                        playsinline
                        preload="metadata"
                        @error="markPreviewPlaybackError"
                    >
                        This browser cannot play the selected video.
                    </video>
                </div>
                <DialogFooter class="gap-2 px-6 pb-6 sm:gap-2">
                    <button
                        type="button"
                        class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                        @click="closeVideoPreview"
                    >
                        Close
                    </button>
                    <a
                        v-if="previewTarget"
                        class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
                        :href="`/api/files/${previewTarget.id}/download`"
                    >
                        <Download class="h-4 w-4" />
                        Download
                    </a>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="shareTarget !== null"
            @update:open="($event) => !$event && closeShareDialog()"
        >
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-5" @submit.prevent="submitShare">
                    <DialogHeader>
                        <DialogTitle>
                            {{
                                createdShareUrl
                                    ? 'Share link ready'
                                    : 'Create share link'
                            }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                shareTarget?.kind === 'file'
                                    ? shareTarget.item.display_name
                                    : shareTarget?.item.name
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <div
                        v-if="createdShareUrl"
                        class="space-y-3 rounded-2xl border border-line bg-white/70 p-4 dark:bg-white/10"
                    >
                        <p class="text-sm text-ink-600 dark:text-ink-300">
                            Anyone with this link can download the shared item
                            until it expires or is revoked.
                        </p>
                        <a
                            :href="createdShareUrl"
                            target="_blank"
                            rel="noreferrer"
                            class="block truncate rounded-xl border border-line bg-background px-3 py-2 text-sm font-medium text-brand"
                        >
                            {{ createdShareUrl }}
                        </a>
                        <div class="flex flex-wrap gap-2">
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
                            <a
                                :href="createdShareUrl"
                                target="_blank"
                                rel="noreferrer"
                                class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            >
                                Open link
                            </a>
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
                    <template v-else>
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
                                    >{{ days }} day{{
                                        days === 1 ? '' : 's'
                                    }}</span
                                >
                                <span
                                    class="text-xs text-ink-600 dark:text-ink-300"
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
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <span>
                                    <span
                                        class="block font-semibold text-ink-950 dark:text-white"
                                        >Download access</span
                                    >
                                    <span
                                        class="text-ink-600 dark:text-ink-300"
                                    >
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
                    </template>
                    <DialogFooter class="gap-2 sm:gap-2">
                        <button
                            type="button"
                            class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                            @click="closeShareDialog"
                        >
                            {{ createdShareUrl ? 'Done' : 'Cancel' }}
                        </button>
                        <button
                            v-if="!createdShareUrl"
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
    </main>
</template>
