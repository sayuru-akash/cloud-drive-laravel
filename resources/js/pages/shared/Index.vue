<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    Check,
    Copy,
    ExternalLink,
    Link2,
    LoaderCircle,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import { copyTextToClipboard } from '@/lib/clipboard';
import { formatDate } from '@/lib/format';

type ShareItem = {
    id: string;
    resource_id: string;
    mode: string;
    status: 'active' | 'expired' | 'revoked' | 'unavailable';
    public_url: string | null;
    is_revoked: boolean;
    expires_at: string | null;
    created_at: string;
    creator?: { name: string | null; email: string | null } | null;
    file?: { display_name: string; size_bytes: number; mime_type: string } | null;
};

defineProps<{
    shares: {
        data: ShareItem[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
}>();
const page = usePage<{ flash?: { shareUrl?: string } }>();
const flash = computed(() => page.props.flash ?? {});
const flashCopyState = ref<'idle' | 'copied' | 'failed'>('idle');
const copiedShareId = ref<string | null>(null);
const failedShareId = ref<string | null>(null);
const revokeTarget = ref<ShareItem | null>(null);
const revokeProcessing = ref(false);
const sharesRefreshProps = ['shares', 'flash'];

function resetFlashCopyState() {
    window.setTimeout(() => {
        flashCopyState.value = 'idle';
    }, 4000);
}

async function copyShareUrl() {
    if (!flash.value.shareUrl) {
        return;
    }

    flashCopyState.value = (await copyTextToClipboard(flash.value.shareUrl))
        ? 'copied'
        : 'failed';
    resetFlashCopyState();
}

async function copyStoredShareUrl(share: ShareItem) {
    if (! share.public_url) {
        return;
    }

    const copied = await copyTextToClipboard(share.public_url);
    copiedShareId.value = copied ? share.id : null;
    failedShareId.value = copied ? null : share.id;
    window.setTimeout(() => {
        copiedShareId.value = null;
        failedShareId.value = null;
    }, 4000);
}

function revokeShare(share: ShareItem) {
    revokeTarget.value = share;
}

function closeRevokeDialog() {
    if (revokeProcessing.value) {
        return;
    }

    revokeTarget.value = null;
}

function confirmRevoke() {
    if (!revokeTarget.value) {
        return;
    }

    router.patch(
        `/shares/${revokeTarget.value.id}/revoke`,
        {},
        {
            only: sharesRefreshProps,
            preserveScroll: true,
            onStart: () => {
                revokeProcessing.value = true;
            },
            onSuccess: () => {
                revokeTarget.value = null;
            },
            onFinish: () => {
                revokeProcessing.value = false;
            },
        },
    );
}

</script>

<template>
    <Head title="Shared" />
    <div class="space-y-6">
        <PageHeader
            title="Shared links"
            description="Download-only links with expiry and revoke controls."
        />
        <div
            v-if="flash.shareUrl"
            class="cloud-panel flex flex-col gap-4 p-4 text-sm text-ink-700 md:flex-row md:items-center md:justify-between dark:text-ink-300"
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
                        <ExternalLink class="h-4 w-4" />
                        Open
                    </a>
                    <button
                        type="button"
                        class="cloud-button transition"
                        :class="
                            flashCopyState === 'copied'
                                ? 'bg-emerald-600 text-white'
                                : flashCopyState === 'failed'
                                  ? 'bg-red-600 text-white'
                                  : 'bg-ink-950 text-white dark:bg-white dark:text-ink-950'
                        "
                        @click="copyShareUrl"
                    >
                        <Check
                            v-if="flashCopyState === 'copied'"
                            class="h-4 w-4"
                        />
                        <AlertCircle
                            v-else-if="flashCopyState === 'failed'"
                            class="h-4 w-4"
                        />
                        <Copy v-else class="h-4 w-4" />
                        {{
                            flashCopyState === 'copied'
                                ? 'Copied'
                                : flashCopyState === 'failed'
                                  ? 'Copy failed'
                                  : 'Copy link'
                        }}
                    </button>
                </div>
                <p
                    v-if="flashCopyState !== 'idle'"
                    class="text-xs font-medium"
                    :class="
                        flashCopyState === 'copied'
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-red-600 dark:text-red-300'
                    "
                >
                    {{
                        flashCopyState === 'copied'
                            ? 'Copied to clipboard.'
                            : 'Your browser blocked clipboard access.'
                    }}
                </p>
            </div>
        </div>
        <section class="cloud-panel divide-y divide-line p-5">
            <div
                v-for="share in shares.data"
                :key="share.id"
                class="grid gap-4 py-4 md:grid-cols-[1fr_auto_auto_auto] md:items-center"
            >
                <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-3">
                        <Link2 class="h-5 w-5 shrink-0 text-brand" />
                        <p
                            class="truncate font-medium text-ink-950 dark:text-white"
                        >
                            {{ share.file?.display_name ?? share.resource_id }}
                        </p>
                    </div>
                    <p
                        class="mt-1 text-xs text-ink-600 dark:text-ink-300"
                    >
                        Created {{ formatDate(share.created_at) }} · Expires
                        {{ formatDate(share.expires_at) }}
                    </p>
                    <p
                        v-if="share.creator"
                        class="mt-1 text-xs text-ink-600 dark:text-ink-300"
                    >
                        {{ share.creator.name ?? share.creator.email }}
                    </p>
                </div>
                <StatusBadge :value="share.status" />
                <div class="flex flex-wrap gap-2 md:justify-end">
                    <a
                        v-if="share.public_url"
                        :href="share.public_url"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex items-center gap-2 text-sm font-medium text-brand"
                    >
                        <ExternalLink class="h-4 w-4" />
                        Open
                    </a>
                    <button
                        v-if="share.public_url"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            copiedShareId === share.id
                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200'
                                : failedShareId === share.id
                                  ? 'bg-red-50 text-red-700 dark:bg-red-400/15 dark:text-red-200'
                                  : 'text-brand hover:bg-emerald-50 dark:hover:bg-emerald-400/10'
                        "
                        @click="copyStoredShareUrl(share)"
                    >
                        <Check
                            v-if="copiedShareId === share.id"
                            class="h-4 w-4"
                        />
                        <AlertCircle
                            v-else-if="failedShareId === share.id"
                            class="h-4 w-4"
                        />
                        <Copy v-else class="h-4 w-4" />
                        {{
                            copiedShareId === share.id
                                ? 'Copied'
                                : failedShareId === share.id
                                  ? 'Blocked'
                                  : 'Copy'
                        }}
                    </button>
                    <span
                        v-if="! share.public_url"
                        class="text-sm text-ink-500 dark:text-ink-400"
                    >
                        {{
                            share.status === 'active'
                                ? 'Copy unavailable'
                                : 'Link unavailable'
                        }}
                    </span>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center justify-end gap-2 text-sm font-medium text-red-600 disabled:opacity-45"
                    :disabled="share.status === 'revoked' || revokeProcessing"
                    @click="revokeShare(share)"
                >
                    <Trash2 class="h-4 w-4" />
                    Revoke
                </button>
            </div>
            <p
                v-if="shares.data.length === 0"
                class="py-10 text-center text-sm text-ink-600 dark:text-ink-300"
            >
                No share links yet.
            </p>
        </section>
        <PaginationLinks v-if="shares.links" :links="shares.links" />
        <Link
            href="/files"
            class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950"
            >Create from Files</Link
        >

        <Dialog
            :open="revokeTarget !== null"
            @update:open="($event) => !$event && closeRevokeDialog()"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Revoke share link</DialogTitle>
                    <DialogDescription>
                        {{
                            revokeTarget?.file?.display_name ??
                            'This share link'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <div
                    class="rounded-2xl border border-red-100 bg-red-50 p-4 text-sm text-red-700 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-200"
                >
                    The public link stops working immediately. The original
                    file stays in Drive and can be shared again later.
                </div>
                <DialogFooter class="gap-2 sm:gap-2">
                    <button
                        type="button"
                        class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white"
                        :disabled="revokeProcessing"
                        @click="closeRevokeDialog"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="cloud-button bg-red-600 text-white hover:bg-red-700"
                        :disabled="revokeProcessing"
                        @click="confirmRevoke"
                    >
                        <LoaderCircle
                            v-if="revokeProcessing"
                            class="h-4 w-4 animate-spin"
                        />
                        <Trash2 v-else class="h-4 w-4" />
                        {{ revokeProcessing ? 'Revoking' : 'Revoke link' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
