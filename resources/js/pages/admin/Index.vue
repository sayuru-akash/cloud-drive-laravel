<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    KeyRound,
    RotateCcw,
    Save,
    ShieldCheck,
    UserPlus,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { UserRole } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    is_active: boolean;
    two_factor_enabled: boolean;
};

const props = defineProps<{
    users: {
        data: UserRow[];
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    settings: {
        maxUploadSizeBytes: number;
        retentionDays: number;
        shareExpiryDays: number;
        blockedExtensions: string[];
    };
    currentUserId: number;
    canCreateSuperAdmin: boolean;
}>();

const settingsForm = useForm({
    max_upload_size_bytes: props.settings.maxUploadSizeBytes,
    retention_days: props.settings.retentionDays,
    share_expiry_days: props.settings.shareExpiryDays,
    blocked_extensions: props.settings.blockedExtensions.join(', '),
});

const createDialogOpen = ref(false);
const generatedPassword = ref('');
const userEdits = reactive<Record<number, { role: UserRole; is_active: boolean }>>({});

const createUserForm = useForm({
    name: '',
    email: '',
    role: 'member' as UserRole,
    is_active: true,
    password: '',
    password_confirmation: '',
});

const roleOptions = computed(() => {
    const options: Array<{ value: UserRole; label: string; description: string }> = [
        {
            value: 'member',
            label: 'Member',
            description: 'Can manage their own files and view workspace files.',
        },
        {
            value: 'admin',
            label: 'Admin',
            description: 'Can manage users, trash cleanup, settings, and audit logs.',
        },
    ];

    if (props.canCreateSuperAdmin) {
        options.push({
            value: 'super_admin',
            label: 'Super admin',
            description: 'Full workspace ownership. Keep this tightly limited.',
        });
    }

    return options;
});

watch(
    () => props.users.data,
    (users) => {
        users.forEach((user) => {
            userEdits[user.id] = {
                role: user.role,
                is_active: user.is_active,
            };
        });
    },
    { immediate: true },
);

function generateTemporaryPassword(): void {
    const randomValues = new Uint32Array(4);
    window.crypto.getRandomValues(randomValues);
    const password = `Drive-${Array.from(randomValues)
        .map((value) => value.toString(36).slice(0, 4))
        .join('-')}!9`;

    generatedPassword.value = password;
    createUserForm.password = password;
    createUserForm.password_confirmation = password;
}

function resetCreateUserForm(): void {
    createUserForm.reset();
    createUserForm.clearErrors();
    generatedPassword.value = '';
    createUserForm.role = 'member';
    createUserForm.is_active = true;
}

function createUser(): void {
    createUserForm.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            resetCreateUserForm();
            createDialogOpen.value = false;
        },
    });
}

function resetUserEdit(user: UserRow): void {
    userEdits[user.id] = {
        role: user.role,
        is_active: user.is_active,
    };
}

function userHasChanges(user: UserRow): boolean {
    const edit = userEdits[user.id];

    return Boolean(edit && (edit.role !== user.role || edit.is_active !== user.is_active));
}

function isSuperAdminLocked(user: UserRow): boolean {
    return user.role === 'super_admin' && !props.canCreateSuperAdmin;
}

function isSelfDisable(user: UserRow): boolean {
    return user.id === props.currentUserId && !userEdits[user.id]?.is_active;
}

function saveUser(user: UserRow): void {
    const edit = userEdits[user.id];

    if (!edit) {
        return;
    }

    router.patch(`/admin/users/${user.id}`, edit, {
        preserveScroll: true,
    });
}

function roleLabel(role: UserRole): string {
    return role
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}
</script>

