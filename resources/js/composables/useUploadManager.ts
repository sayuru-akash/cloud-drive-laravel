import { computed, markRaw, ref } from 'vue';

export const uploadsChangedEvent = 'cloud-drive:uploads-changed';

export type UploadQueueStatus =
    | 'queued'
    | 'preparing'
    | 'uploading'
    | 'finalizing'
    | 'cancelling'
    | 'done'
    | 'error'
    | 'cancelled';

export type UploadQueueItem = {
    id: string;
    name: string;
    size: number;
    uploadedBytes: number;
    progress: number;
    status: UploadQueueStatus;
    mimeType: string | null;
    message: string;
    remoteFileId?: string;
    file: File;
    folderId: string | null;
    partConcurrency: number;
};

type QueueOptions = {
    folderId: string | null;
    maxUploadSizeBytes: number;
    blockedExtensions: string[];
    parallelFileUploads?: number;
    parallelPartUploads?: number;
};

type InitiateUploadResponse = {
    fileId: string;
    multipart: boolean;
    uploadUrl: string | null;
    chunkSize: number;
    totalParts: number;
};

const uploads = ref<UploadQueueItem[]>([]);
const expanded = ref(true);
const activeUploadRequests = new Map<string, Set<XMLHttpRequest>>();
const cancelledUploads = new Set<string>();
const stoppedUploads = new Set<string>();
let activeFileUploads = 0;
let parallelFileUploads = 2;

const inProgressStatuses: UploadQueueStatus[] = [
    'queued',
    'preparing',
    'uploading',
    'finalizing',
    'cancelling',
];

const hasInProgress = computed(() =>
    uploads.value.some((upload) => inProgressStatuses.includes(upload.status)),
);
const canDismiss = computed(
    () => uploads.value.length > 0 && !hasInProgress.value,
);
const aggregateProgress = computed(() => {
    const relevant = uploads.value.filter(
        (upload) => upload.status !== 'cancelled',
    );
    const totalBytes = relevant.reduce((sum, upload) => sum + upload.size, 0);

    if (totalBytes === 0) {
        return hasInProgress.value ? 0 : 100;
    }

    const uploadedBytes = relevant.reduce(
        (sum, upload) => sum + Math.min(upload.uploadedBytes, upload.size),
        0,
    );

    return Math.min(100, Math.round((uploadedBytes / totalBytes) * 100));
});
const summary = computed(() => {
    const active = uploads.value.filter((upload) =>
        ['preparing', 'uploading', 'finalizing', 'cancelling'].includes(
            upload.status,
        ),
    ).length;
    const queued = uploads.value.filter(
        (upload) => upload.status === 'queued',
    ).length;
    const completed = uploads.value.filter(
        (upload) => upload.status === 'done',
    ).length;
    const failed = uploads.value.filter(
        (upload) => upload.status === 'error',
    ).length;
    const cancelled = uploads.value.filter(
        (upload) => upload.status === 'cancelled',
    ).length;

    return [
        active ? `${active} active` : '',
        queued ? `${queued} queued` : '',
        completed ? `${completed} complete` : '',
        failed ? `${failed} failed` : '',
        cancelled ? `${cancelled} cancelled` : '',
    ]
        .filter(Boolean)
        .join(' · ');
});

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function updateUpload(id: string, patch: Partial<UploadQueueItem>): void {
    const upload = uploads.value.find((candidate) => candidate.id === id);

    if (upload) {
        Object.assign(upload, patch);
    }
}

function notifyUploadDataChanged(): void {
    window.dispatchEvent(new CustomEvent(uploadsChangedEvent));
}

async function responseError(
    response: Response,
    fallback: string,
): Promise<string> {
    const body = (await response.json().catch(() => null)) as {
        message?: string;
    } | null;

    if (response.status === 419) {
        return 'Your session expired. Sign in again, then retry this upload.';
    }

    return body?.message || fallback;
}

