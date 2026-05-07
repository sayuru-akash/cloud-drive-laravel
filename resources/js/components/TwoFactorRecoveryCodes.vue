<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Eye,
    EyeOff,
    LockKeyhole,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import { nextTick, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { copyTextToClipboard } from '@/lib/clipboard';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const copyStatus = ref<'idle' | 'copied' | 'blocked'>('idle');
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

const copyRecoveryCodes = async (): Promise<void> => {
    if (!recoveryCodesList.value.length) {
        return;
    }

    const copied = await copyTextToClipboard(recoveryCodesList.value.join('\n'));
    copyStatus.value = copied ? 'copied' : 'blocked';

    window.setTimeout(() => {
        copyStatus.value = 'idle';
    }, 1800);
};
</script>

<template>
    <Card class="w-full">
        <CardHeader>
            <CardTitle class="flex gap-3">
                <LockKeyhole class="size-4" />2FA recovery codes
            </CardTitle>
            <CardDescription>
                Recovery codes let you regain access if you lose your 2FA
                device. Store them in a secure password manager.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Button @click="toggleRecoveryCodesVisibility" class="w-fit">
                    <component
                        :is="isRecoveryCodesVisible ? EyeOff : Eye"
                        class="size-4"
                    />
                    {{ isRecoveryCodesVisible ? 'Hide' : 'View' }} recovery
                    codes
                </Button>

                <div
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    class="flex flex-wrap gap-2"
                >
                    <Button
                        type="button"
                        variant="outline"
                        :aria-label="
                            copyStatus === 'copied'
                                ? 'Recovery codes copied'
                                : 'Copy recovery codes'
                        "
                        @click="copyRecoveryCodes"
                    >
                        <Check
                            v-if="copyStatus === 'copied'"
                            class="size-4 text-green-500"
                        />
                        <XCircle
                            v-else-if="copyStatus === 'blocked'"
                            class="size-4 text-destructive"
                        />
                        <Copy v-else class="size-4" />
                        {{
                            copyStatus === 'copied'
                                ? 'Copied'
                                : copyStatus === 'blocked'
                                  ? 'Blocked'
                                  : 'Copy'
                        }}
                    </Button>
                    <Form
                        v-bind="regenerateRecoveryCodes.form()"
                        method="post"
                        :options="{ preserveScroll: true }"
                        @success="
                            fetchRecoveryCodes();
                            copyStatus = 'idle';
                        "
                        #default="{ processing }"
                    >
                        <Button
                            variant="secondary"
                            type="submit"
                            :disabled="processing"
                        >
                            <RefreshCw /> Regenerate codes
                        </Button>
                    </Form>
                </div>
            </div>
            <div
                :class="[
                    'relative overflow-hidden transition-all duration-300',
                    isRecoveryCodesVisible
                        ? 'h-auto opacity-100'
                        : 'h-0 opacity-0',
                ]"
            >
                <div v-if="errors?.length" class="mt-6">
                    <AlertError :errors="errors" />
                </div>
                <div v-else class="mt-3 space-y-3">
                    <div
                        ref="recoveryCodeSectionRef"
                        class="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm"
                    >
                        <div v-if="!recoveryCodesList.length" class="space-y-2">
                            <div
                                v-for="n in 8"
                                :key="n"
                                class="h-4 animate-pulse rounded bg-muted-foreground/20"
                            ></div>
                        </div>
                        <div
                            v-else
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground select-none">
                        Each recovery code can be used once to access your
                        account and will be removed after use. If you need more,
                        click
                        <span class="font-bold">Regenerate codes</span> above.
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
