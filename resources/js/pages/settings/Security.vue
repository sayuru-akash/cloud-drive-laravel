<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound, LockKeyhole, ShieldCheck, Smartphone } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Current password</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="New password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Save password
                </Button>
            </div>
        </Form>
    </div>

    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            title="Two-factor authentication"
            description="Use an authenticator app to protect this account during login"
        />

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <ShieldCheck class="size-5 text-brand" />
                    Authenticator app
                </CardTitle>
                <CardDescription>
                    {{
                        twoFactorEnabled
                            ? 'Two-factor authentication is active for this account.'
                            : 'Set up Google Authenticator, 1Password, Authy, Microsoft Authenticator, or any TOTP app.'
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-5">
                <Alert
                    :class="
                        twoFactorEnabled
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'
                            : ''
                    "
                >
                    <component
                        :is="twoFactorEnabled ? ShieldCheck : Smartphone"
                    />
                    <AlertTitle>
                        {{
                            twoFactorEnabled
                                ? '2FA is protecting this login'
                                : '2FA is not enabled yet'
                        }}
                    </AlertTitle>
                    <AlertDescription>
                        {{
                            twoFactorEnabled
                                ? 'Future sign-ins require the password plus a fresh 6-digit authenticator code or a recovery code.'
                                : 'Enabling 2FA creates a QR code, a manual setup key, and recovery codes for account recovery.'
                        }}
                    </AlertDescription>
                </Alert>

                <div
                    v-if="!twoFactorEnabled"
                    class="grid gap-3 rounded-xl border border-line p-4 text-sm text-muted-foreground md:grid-cols-3"
                >
                    <div class="space-y-2">
                        <Smartphone class="size-4 text-brand" />
                        <p class="font-medium text-foreground">Scan QR</p>
                        <p>Open your authenticator app and scan the QR code.</p>
                    </div>
                    <div class="space-y-2">
                        <KeyRound class="size-4 text-brand" />
                        <p class="font-medium text-foreground">Keep backup</p>
                        <p>Use the setup key if the camera scan is not available.</p>
                    </div>
                    <div class="space-y-2">
                        <LockKeyhole class="size-4 text-brand" />
                        <p class="font-medium text-foreground">Confirm code</p>
                        <p>Enter the current 6-digit code to complete setup.</p>
                    </div>
                </div>

                <div
                    v-if="!twoFactorEnabled"
                    class="flex flex-wrap items-center gap-3"
                >
                    <Button v-if="hasSetupData" @click="showSetupModal = true">
                        <ShieldCheck />Continue setup
                    </Button>
                    <Form
                        v-else
                        v-bind="enable.form()"
                        @success="showSetupModal = true"
                        #default="{ processing }"
                    >
                        <Button type="submit" :disabled="processing">
                            <ShieldCheck />
                            Enable 2FA
                        </Button>
                    </Form>
                </div>

                <div v-else class="space-y-5">
                    <TwoFactorRecoveryCodes />

                    <div class="rounded-xl border border-destructive/30 p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-medium">Disable 2FA</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Turning this off removes the authenticator
                                    requirement and clears recovery codes.
                                </p>
                            </div>
                            <Form v-bind="disable.form()" #default="{ processing }">
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    :disabled="processing"
                                >
                                    Disable 2FA
                                </Button>
                            </Form>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </div>
</template>