function uploadBlob(
    queueId: string,
    url: string,
    blob: Blob,
    contentType: string | null,
    requireEtag: boolean,
    onProgress: (loaded: number) => void,
): Promise<string | null> {
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

        const removeRequest = (): void => {
            requests.delete(xhr);

            if (requests.size === 0) {
                activeUploadRequests.delete(queueId);
            }
        };

        xhr.onload = () => {
            removeRequest();

            if (xhr.status >= 200 && xhr.status < 300) {
                const etag = xhr.getResponseHeader('ETag');

                if (requireEtag && !etag) {
                    reject(
                        new Error(
                            'Storage completed the part but did not expose its ETag. Check bucket CORS and retry.',
                        ),
                    );

                    return;
                }

                resolve(etag);

                return;
            }

            reject(
                new Error(
                    xhr.status === 0
                        ? 'Storage could not be reached. Check your connection and retry.'
                        : `Storage rejected the upload with status ${xhr.status}.`,
                ),
            );
        };
        xhr.onerror = () => {
            removeRequest();
            reject(
                new Error(
                    'Upload failed before reaching storage. Check your connection and bucket CORS, then retry.',
                ),
            );
        };
        xhr.onabort = () => {
            removeRequest();
            reject(new Error('Upload cancelled.'));
        };
        xhr.send(blob);
    });
}

function abortUploadRequests(queueId: string): void {
    activeUploadRequests.get(queueId)?.forEach((request) => request.abort());
    activeUploadRequests.delete(queueId);
}

function retryDelay(attempt: number): Promise<void> {
    return new Promise((resolve) =>
        window.setTimeout(resolve, 750 * 2 ** (attempt - 1)),
    );
}

function assertUploadMayContinue(queueId: string): void {
    if (cancelledUploads.has(queueId) || stoppedUploads.has(queueId)) {
        throw new Error('Upload cancelled.');
    }
}

async function cancelRemoteUpload(fileId: string): Promise<boolean> {
    const response = await fetch(`/api/files/${fileId}/cancel-upload`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    return response.ok || response.status === 409;
}

async function completeUpload(
    fileId: string,
    body: Record<string, unknown>,
): Promise<void> {
    let failureMessage = 'The app could not finalize this upload.';

    for (let attempt = 1; attempt <= 3; attempt += 1) {
        let response: Response;

        try {
            response = await fetch(`/api/files/${fileId}/complete-upload`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });
        } catch (error) {
            failureMessage =
                error instanceof Error
                    ? error.message
                    : 'The connection was interrupted while finalizing this upload.';

            if (attempt === 3) {
                throw new Error(failureMessage);
            }

            await retryDelay(attempt);

            continue;
        }

        if (response.ok) {
            return;
        }

        failureMessage = await responseError(
            response,
            'The app could not finalize this upload.',
        );
        const retryable =
            response.status >= 500 || [408, 425, 429].includes(response.status);

        if (!retryable || attempt === 3) {
            throw new Error(failureMessage);
        }

        await retryDelay(attempt);
    }

    throw new Error(failureMessage);
}

