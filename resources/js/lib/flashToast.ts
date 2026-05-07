import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (data) {
            toast[data.type](data.message);

            return;
        }

        if (typeof flash?.success === 'string' && flash.success.length > 0) {
            toast.success(flash.success);
        }

        if (typeof flash?.error === 'string' && flash.error.length > 0) {
            toast.error(flash.error);
        }
    });
}
