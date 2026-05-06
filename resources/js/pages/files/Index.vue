<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Download, File, Folder, Grid2X2, List, MoreHorizontal, Pencil, Plus, Search, Share2, Trash2, UploadCloud } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { formatBytes, formatDate } from '@/lib/format';

type FolderItem = { id: string; name: string; visibility: string; updated_at: string };
type FileItem = { id: string; display_name: string; visibility: string; size_bytes: number; mime_type: string; updated_at: string };
type UploadQueueItem = {
    id: string;
    name: string;
    size: number;
    uploadedBytes: number;
    progress: number;
    status: 'queued' | 'preparing' | 'uploading' | 'finalizing' | 'done' | 'error';
    message: string;
};

const props = defineProps<{
    folderId: string | null;
    breadcrumbs: Array<{ id: string; name: string }>;
    folders: FolderItem[];
    files: FileItem[];
    filters: { q?: string; visibility?: string; type?: string; sort?: string };
    settings: { maxUploadSizeBytes: number; blockedExtensions: string[]; parallelFileUploads?: number; parallelPartUploads?: number };
}>();

const view = ref(localStorage.getItem('cloud-drive-view') || 'list');
const uploadQueue = ref<UploadQueueItem[]>([]);
const dragging = ref(false);
const shareCopied = ref(false);
const folderForm = useForm({ name: '', parent_folder_id: props.folderId, visibility: 'private' });
const page = usePage<{ flash?: { shareUrl?: string; success?: string; error?: string } }>();

const activeFilters = computed(() => [props.filters.q, props.filters.visibility, props.filters.type].filter(Boolean).length);
const visibleUploads = computed(() => uploadQueue.value.filter((item) => item.status !== 'done').slice(-5));
const flash = computed(() => page.props.flash ?? {});

function setView(next: string) {
    view.value = next;
    localStorage.setItem('cloud-drive-view', next);
}

function updateFilters(key: string, value: string) {
    router.get('/files', { ...props.filters, [key]: value || undefined, folder: props.folderId || undefined }, { preserveState: true, replace: true });
}

function createFolder() {
    folderForm.parent_folder_id = props.folderId;
    folderForm.post('/folders', { preserveScroll: true, onSuccess: () => folderForm.reset('name') });
}

function updateFile(file: FileItem, changes: Record<string, string | null>) {
    router.patch(`/files/${file.id}`, changes, { preserveScroll: true });
}

function updateFolder(folder: FolderItem, changes: Record<string, string | null>) {
    router.patch(`/folders/${folder.id}`, changes, { preserveScroll: true });
}

function renameFile(file: FileItem) {
    const next = window.prompt('Rename file', file.display_name)?.trim();

    if (next && next !== file.display_name) {
        updateFile(file, { display_name: next });
    }
}

function renameFolder(folder: FolderItem) {
    const next = window.prompt('Rename folder', folder.name)?.trim();

    if (next && next !== folder.name) {
        updateFolder(folder, { name: next });
    }
}

function trashFile(file: FileItem) {
    if (window.confirm(`Move "${file.display_name}" to trash?`)) {
        router.delete(`/files/${file.id}`, { preserveScroll: true });
    }
}

function trashFolder(folder: FolderItem) {
    if (window.confirm(`Move "${folder.name}" to trash?`)) {
        router.delete(`/folders/${folder.id}`, { preserveScroll: true });
    }
}

function createShare(file: FileItem) {
    router.post(`/files/${file.id}/shares`, {}, { preserveScroll: true });
}

async function copyShareUrl() {
    if (!flash.value.shareUrl) {
        return;
    }

    await navigator.clipboard.writeText(flash.value.shareUrl);
    shareCopied.value = true;
    window.setTimeout(() => {
        shareCopied.value = false;
    }, 1800);
}

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function updateUpload(id: string, patch: Partial<UploadQueueItem>) {
    const item = uploadQueue.value.find((upload) => upload.id === id);

    if (item) {
        Object.assign(item, patch);
    }
}

async function runPool<T>(items: T[], concurrency: number, worker: (item: T, index: number) => Promise<void>) {
    let nextIndex = 0;
    const workers = Array.from({ length: Math.min(concurrency, items.length) }, async () => {
        while (nextIndex < items.length) {
            const index = nextIndex;
            nextIndex += 1;
            await worker(items[index], index);
        }
    });

    await Promise.all(workers);
}

