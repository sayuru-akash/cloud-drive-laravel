<script setup lang="ts">
import SeoHead from '@/components/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

const sections = [
    [
        'Data Cloud Drive processes',
        'Cloud Drive stores user account details, file and folder metadata, upload state, ownership, visibility, share-link state, storage usage, security settings, and audit records needed to operate the workspace. File bytes are stored separately in a private Backblaze B2 bucket.',
    ],
    [
        'How the data is used',
        'The data is used to authenticate approved employees, enforce role and ownership rules, organize files, complete uploads, provide previews and downloads, create or revoke shares, apply retention settings, and investigate operational activity.',
    ],
    [
        'File transfers and storage',
        'Uploads move directly from the browser to private object storage using short-lived signed requests. Preview and download access also uses short-lived signed requests. The application stores metadata in PostgreSQL and does not publish permanent object-storage URLs.',
    ],
    [
        'Public share links',
        'Authorized users may create expiring links for selected files or folders. Anyone who receives an active link can access the shared resource until it expires or is revoked. Public share pages are excluded from search indexing and use generic social preview metadata.',
    ],
    [
        'Audit logs',
        'Operational events can include sign-ins, uploads, file and folder changes, sharing, recovery, policy changes, and administrative actions. Audit records may include the actor, action, resource identifier, timestamp, IP address, user agent, and limited operational metadata.',
    ],
    [
        'Security and access',
        'Public account registration is disabled. Access is issued by administrators, role-based permissions are enforced on the server, and optional authenticator-based two-factor authentication is available. Storage credentials and application secrets remain in environment configuration.',
    ],
    [
        'Retention and deletion',
        'Deleted items remain in trash for the configured retention period unless an authorized administrator permanently removes them earlier. Permanent deletion removes the associated object-storage data where present. Audit records may be retained for security, legal, and operational requirements.',
    ],
    [
        'Service providers',
        'Cloud Drive relies on infrastructure providers, including managed PostgreSQL and Backblaze B2, to deliver the service. Those providers process data only as needed to provide their contracted infrastructure functions.',
    ],
];
</script>

<template>
    <SeoHead
        title="Privacy Policy"
        description="How Cloud Drive handles account data, file metadata, private object storage, signed links, sharing, retention, and audit records."
        path="/privacy"
        type="article"
    />
    <PublicLayout>
        <article class="mx-auto max-w-4xl px-5 py-16 sm:px-8 sm:py-24">
            <p class="text-sm font-semibold text-brand">Privacy Policy</p>
            <h1 class="mt-5 text-4xl font-semibold sm:text-6xl">
                How Cloud Drive handles workspace data.
            </h1>
            <p class="mt-6 max-w-3xl text-lg leading-8 text-muted-foreground">
                Cloud Drive is a private file management system for approved
                Codezela Technologies employees. This policy explains how
                accounts, file metadata, storage transfers, share links, and
                operational logs are handled.
            </p>
            <p class="mt-5 text-sm text-muted-foreground">
                Last updated: August 17, 2026
            </p>
            <div class="mt-14 divide-y divide-line border-y border-line">
                <section
                    v-for="section in sections"
                    :key="section[0]"
                    class="py-8"
                >
                    <h2 class="text-xl font-semibold">{{ section[0] }}</h2>
                    <p class="mt-3 leading-7 text-muted-foreground">
                        {{ section[1] }}
                    </p>
                </section>
            </div>
        </article>
    </PublicLayout>
</template>
