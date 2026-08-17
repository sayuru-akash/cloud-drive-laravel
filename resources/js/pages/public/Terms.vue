<script setup lang="ts">
import SeoHead from '@/components/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

const sections = [
    [
        'Authorized use',
        'Cloud Drive is provided for approved Codezela Technologies employees and authorized business activity. Accounts are personal to the assigned user and must not be shared. Public self-registration is not available.',
    ],
    [
        'Your responsibilities',
        'You are responsible for the files you upload, the folders you create, the recipients with whom you share links, and compliance with applicable law and company policy. Do not upload unlawful, malicious, infringing, or unauthorized data.',
    ],
    [
        'File sharing',
        'Share links provide access to the selected file or folder for anyone who possesses the active URL. Choose an appropriate expiry, send links only to intended recipients, and revoke access when it is no longer required.',
    ],
    [
        'Storage and availability',
        'The service depends on application, database, network, and object-storage providers. Uploads and downloads may be limited by configured workspace policy, provider availability, or provider account limits. No system can guarantee uninterrupted availability.',
    ],
    [
        'Retention and deletion',
        'Items moved to trash may be restored during the configured retention period. After permanent deletion or retention cleanup, recovery may not be possible. Users should not treat trash as a backup.',
    ],
    [
        'Security',
        'Users must protect passwords, recovery codes, authenticator access, and active sessions. Suspected account compromise, unintended sharing, or unauthorized access should be reported promptly to an administrator.',
    ],
    [
        'Administration and enforcement',
        'Administrators may manage accounts, roles, workspace policy, storage limits, audit records, and content where required for security or operations. Access may be suspended when an account is inactive, compromised, or used outside these terms.',
    ],
    [
        'Changes',
        'These terms may be updated as the service, providers, or company requirements change. Continued use after an update means the revised terms apply to subsequent use.',
    ],
];
</script>

<template>
    <SeoHead
        title="Terms of Use"
        description="Terms governing authorized access, file uploads, sharing, retention, security, and administration in Cloud Drive."
        path="/terms"
        type="article"
    />
    <PublicLayout>
        <article class="mx-auto max-w-4xl px-5 py-16 sm:px-8 sm:py-24">
            <p class="text-sm font-semibold text-brand">Terms of Use</p>
            <h1 class="mt-5 text-4xl font-semibold sm:text-6xl">
                Use Cloud Drive responsibly.
            </h1>
            <p class="mt-6 max-w-3xl text-lg leading-8 text-muted-foreground">
                These terms govern access to Cloud Drive, including uploads,
                folder management, previews, downloads, public share links,
                recovery, and administrative controls.
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