async function uploadOne(upload: UploadQueueItem): Promise<void> {
    updateUpload(upload.id, {
        status: 'preparing',
        message: 'Preparing secure upload',
    });
    const init = await fetch('/api/files/initiate-upload', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            name: upload.file.name,
            size: upload.file.size,
            type: upload.file.type,
            folderId: upload.folderId,
        }),
    });

    if (!init.ok) {
        throw new Error(
            await responseError(init, 'Upload rejected by workspace policy.'),
        );
    }

    const payload = (await init.json()) as InitiateUploadResponse;
    updateUpload(upload.id, { remoteFileId: payload.fileId });
    notifyUploadDataChanged();
    assertUploadMayContinue(upload.id);

    if (!payload.multipart) {
        if (!payload.uploadUrl) {
            throw new Error('Storage did not provide an upload URL.');
        }

        updateUpload(upload.id, {
            status: 'uploading',
            message: 'Uploading to storage',
        });
        await uploadBlob(
            upload.id,
            payload.uploadUrl,
            upload.file,
            upload.file.type || 'application/octet-stream',
            false,
            (loaded) => {
                updateUpload(upload.id, {
                    uploadedBytes: loaded,
                    progress: Math.min(
                        96,
                        Math.round((loaded / upload.file.size) * 96),
                    ),
                });
            },
        );
        updateUpload(upload.id, {
            status: 'finalizing',
            message: 'Verifying upload',
            progress: 98,
        });
        await completeUpload(payload.fileId, {});
        updateUpload(upload.id, {
            status: 'done',
            message: 'Upload complete',
            progress: 100,
            uploadedBytes: upload.file.size,
        });

        return;
    }

    updateUpload(upload.id, {
        status: 'uploading',
        message: `Uploading ${payload.totalParts} parts`,
    });
    const partProgress = new Map<number, number>();
    const parts: Array<{ partNumber: number; etag: string }> = [];
    const partNumbers = Array.from(
        { length: payload.totalParts },
        (_, index) => index + 1,
    );

    await runPool(partNumbers, upload.partConcurrency, async (partNumber) => {
        const start = (partNumber - 1) * payload.chunkSize;
        const blob = upload.file.slice(
            start,
            Math.min(start + payload.chunkSize, upload.file.size),
        );
        let etag: string | null = null;

        for (let attempt = 1; attempt <= 3; attempt += 1) {
            try {
                assertUploadMayContinue(upload.id);
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

                const partPayload = (await partResponse.json()) as {
                    uploadUrl: string;
                };
                assertUploadMayContinue(upload.id);
                etag = await uploadBlob(
                    upload.id,
                    partPayload.uploadUrl,
                    blob,
                    null,
                    true,
                    (loaded) => {
                        partProgress.set(partNumber, loaded);
                        const uploadedBytes = Array.from(
                            partProgress.values(),
                        ).reduce((sum, current) => sum + current, 0);
                        updateUpload(upload.id, {
                            uploadedBytes,
                            progress: Math.min(
                                96,
                                Math.round(
                                    (uploadedBytes / upload.file.size) * 96,
                                ),
                            ),
                        });
                    },
                );

                break;
            } catch (error) {
                if (
                    attempt === 3 ||
                    cancelledUploads.has(upload.id) ||
                    stoppedUploads.has(upload.id)
                ) {
                    throw error;
                }

                updateUpload(upload.id, {
                    message: `Retrying part ${partNumber} (${attempt + 1}/3)`,
                });
                await retryDelay(attempt);
            }
        }

        if (!etag) {
            throw new Error(`Part ${partNumber} did not return an ETag.`);
        }

        parts.push({ partNumber, etag });
        updateUpload(upload.id, {
            message: `Uploading ${payload.totalParts} parts`,
        });
    });

    assertUploadMayContinue(upload.id);
    updateUpload(upload.id, {
        status: 'finalizing',
        message: 'Combining and verifying parts',
        progress: 98,
    });
    parts.sort((left, right) => left.partNumber - right.partNumber);
    await completeUpload(payload.fileId, { parts });
    updateUpload(upload.id, {
        status: 'done',
        message: 'Upload complete',
        progress: 100,
        uploadedBytes: upload.file.size,
    });
}

async function runPool<T>(
    items: T[],
    concurrency: number,
    worker: (item: T) => Promise<void>,
): Promise<void> {
    let nextIndex = 0;
    const workers = Array.from(
        { length: Math.min(Math.max(1, concurrency), items.length) },
        async () => {
            while (nextIndex < items.length) {
                const index = nextIndex;
                nextIndex += 1;
                await worker(items[index]);
            }
        },
    );

    await Promise.all(workers);
}

async function processUpload(upload: UploadQueueItem): Promise<void> {
    try {
        await uploadOne(upload);
    } catch (error) {
        stoppedUploads.add(upload.id);
        abortUploadRequests(upload.id);
        const wasCancelled = cancelledUploads.has(upload.id);
        const cleanedUp = upload.remoteFileId
            ? await cancelRemoteUpload(upload.remoteFileId).catch(() => false)
            : false;

        updateUpload(upload.id, {
            status: wasCancelled ? 'cancelled' : 'error',
            message: wasCancelled
                ? cleanedUp
                    ? 'Upload cancelled and storage cleaned up'
                    : 'Upload cancelled; temporary data will expire automatically'
                : `${error instanceof Error ? error.message : 'Upload failed'}${cleanedUp ? ' Temporary storage was cleaned up.' : ''}`,
            remoteFileId: undefined,
        });
    } finally {
        cancelledUploads.delete(upload.id);
        stoppedUploads.delete(upload.id);
        activeFileUploads -= 1;
        notifyUploadDataChanged();
        scheduleUploads();
    }
}

