<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($theme ?? 'system') == 'dark'])>
    <head>
        @php
            $appName = config('app.name') === 'Laravel' ? 'Cloud Drive' : config('app.name', 'Cloud Drive');
            $requestPath = request()->path() === '/' ? '/' : '/'.trim(request()->path(), '/');
            $isShare = str_starts_with($requestPath, '/s/');
            $isPrivacy = $requestPath === '/privacy';
            $isTerms = $requestPath === '/terms';
            $isIndexable = in_array($requestPath, ['/', '/privacy', '/terms'], true);
            $pageTitle = match (true) {
                $isShare => 'Secure file share | '.$appName,
                $isPrivacy => 'Privacy Policy | '.$appName,
                $isTerms => 'Terms of Use | '.$appName,
                $requestPath === '/' => 'Private Team File Workspace | '.$appName,
                default => $appName,
            };
            $pageDescription = match (true) {
                $isShare => 'Secure download-only file sharing for authorized Cloud Drive recipients.',
                $isPrivacy => 'How Cloud Drive handles account data, file metadata, private object storage, signed links, sharing, retention, and audit records.',
                $isTerms => 'Terms governing authorized access, file uploads, sharing, retention, security, and administration in Cloud Drive.',
                default => 'Cloud Drive is a private team file workspace for direct uploads, nested folders, controlled sharing, previews, retention, audit logs, and admin access.',
            };
            $previewUrl = $isShare ? url('/') : url($requestPath);
            $previewImage = url('/og-image.png');
            $robots = $isIndexable
                ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
                : 'noindex,nofollow,noarchive';
        @endphp
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ $appName }}">
        <meta name="apple-mobile-web-app-title" content="{{ $appName }}">
        <meta name="description" content="{{ $pageDescription }}">
        <meta name="robots" content="{{ $robots }}">
        <meta name="theme-color" content="#197a68">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:locale" content="en_US">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $previewUrl }}">
        <meta property="og:image" content="{{ $previewImage }}">
        <meta property="og:image:secure_url" content="{{ $previewImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $appName }} secure file workspace">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        <meta name="twitter:description" content="{{ $pageDescription }}">
        <meta name="twitter:image" content="{{ $previewImage }}">
        <meta name="twitter:image:alt" content="{{ $appName }} secure file workspace">
        @if ($isIndexable)
            <link rel="canonical" href="{{ $previewUrl }}">
            <script type="application/ld+json">{!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => $requestPath === '/' ? 'WebApplication' : 'WebPage',
                'name' => $pageTitle,
                'description' => $pageDescription,
                'url' => $previewUrl,
                'provider' => [
                    '@type' => 'Organization',
                    'name' => 'Codezela Technologies',
                    'url' => 'https://codezela.com',
                ],
            ], JSON_UNESCAPED_SLASHES) !!}</script>
        @endif

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const theme = '{{ $theme ?? "system" }}';

                if (theme === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $pageTitle }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
