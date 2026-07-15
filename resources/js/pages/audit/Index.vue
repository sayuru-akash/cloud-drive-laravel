<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/components/cloud/PageHeader.vue';
import PaginationLinks from '@/components/cloud/PaginationLinks.vue';
import { formatDate, formatStatus } from '@/lib/format';

defineProps<{
    logs: {
        data: Array<{
            id: string;
            actor_email: string | null;
            action_type: string;
            resource_type: string | null;
            resource_id: string | null;
            created_at: string;
        }>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { q?: string; action?: string };
}>();
</script>

<template>
    <Head title="Audit" />
    <div class="space-y-6">
        <PageHeader
            title="Audit"
            description="Trace workspace actions across files, folders, sharing, and admin changes."
        />
        <section class="cloud-panel p-5">
            <div
                class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
            >
                <input
                    :value="filters.q"
                    class="min-w-0 flex-1 rounded-full border border-line bg-white px-4 py-2 text-sm dark:bg-white/10"
                    placeholder="Search audit"
                    @input="
                        router.get(
                            '/audit',
                            {
                                ...filters,
                                q: ($event.target as HTMLInputElement).value,
                            },
                            {
                                preserveScroll: true,
                                preserveState: true,
                                replace: true,
                            },
                        )
                    "
                />
                <p class="text-sm text-ink-600 dark:text-ink-300">
                    Showing {{ logs.from ?? 0 }}-{{ logs.to ?? 0 }} of
                    {{ logs.total }}
                </p>
            </div>
            <div class="mt-5 divide-y divide-line">
                <div
                    v-for="log in logs.data"
                    :key="log.id"
                    class="grid gap-3 py-4 md:grid-cols-[1fr_auto] md:items-center"
                >
                    <div>
                        <p class="font-medium">
                            {{ formatStatus(log.action_type) }}
                        </p>
                        <p class="text-sm text-ink-600 dark:text-ink-300">
                            {{ log.actor_email ?? 'Public' }} ·
                            {{ formatStatus(log.resource_type ?? 'system') }} ·
                            {{ log.resource_id ?? '-' }}
                        </p>
                    </div>
                    <span class="text-sm text-ink-600 dark:text-ink-300">{{
                        formatDate(log.created_at)
                    }}</span>
                </div>
                <p
                    v-if="logs.data.length === 0"
                    class="py-10 text-center text-sm text-ink-600 dark:text-ink-300"
                >
                    No audit records found.
                </p>
            </div>
        </section>
        <PaginationLinks :links="logs.links" />
    </div>
</template>