<template>
    <Head title="Admin" />

    <div class="space-y-6">
        <PageHeader
            title="Admin"
            description="Create users, manage access, and tune workspace policy."
        >
            <template #actions>
                <Button
                    type="button"
                    class="gap-2"
                    @click="createDialogOpen = true"
                >
                    <UserPlus class="h-4 w-4" />
                    Add user
                </Button>
            </template>
        </PageHeader>

        <section class="grid gap-6 xl:grid-cols-[1fr_.85fr]">
            <form
                class="cloud-panel grid gap-4 p-5 md:grid-cols-4"
                @submit.prevent="settingsForm.patch('/admin/settings')"
            >
                <label class="text-sm font-medium">
                    Upload limit
                    <input
                        v-model.number="settingsForm.max_upload_size_bytes"
                        type="number"
                        min="1"
                        class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                    />
                    <InputError :message="settingsForm.errors.max_upload_size_bytes" />
                </label>
                <label class="text-sm font-medium">
                    Retention days
                    <input
                        v-model.number="settingsForm.retention_days"
                        type="number"
                        min="1"
                        max="365"
                        class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                    />
                    <InputError :message="settingsForm.errors.retention_days" />
                </label>
                <label class="text-sm font-medium">
                    Share expiry
                    <input
                        v-model.number="settingsForm.share_expiry_days"
                        type="number"
                        min="1"
                        max="90"
                        class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                    />
                    <InputError :message="settingsForm.errors.share_expiry_days" />
                </label>
                <label class="text-sm font-medium">
                    Blocked extensions
                    <input
                        v-model="settingsForm.blocked_extensions"
                        class="mt-2 w-full rounded-full border border-line bg-white px-4 py-2 dark:bg-white/10"
                    />
                    <InputError :message="settingsForm.errors.blocked_extensions" />
                </label>
                <Button
                    type="submit"
                    class="gap-2 md:col-span-4"
                    :disabled="settingsForm.processing"
                >
                    <Spinner v-if="settingsForm.processing" />
                    <Save v-else class="h-4 w-4" />
                    Save settings
                </Button>
            </form>

            <aside class="cloud-panel p-5">
                <div class="flex items-start gap-3">
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand"
                    >
                        <ShieldCheck class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold">Closed workspace signup</h2>
                        <p class="mt-2 text-sm leading-6 text-ink-600 dark:text-ink-300">
                            Public account creation is off. New users must be added
                            here by an admin, then sign in with their temporary
                            password or use password reset.
                        </p>
                    </div>
                </div>
            </aside>
        </section>

        <section class="cloud-panel overflow-hidden">
            <div
                class="flex flex-col gap-3 border-b border-line p-5 md:flex-row md:items-end md:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">Users</h2>
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                        Showing {{ users.from ?? 0 }}-{{ users.to ?? 0 }} of
                        {{ users.total }} accounts.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="gap-2"
                    @click="createDialogOpen = true"
                >
                    <UserPlus class="h-4 w-4" />
                    Add user
                </Button>
            </div>

            <div class="divide-y divide-line">
                <form
                    v-for="user in users.data"
                    :key="user.id"
                    class="grid gap-4 p-5 xl:grid-cols-[minmax(15rem,1fr)_12rem_10rem_auto] xl:items-center"
                    @submit.prevent="saveUser(user)"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate font-medium">{{ user.name }}</p>
                            <Badge variant="secondary">{{ roleLabel(user.role) }}</Badge>
                            <Badge
                                :variant="user.is_active ? 'default' : 'outline'"
                            >
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <Badge
                                :variant="
                                    user.two_factor_enabled
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                {{
                                    user.two_factor_enabled
                                        ? '2FA on'
                                        : '2FA off'
                                }}
                            </Badge>
                        </div>
                        <p class="mt-1 truncate text-sm text-ink-600 dark:text-ink-300">
                            {{ user.email }}
                        </p>
                        <p
                            v-if="isSuperAdminLocked(user)"
                            class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-300"
                        >
                            Only a super admin can edit this account.
                        </p>
                    </div>

                    <label class="text-xs font-semibold text-ink-600 dark:text-ink-300">
                        Role
                        <select
                            v-model="userEdits[user.id].role"
                            class="mt-2 w-full rounded-full border border-line bg-white px-3 py-2 text-sm text-ink-950 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/10 dark:text-white"
                            :disabled="isSuperAdminLocked(user)"
                        >
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                            <option
                                v-if="canCreateSuperAdmin || user.role === 'super_admin'"
                                value="super_admin"
                            >
                                Super admin
                            </option>
                        </select>
                    </label>

                    <label class="text-xs font-semibold text-ink-600 dark:text-ink-300">
                        Status
                        <select
                            v-model="userEdits[user.id].is_active"
                            class="mt-2 w-full rounded-full border border-line bg-white px-3 py-2 text-sm text-ink-950 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/10 dark:text-white"
                            :disabled="isSuperAdminLocked(user)"
                        >
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                    </label>

                    <div class="flex flex-wrap justify-start gap-2 xl:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            class="gap-2"
                            :disabled="!userHasChanges(user)"
                            @click="resetUserEdit(user)"
                        >
                            <RotateCcw class="h-4 w-4" />
                            Revert
                        </Button>
                        <Button
                            type="submit"
                            class="gap-2"
                            :disabled="
                                !userHasChanges(user) ||
                                isSuperAdminLocked(user) ||
                                isSelfDisable(user)
                            "
                        >
                            <Save class="h-4 w-4" />
                            Save
                        </Button>
                        <p
                            v-if="isSelfDisable(user)"
                            class="basis-full text-xs font-medium text-red-600 xl:text-right"
                        >
                            You cannot disable your own account.
                        </p>
                    </div>
                </form>
            </div>

            <div
                v-if="users.links.length > 3"
                class="flex flex-wrap gap-2 border-t border-line p-5"
            >
                <button
                    v-for="link in users.links"
                    :key="link.label"
                    type="button"
                    class="rounded-full border border-line px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    :class="{
                        'bg-ink-950 text-white dark:bg-white dark:text-ink-950':
                            link.active,
                    }"
                    :disabled="link.url === null"
                    v-html="link.label"
                    @click="link.url && router.visit(link.url)"
                />
            </div>
        </section>

        <Dialog v-model:open="createDialogOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <UserPlus class="h-5 w-5" />
                        Add workspace user
                    </DialogTitle>
                    <DialogDescription>
                        Create the account here. Public signup is disabled, so
                        this is the controlled entry point for new users.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-5" @submit.prevent="createUser">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="new-user-name">Name</Label>
                            <Input
                                id="new-user-name"
                                v-model="createUserForm.name"
                                autocomplete="name"
                                required
                            />
                            <InputError :message="createUserForm.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="new-user-email">Email</Label>
                            <Input
                                id="new-user-email"
                                v-model="createUserForm.email"
                                type="email"
                                autocomplete="email"
                                required
                            />
                            <InputError :message="createUserForm.errors.email" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="new-user-role">Role</Label>
                            <select
                                id="new-user-role"
                                v-model="createUserForm.role"
                                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="role in roleOptions"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </option>
                            </select>
                            <p class="text-xs leading-5 text-muted-foreground">
                                {{
                                    roleOptions.find(
                                        (role) => role.value === createUserForm.role,
                                    )?.description
                                }}
                            </p>
                            <InputError :message="createUserForm.errors.role" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="new-user-status">Initial status</Label>
                            <select
                                id="new-user-status"
                                v-model="createUserForm.is_active"
                                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option :value="true">Active now</option>
                                <option :value="false">Create inactive</option>
                            </select>
                            <p class="text-xs leading-5 text-muted-foreground">
                                Inactive users cannot sign in until an admin enables
                                the account.
                            </p>
                            <InputError :message="createUserForm.errors.is_active" />
                        </div>
                    </div>

                    <div class="rounded-xl border border-line p-4">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="font-medium">Temporary password</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Generate one here or type your own. Share it
                                    securely with the new user.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                class="gap-2"
                                @click="generateTemporaryPassword"
                            >
                                <KeyRound class="h-4 w-4" />
                                Generate
                            </Button>
                        </div>

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="new-user-password">Password</Label>
                                <PasswordInput
                                    id="new-user-password"
                                    v-model="createUserForm.password"
                                    autocomplete="new-password"
                                    required
                                />
                                <InputError :message="createUserForm.errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="new-user-password-confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="new-user-password-confirmation"
                                    v-model="createUserForm.password_confirmation"
                                    autocomplete="new-password"
                                    required
                                />
                            </div>
                        </div>

                        <div
                            v-if="generatedPassword"
                            class="mt-4 flex items-start gap-3 rounded-lg bg-emerald-50 p-3 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100"
                        >
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                            <p class="text-sm">
                                Generated password is filled into both password
                                fields. It will not be shown again after the user
                                is created.
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="
                                createDialogOpen = false;
                                resetCreateUserForm();
                            "
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="gap-2"
                            :disabled="createUserForm.processing"
                        >
                            <Spinner v-if="createUserForm.processing" />
                            <UserPlus v-else class="h-4 w-4" />
                            Create user
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
