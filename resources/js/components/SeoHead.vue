<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        path?: string;
        image?: string;
        type?: 'website' | 'article';
        noindex?: boolean;
    }>(),
    {
        description: 'A private workspace file manager with direct Backblaze B2 uploads, download-only sharing, retention, audit logs, and admin controls.',
        image: '/apple-touch-icon.png',
        type: 'website',
        noindex: false,
    },
);

const page = usePage();
const seo = computed(() => (page.props as { seo?: { appName?: string; appUrl?: string } }).seo ?? {});
const appName = computed(() => seo.value.appName ?? 'Cloud Drive');
const appUrl = computed(() => (seo.value.appUrl ?? 'http://localhost').replace(/\/$/, ''));
const pagePath = computed(() => props.path ?? page.url.split('?')[0] ?? '/');
const canonical = computed(() => new URL(pagePath.value, appUrl.value).toString());
const imageUrl = computed(() => new URL(props.image, appUrl.value).toString());
const fullTitle = computed(() => (props.title === appName.value ? props.title : `${props.title} | ${appName.value}`));
const robots = computed(() => (props.noindex ? 'noindex,nofollow,noarchive' : 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'));
</script>

<template>
    <Head :title="props.title">
        <meta head-key="description" name="description" :content="props.description" />
        <meta head-key="robots" name="robots" :content="robots" />
        <link v-if="!props.noindex" head-key="canonical" rel="canonical" :href="canonical" />

        <meta head-key="og:site_name" property="og:site_name" :content="appName" />
        <meta head-key="og:title" property="og:title" :content="fullTitle" />
        <meta head-key="og:description" property="og:description" :content="props.description" />
        <meta head-key="og:type" property="og:type" :content="props.type" />
        <meta head-key="og:url" property="og:url" :content="canonical" />
        <meta head-key="og:image" property="og:image" :content="imageUrl" />

        <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
        <meta head-key="twitter:title" name="twitter:title" :content="fullTitle" />
        <meta head-key="twitter:description" name="twitter:description" :content="props.description" />
        <meta head-key="twitter:image" name="twitter:image" :content="imageUrl" />
    </Head>
</template>