function scheduleUploads(): void {
    while (activeFileUploads < parallelFileUploads) {
        const nextUpload = uploads.value.find(
            (upload) => upload.status === 'queued',
        );

        if (!nextUpload) {
            return;
        }

        activeFileUploads += 1;
        updateUpload(nextUpload.id, {
            status: 'preparing',
            message: 'Preparing secure upload',
        });
        void processUpload(nextUpload);
    }
}

function validationError(file: File, options: QueueOptions): string | null {
    if (file.size < 1) {
        return 'Empty files cannot be uploaded.';
    }

    if (file.size > options.maxUploadSizeBytes) {
        return 'This file is larger than the workspace upload limit.';
    }

    const extension = file.name.includes('.')
        ? file.name.split('.').pop()?.toLowerCase()
        : null;

    if (
        extension &&
        options.blockedExtensions
            .map((value) => value.toLowerCase())
            .includes(extension)
    ) {
        return `.${extension} files are blocked by workspace policy.`;
    }

    return null;
}

function queueFiles(files: File[], options: QueueOptions): void {
    if (files.length === 0) {
        return;
    }

    parallelFileUploads = Math.max(1, options.parallelFileUploads ?? 2);
    expanded.value = true;

    files.forEach((file) => {
        const error = validationError(file, options);
        uploads.value.push({
            id: `${file.name}-${file.size}-${crypto.randomUUID()}`,
            name: file.name,
            size: file.size,
            mimeType: file.type || null,
            uploadedBytes: 0,
            progress: 0,
            status: error ? 'error' : 'queued',
            message: error ?? 'Queued',
            file: markRaw(file),
            folderId: options.folderId,
            partConcurrency: Math.max(1, options.parallelPartUploads ?? 2),
        });
    });

    scheduleUploads();
}

function cancelUpload(id: string): void {
    const upload = uploads.value.find((candidate) => candidate.id === id);

    if (
        !upload ||
        !['queued', 'preparing', 'uploading'].includes(upload.status)
    ) {
        return;
    }

    cancelledUploads.add(id);

    if (upload.status === 'queued') {
        updateUpload(id, {
            status: 'cancelled',
            message: 'Removed from the upload queue',
        });
        cancelledUploads.delete(id);
        notifyUploadDataChanged();

        return;
    }

    updateUpload(id, {
        status: 'cancelling',
        message: 'Cancelling and cleaning up',
    });
    abortUploadRequests(id);
}

function retryUpload(id: string): void {
    const upload = uploads.value.find((candidate) => candidate.id === id);

    if (!upload || upload.status !== 'error') {
        return;
    }

    updateUpload(id, {
        uploadedBytes: 0,
        progress: 0,
        status: 'queued',
        message: 'Queued to retry',
        remoteFileId: undefined,
    });
    expanded.value = true;
    scheduleUploads();
}

function removeUpload(id: string): void {
    const upload = uploads.value.find((candidate) => candidate.id === id);

    if (!upload || inProgressStatuses.includes(upload.status)) {
        return;
    }

    uploads.value = uploads.value.filter((candidate) => candidate.id !== id);
}

function dismissUploads(): void {
    if (!canDismiss.value) {
        return;
    }

    uploads.value = [];
}

function setExpanded(value: boolean): void {
    expanded.value = value;
}

function bindUploadUnloadGuard(): () => void {
    const guard = (event: BeforeUnloadEvent): string | undefined => {
        if (!hasInProgress.value) {
            return undefined;
        }

        event.preventDefault();
        event.returnValue = true;

        return '';
    };

    window.addEventListener('beforeunload', guard);

    return () => window.removeEventListener('beforeunload', guard);
}

export function useUploadManager() {
    return {
        uploads,
        expanded,
        hasInProgress,
        canDismiss,
        aggregateProgress,
        summary,
        queueFiles,
        cancelUpload,
        retryUpload,
        removeUpload,
        dismissUploads,
        setExpanded,
        bindUploadUnloadGuard,
    };
}
