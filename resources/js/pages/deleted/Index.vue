<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { File, Folder, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDate } from '@/lib/format';

type DeletedFile = { id: string; display_name: string; deleted_at: string };
type DeletedFolder = { id: string; name: string; deleted_at: string };
type DeleteTarget =
    | { kind: 'file'; item: DeletedFile }
    | { kind: 'folder'; item: DeletedFolder };

defineProps<{
    files: Array<{ id: string; display_name: string; deleted_at: string }>;
    folders: Array<{ id: string; name: string; deleted_at: string }>;
    canHardDelete: boolean;
    retentionDays: number;
}>();

const deleteTarget = ref<DeleteTarget | null>(null);
const deleteDescription = computed(() => {
    if (! deleteTarget.value) {
        return '';
    }

    if (deleteTarget.value.kind === 'folder') {
        return `Permanently delete "${deleteTarget.value.item.name}" and its contents? This cannot be undone.`;
    }

    return `Permanently delete "${deleteTarget.value.item.display_name}"? This cannot be undone.`;
});

function hardDeleteFile(file: DeletedFile) {
    deleteTarget.value = { kind: 'file', item: file };
}

function hardDeleteFolder(folder: DeletedFolder) {
    deleteTarget.value = { kind: 'folder', item: folder };
}

function closeDeleteDialog() {
    deleteTarget.value = null;
}

function confirmHardDelete() {
    const target = deleteTarget.value;

    if (! target) {
        return;
    }

    router.delete(
        target.kind === 'file'
            ? `/deleted/files/${target.item.id}/hard-delete`
            : `/deleted/folders/${target.item.id}/hard-delete`,
        { preserveScroll: true },
    );
    closeDeleteDialog();
}
</script>

<template>
    <Head title="Trash" />
    <div class="space-y-6">
        <PageHeader
            title="Trash"
            :description="`Deleted items stay restorable for ${retentionDays} days unless an admin removes them.`"
        />
        <section class="cloud-panel divide-y divide-line p-5">
            <div
                v-for="folder in folders"
                :key="folder.id"
                class="flex items-center justify-between gap-4 py-4"
            >
                <div class="flex items-center gap-3">
                    <Folder class="h-5 w-5 text-brand" />
                    <div>
                        <p class="font-medium">{{ folder.name }}</p>
                        <p class="text-xs text-ink-600 dark:text-ink-300">
                            Deleted {{ formatDate(folder.deleted_at) }}
                        </p>
                    </div>
                </div>
                <div
                    class="flex flex-wrap justify-end gap-3 text-sm font-medium"
                >
                    <button
                        type="button"
                        class="text-brand"
                        @click="
                            router.patch(
                                `/deleted/folders/${folder.id}/restore`,
                            )
                        "
                    >
                        Restore
                    </button>
                    <button
                        v-if="canHardDelete"
                        type="button"
                        class="inline-flex items-center gap-1 text-red-600"
                        @click="hardDeleteFolder(folder)"
                    >
                        <Trash2 class="h-4 w-4" /> Delete forever
                    </button>
                </div>
            </div>
            <div
                v-for="file in files"
                :key="file.id"
                class="flex items-center justify-between gap-4 py-4"
            >
                <div class="flex items-center gap-3">
                    <File class="h-5 w-5 text-brand" />
                    <div>
                        <p class="font-medium">{{ file.display_name }}</p>
                        <p class="text-xs text-ink-600 dark:text-ink-300">
                            Deleted {{ formatDate(file.deleted_at) }}
                        </p>
                    </div>
                </div>
                <div
                    class="flex flex-wrap justify-end gap-3 text-sm font-medium"
                >
                    <button
                        type="button"
                        class="text-brand"
                        @click="
                            router.patch(`/deleted/files/${file.id}/restore`)
                        "
                    >
                        Restore
                    </button>
                    <button
                        v-if="canHardDelete"
                        type="button"
                        class="inline-flex items-center gap-1 text-red-600"
                        @click="hardDeleteFile(file)"
                    >
                        <Trash2 class="h-4 w-4" /> Delete forever
                    </button>
                </div>
            </div>
            <p
                v-if="files.length + folders.length === 0"
                class="py-10 text-center text-sm text-ink-600 dark:text-ink-300"
            >
                Trash is empty.
            </p>
        </section>

        <Dialog
            :open="deleteTarget !== null"
            @update:open="($event) => !$event && closeDeleteDialog()"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete forever</DialogTitle>
                    <DialogDescription>
                        {{ deleteDescription }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-2">
                    <button
                        type="button"
                        class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                        @click="closeDeleteDialog"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="cloud-button bg-red-600 text-white hover:bg-red-700"
                        @click="confirmHardDelete"
                    >
                        Delete forever
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
