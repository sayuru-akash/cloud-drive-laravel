<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/cloud/PageHeader.vue';

const props = defineProps<{
    users: { data: Array<{ id: number; name: string; email: string; role: string; is_active: boolean }> };
    settings: { maxUploadSizeBytes: number; retentionDays: number; shareExpiryDays: number; blockedExtensions: string[] };
}>();

const form = useForm({
    max_upload_size_bytes: props.settings.maxUploadSizeBytes,
    retention_days: props.settings.retentionDays,
    share_expiry_days: props.settings.shareExpiryDays,
    blocked_extensions: props.settings.blockedExtensions.join(', '),
});
</script>

<template>
    <Head title="Admin" />
    <div class="space-y-6">
        <PageHeader title="Admin" description="Manage users and workspace policies." />
        <form class="cloud-panel grid gap-4 p-5 md:grid-cols-4" @submit.prevent="form.patch('/admin/settings')">
            <label class="text-sm font-medium">Upload limit<input v-model.number="form.max_upload_size_bytes" type="number" min="1" class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10" /></label>
            <label class="text-sm font-medium">Retention days<input v-model.number="form.retention_days" type="number" min="1" max="365" class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10" /></label>
            <label class="text-sm font-medium">Share expiry<input v-model.number="form.share_expiry_days" type="number" min="1" max="90" class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10" /></label>
            <label class="text-sm font-medium">Blocked extensions<input v-model="form.blocked_extensions" class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10" /></label>
            <button type="submit" class="cloud-button bg-ink-950 text-white md:col-span-4 dark:bg-white dark:text-ink-950">Save settings</button>
        </form>
        <section class="cloud-panel divide-y divide-line p-5">
            <div v-for="user in users.data" :key="user.id" class="grid gap-3 py-4 md:grid-cols-[1fr_auto_auto_auto] md:items-center">
                <div><p class="font-medium">{{ user.name }}</p><p class="text-sm text-ink-600 dark:text-ink-300">{{ user.email }}</p></div>
                <select class="rounded-full border border-line bg-white px-3 py-2 text-sm dark:bg-white/10" :value="user.role" @change="router.patch(`/admin/users/${user.id}`, { role: ($event.target as HTMLSelectElement).value, is_active: user.is_active })">
                    <option value="member">member</option><option value="admin">admin</option><option value="super_admin">super_admin</option>
                </select>
                <span class="text-sm">{{ user.is_active ? 'Active' : 'Inactive' }}</span>
                <button type="button" class="text-sm font-medium text-brand" @click="router.patch(`/admin/users/${user.id}`, { role: user.role, is_active: !user.is_active })">{{ user.is_active ? 'Disable' : 'Enable' }}</button>
            </div>
        </section>
    </div>
</template>