function uploadBlob(url: string, blob: Blob, contentType: string | null, onProgress: (loaded: number) => void): Promise<string> {
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
        xhr.onerror = () => reject(new Error('Upload failed before reaching storage.'));
        xhr.onabort = () => reject(new Error('Upload cancelled.'));
        xhr.send(blob);
    });
}

async function completeUpload(fileId: string, body: Record<string, unknown>) {
    const response = await fetch(`/api/files/${fileId}/complete-upload`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('The app could not finalize this upload.');
    }
}

async function uploadOne(file: globalThis.File, queueId: string) {
    updateUpload(queueId, { status: 'preparing', message: 'Preparing signed URL' });
    const init = await fetch('/api/files/initiate-upload', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ name: file.name, size: file.size, type: file.type, folderId: props.folderId }),
    });

    if (!init.ok) {
        throw new Error('Upload rejected by workspace policy.');
    }

    const payload = await init.json();

    if (!payload.multipart) {
        updateUpload(queueId, { status: 'uploading', message: 'Uploading to B2' });
        await uploadBlob(payload.uploadUrl, file, file.type || 'application/octet-stream', (loaded) => {
            updateUpload(queueId, {
                uploadedBytes: loaded,
                progress: Math.min(95, Math.round((loaded / file.size) * 95)),
            });
        });
        updateUpload(queueId, { status: 'finalizing', message: 'Finalizing metadata', progress: 98 });
        await completeUpload(payload.fileId, {});
        updateUpload(queueId, { status: 'done', message: 'Done', progress: 100, uploadedBytes: file.size });

        return;
    }

    updateUpload(queueId, { status: 'uploading', message: `Uploading ${payload.totalParts} parts in parallel` });
    const partProgress = new Map<number, number>();
    const parts: Array<{ partNumber: number; etag: string }> = [];
    const partNumbers = Array.from({ length: payload.totalParts }, (_, index) => index + 1);
    const partConcurrency = Math.max(1, props.settings.parallelPartUploads ?? 4);

    await runPool(partNumbers, partConcurrency, async (partNumber) => {
        const start = (partNumber - 1) * payload.chunkSize;
        const blob = file.slice(start, Math.min(start + payload.chunkSize, file.size));
        const partResponse = await fetch(`/api/files/${payload.fileId}/multipart-part`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ partNumber }),
        });

        if (!partResponse.ok) {
            throw new Error(`Could not sign part ${partNumber}.`);
        }

        const partPayload = await partResponse.json();
        const etag = await uploadBlob(partPayload.uploadUrl, blob, null, (loaded) => {
            partProgress.set(partNumber, loaded);
            const uploadedBytes = Array.from(partProgress.values()).reduce((sum, current) => sum + current, 0);
            updateUpload(queueId, {
                uploadedBytes,
                progress: Math.min(95, Math.round((uploadedBytes / file.size) * 95)),
            });
        });

        parts.push({ partNumber, etag });
    });

    updateUpload(queueId, { status: 'finalizing', message: 'Combining parts', progress: 98 });
    await completeUpload(payload.fileId, { parts });
    updateUpload(queueId, { status: 'done', message: 'Done', progress: 100, uploadedBytes: file.size });
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

    await runPool(queued, Math.max(1, props.settings.parallelFileUploads ?? 2), async ({ file, item }) => {
        try {
            await uploadOne(file, item.id);
        } catch (error) {
            updateUpload(item.id, {
                status: 'error',
                message: error instanceof Error ? error.message : 'Upload failed',
            });
        }
    });

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
    <main class="space-y-6" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="handleDrop">
        <PageHeader title="Files" description="Browse folders, upload directly to storage, share download links, and keep access tidy.">
            <template #actions>
                <label class="cloud-button cursor-pointer bg-ink-950 text-white dark:bg-white dark:text-ink-950">
                    <UploadCloud class="h-4 w-4" />
                    Upload
                    <input type="file" multiple class="hidden" @change="handleInput" />
                </label>
            </template>
        </PageHeader>

        <section class="cloud-panel p-4 md:p-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-2 rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10">
                    <Search class="h-4 w-4 text-brand" />
                    <input :value="filters.q" class="min-w-0 flex-1 bg-transparent text-sm outline-none" placeholder="Search files" @input="updateFilters('q', ($event.target as HTMLInputElement).value)" />
                </div>
                <select class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10" :value="filters.visibility" @change="updateFilters('visibility', ($event.target as HTMLSelectElement).value)">
                    <option value="">All access</option>
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                </select>
                <button v-if="activeFilters" type="button" class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white" @click="router.get('/files', { folder: folderId || undefined })">Clear</button>
                <div class="flex rounded-full border border-line bg-white p-1 dark:bg-white/10">
                    <button type="button" class="rounded-full p-2" :class="view === 'list' ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950' : 'text-ink-600'" @click="setView('list')"><List class="h-4 w-4" /></button>
                    <button type="button" class="rounded-full p-2" :class="view === 'grid' ? 'bg-ink-950 text-white dark:bg-white dark:text-ink-950' : 'text-ink-600'" @click="setView('grid')"><Grid2X2 class="h-4 w-4" /></button>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm text-ink-600 dark:text-ink-300">
                <Link href="/files" class="font-medium text-brand">Home</Link>
                <template v-for="crumb in breadcrumbs" :key="crumb.id">
                    <span>/</span>
                    <Link :href="`/files?folder=${crumb.id}`" class="font-medium text-brand">{{ crumb.name }}</Link>
                </template>
            </div>
        </section>

        <section v-if="flash.shareUrl" class="cloud-panel flex flex-col gap-3 p-4 text-sm md:flex-row md:items-center md:justify-between">
            <a class="min-w-0 truncate font-medium text-brand" :href="flash.shareUrl" target="_blank" rel="noreferrer">{{ flash.shareUrl }}</a>
            <button type="button" class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white" @click="copyShareUrl">
                <Copy class="h-4 w-4" />
                {{ shareCopied ? 'Copied' : 'Copy' }}
            </button>
        </section>

        <section class="cloud-panel p-4 md:p-5" :class="dragging ? 'ring-2 ring-brand ring-offset-4 ring-offset-background' : ''">
            <form class="mb-5 flex flex-col gap-3 md:flex-row" @submit.prevent="createFolder">
                <input v-model="folderForm.name" class="rounded-full border border-line bg-white px-4 py-2 text-sm outline-none dark:bg-white/10" placeholder="New folder" />
                <select v-model="folderForm.visibility" class="rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10">
                    <option value="private">Private</option>
                    <option value="workspace">Workspace</option>
                </select>
                <button type="submit" class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950" :disabled="folderForm.processing">
                    <Plus class="h-4 w-4" />
                    Create
                </button>
            </form>

            <div v-if="view === 'grid'" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="folder in folders" :key="folder.id" class="rounded-[1.5rem] border border-line bg-white/70 p-4 dark:bg-white/10">
                    <Folder class="h-6 w-6 text-brand" />
                    <Link :href="`/files?folder=${folder.id}`" class="mt-5 block truncate font-semibold text-ink-950 dark:text-white">{{ folder.name }}</Link>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <StatusBadge :value="folder.visibility" />
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10" title="Rename" @click="renameFolder(folder)"><Pencil class="h-4 w-4" /></button>
                            <button type="button" class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10" title="Toggle access" @click="updateFolder(folder, { visibility: folder.visibility === 'private' ? 'workspace' : 'private' })"><MoreHorizontal class="h-4 w-4" /></button>
                            <button type="button" class="rounded-full p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" title="Move to trash" @click="trashFolder(folder)"><Trash2 class="h-4 w-4" /></button>
                        </div>
                    </div>
                </div>
                <div v-for="file in files" :key="file.id" class="rounded-[1.5rem] border border-line bg-white/70 p-4 dark:bg-white/10">
                    <File class="h-6 w-6 text-brand" />
                    <p class="mt-5 truncate font-semibold text-ink-950 dark:text-white">{{ file.display_name }}</p>
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">{{ formatBytes(file.size_bytes) }}</p>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <StatusBadge :value="file.visibility" />
                        <div class="flex items-center gap-2">
                            <a class="rounded-full p-2 text-brand hover:bg-ink-950/5 dark:hover:bg-white/10" title="Download" :href="`/api/files/${file.id}/download`"><Download class="h-4 w-4" /></a>
                            <button type="button" class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10" title="Share" @click="createShare(file)"><Share2 class="h-4 w-4" /></button>
                            <button type="button" class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10" title="Rename" @click="renameFile(file)"><Pencil class="h-4 w-4" /></button>
                            <button type="button" class="rounded-full p-2 text-ink-600 hover:bg-ink-950/5 dark:text-ink-300 dark:hover:bg-white/10" title="Toggle access" @click="updateFile(file, { visibility: file.visibility === 'private' ? 'workspace' : 'private' })"><MoreHorizontal class="h-4 w-4" /></button>
                            <button type="button" class="rounded-full p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" title="Move to trash" @click="trashFile(file)"><Trash2 class="h-4 w-4" /></button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="divide-y divide-line">
                <div v-for="folder in folders" :key="folder.id" class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto] md:items-center">
                    <Link :href="`/files?folder=${folder.id}`" class="flex min-w-0 items-center gap-3"><Folder class="h-5 w-5 text-brand" /><span class="truncate font-medium">{{ folder.name }}</span></Link>
                    <StatusBadge :value="folder.visibility" />
                    <div class="flex flex-wrap justify-start gap-3 text-sm md:justify-end">
                        <button type="button" class="text-brand" @click="renameFolder(folder)">Rename</button>
                        <button type="button" class="text-brand" @click="updateFolder(folder, { visibility: folder.visibility === 'private' ? 'workspace' : 'private' })">Access</button>
                        <button type="button" class="text-red-600" @click="trashFolder(folder)">Trash</button>
                    </div>
                </div>
                <div v-for="file in files" :key="file.id" class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto_auto] md:items-center">
                    <div class="flex min-w-0 items-center gap-3"><File class="h-5 w-5 text-brand" /><div class="min-w-0"><p class="truncate font-medium">{{ file.display_name }}</p><p class="text-xs text-ink-600 dark:text-ink-300">{{ file.mime_type }} · {{ formatDate(file.updated_at) }}</p></div></div>
                    <span class="text-sm text-ink-600 dark:text-ink-300">{{ formatBytes(file.size_bytes) }}</span>
                    <StatusBadge :value="file.visibility" />
                    <div class="flex flex-wrap gap-3 text-sm">
                        <a class="text-brand" :href="`/api/files/${file.id}/download`">Download</a>
                        <button type="button" class="text-brand" @click="createShare(file)">Share</button>
                        <button type="button" class="text-brand" @click="renameFile(file)">Rename</button>
                        <button type="button" class="text-brand" @click="updateFile(file, { visibility: file.visibility === 'private' ? 'workspace' : 'private' })">Access</button>
                        <button type="button" class="text-red-600" @click="trashFile(file)">Trash</button>
                    </div>
                </div>
                <p v-if="folders.length + files.length === 0" class="py-12 text-center text-sm text-ink-600 dark:text-ink-300">Drop files here or create a folder.</p>
            </div>
        </section>

        <div v-if="visibleUploads.length > 0" class="fixed right-4 bottom-4 w-[min(24rem,calc(100vw-2rem))] rounded-[1.5rem] border border-line bg-white/92 p-4 text-sm shadow-2xl backdrop-blur dark:bg-ink-900/92">
            <div class="flex items-center justify-between">
                <p class="font-semibold text-ink-950 dark:text-white">Uploads</p>
                <span class="text-xs text-ink-600 dark:text-ink-300">Parallel</span>
            </div>
            <div class="mt-3 space-y-3">
                <div v-for="upload in visibleUploads" :key="upload.id" class="rounded-[1.1rem] border border-line bg-white/70 p-3 dark:bg-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink-950 dark:text-white">{{ upload.name }}</p>
                            <p class="mt-0.5 text-xs text-ink-600 dark:text-ink-300">{{ upload.message }} · {{ formatBytes(upload.uploadedBytes) }} / {{ formatBytes(upload.size) }}</p>
                        </div>
                        <span class="text-xs font-semibold text-brand">{{ upload.progress }}%</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink-950/10 dark:bg-white/10">
                        <div class="h-full rounded-full transition-all duration-200" :class="upload.status === 'error' ? 'bg-red-500' : 'bg-brand'" :style="{ width: `${upload.progress}%` }" />
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>
