<script setup lang="ts">
import {
    Archive,
    Code2,
    File,
    FileAudio,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Presentation,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { fileTypeKind, formatFileType } from '@/lib/file-types';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        name?: string | null;
        mimeType?: string | null;
        class?: string;
    }>(),
    {
        name: null,
        mimeType: null,
        class: '',
    },
);

const kind = computed(() => fileTypeKind(props.name, props.mimeType));
const label = computed(() => formatFileType(props.name, props.mimeType));
const icon = computed(() => {
    switch (kind.value) {
        case 'archive':
            return Archive;
        case 'audio':
            return FileAudio;
        case 'code':
            return Code2;
        case 'document':
        case 'pdf':
        case 'text':
            return FileText;
        case 'image':
            return FileImage;
        case 'presentation':
            return Presentation;
        case 'spreadsheet':
            return FileSpreadsheet;
        case 'video':
            return FileVideo;
        default:
            return File;
    }
});
const tone = computed(() => {
    switch (kind.value) {
        case 'archive':
            return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-400/15 dark:text-amber-200 dark:ring-amber-400/20';
        case 'audio':
            return 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200 dark:bg-fuchsia-400/15 dark:text-fuchsia-200 dark:ring-fuchsia-400/20';
        case 'code':
            return 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-400/15 dark:text-slate-200 dark:ring-slate-400/20';
        case 'image':
            return 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-400/15 dark:text-sky-200 dark:ring-sky-400/20';
        case 'pdf':
            return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-400/15 dark:text-red-200 dark:ring-red-400/20';
        case 'presentation':
            return 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-400/15 dark:text-orange-200 dark:ring-orange-400/20';
        case 'spreadsheet':
            return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-400/15 dark:text-emerald-200 dark:ring-emerald-400/20';
        case 'video':
            return 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-400/15 dark:text-violet-200 dark:ring-violet-400/20';
        default:
            return 'bg-ink-950/5 text-ink-700 ring-line dark:bg-white/10 dark:text-ink-200 dark:ring-white/10';
    }
});
</script>

<template>
    <span
        :title="label"
        :aria-label="label"
        :class="
            cn(
                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1',
                tone,
                props.class,
            )
        "
    >
        <component :is="icon" class="h-4 w-4" aria-hidden="true" />
    </span>
</template>
