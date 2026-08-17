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
        indexable?: boolean;
    }>(),
    {
        description:
            'A private workspace file manager with direct Backblaze B2 uploads, download-only sharing, retention, audit logs, and admin controls.',
        image: '/og-image.png',
        type: 'website',
        indexable: true,
    },
);

const page = usePage();
const seo = computed(
    () =>
        (page.props as { seo?: { appName?: string; appUrl?: string } }).seo ??
        {},
);
const appName = computed(() => seo.value.appName ?? 'Cloud Drive');
const appUrl = computed(() =>
    (seo.value.appUrl ?? 'http://localhost').replace(/\/$/, ''),
);
const pagePath = computed(() => props.path ?? page.url.split('?')[0] ?? '/');
const canonical = computed(() =>
    new URL(pagePath.value, appUrl.value).toString(),
);
const imageUrl = computed(() => new URL(props.image, appUrl.value).toString());
const fullTitle = computed(() =>
    props.title === appName.value
        ? props.title
        : `${props.title} | ${appName.value}`,
);
const robots = computed(() =>
    props.indexable
        ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
        : 'noindex,nofollow,noarchive',
);
const schema = computed(() => ({
    '@context': 'https://schema.org',
    '@type': props.path === '/' ? 'WebApplication' : 'WebPage',
    name: fullTitle.value,
    description: props.description,
    url: canonical.value,
    ...(props.path === '/'
        ? {
              applicationCategory: 'BusinessApplication',
              operatingSystem: 'Web',
              provider: {
                  '@type': 'Organization',
                  name: 'Codezela Technologies',
                  url: 'https://codezela.com',
              },
          }
        : {}),
}));
</script>

<template>
    <Head :title="props.title">
        <meta
            head-key="description"
            name="description"
            :content="props.description"
        />
        <meta head-key="robots" name="robots" :content="robots" />
        <link
            v-if="props.indexable"
            head-key="canonical"
            rel="canonical"
            :href="canonical"
        />

        <meta
            head-key="og:site_name"
            property="og:site_name"
            :content="appName"
        />
        <meta head-key="og:title" property="og:title" :content="fullTitle" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="props.description"
        />
        <meta head-key="og:type" property="og:type" :content="props.type" />
        <meta head-key="og:url" property="og:url" :content="canonical" />
        <meta head-key="og:image" property="og:image" :content="imageUrl" />
        <meta
            head-key="og:image:secure_url"
            property="og:image:secure_url"
            :content="imageUrl"
        />
        <meta
            head-key="og:image:width"
            property="og:image:width"
            content="1200"
        />
        <meta
            head-key="og:image:height"
            property="og:image:height"
            content="630"
        />
        <meta
            head-key="og:image:alt"
            property="og:image:alt"
            :content="`${appName} secure file workspace`"
        />

        <meta
            head-key="twitter:card"
            name="twitter:card"
            content="summary_large_image"
        />
        <meta
            head-key="twitter:title"
            name="twitter:title"
            :content="fullTitle"
        />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            :content="props.description"
        />
        <meta
            head-key="twitter:image"
            name="twitter:image"
            :content="imageUrl"
        />
        <meta
            head-key="twitter:image:alt"
            name="twitter:image:alt"
            :content="`${appName} secure file workspace`"
        />
        <script
            v-if="props.indexable"
            head-key="structured-data"
            type="application/ld+json"
        >
            {{ JSON.stringify(schema) }}
        </script>
    </Head>
</template>
