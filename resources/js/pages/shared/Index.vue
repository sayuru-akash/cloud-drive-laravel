<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { formatDate } from '@/lib/format';

defineProps<{ shares: { data: Array<{ id: string; resource_id: string; is_revoked: boolean; expires_at: string | null; created_at: string; file?: { display_name: string } }> } }>();
const flash = usePage().props.flash as { shareUrl?: string } | undefined;
const copied = ref(false);

async function copyShareUrl() {
    if (!flash?.shareUrl) {
        return;
    }

    await navigator.clipboard.writeText(flash.shareUrl);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 1800);
}
</script>

<template>
    <Head title="Shared" />
    <div class="space-y-6">
        <PageHeader title="Shared links" description="Download-only links with expiry and revoke controls." />
        <div v-if="flash?.shareUrl" class="cloud-panel flex flex-col gap-3 p-4 text-sm text-ink-700 md:flex-row md:items-center md:justify-between dark:text-ink-300">
            <a class="min-w-0 truncate font-medium text-brand" :href="flash.shareUrl" target="_blank" rel="noreferrer">{{ flash.shareUrl }}</a>
            <button type="button" class="cloud-button border border-line bg-white text-ink-700 dark:bg-white/10 dark:text-white" @click="copyShareUrl">{{ copied ? 'Copied' : 'Copy' }}</button>
        </div>
        <section class="cloud-panel divide-y divide-line p-5">
            <div v-for="share in shares.data" :key="share.id" class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto] md:items-center">
                <div class="min-w-0">
                    <p class="truncate font-medium text-ink-950 dark:text-white">{{ share.file?.display_name ?? share.resource_id }}</p>
                    <p class="text-xs text-ink-600 dark:text-ink-300">Created {{ formatDate(share.created_at) }} · Expires {{ formatDate(share.expires_at) }}</p>
                </div>
                <StatusBadge :value="share.is_revoked ? 'revoked' : 'active'" />
                <button type="button" class="text-sm font-medium text-red-600 disabled:opacity-45" :disabled="share.is_revoked" @click="router.patch(`/shares/${share.id}/revoke`)">Revoke</button>
            </div>
            <p v-if="shares.data.length === 0" class="py-10 text-center text-sm text-ink-600 dark:text-ink-300">No share links yet.</p>
        </section>
        <Link href="/files" class="cloud-button bg-ink-950 text-white dark:bg-white dark:text-ink-950">Create from Files</Link>
    </div>
</template>
