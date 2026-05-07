<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($theme ?? 'system') == 'dark'])>
    <head>
        @php
            $appName = config('app.name') === 'Laravel' ? 'Cloud Drive' : config('app.name', 'Cloud Drive');
            $requestPath = request()->path() === '/' ? '/' : '/'.trim(request()->path(), '/');
            $isHome = $requestPath === '/';
            $isPrivacy = $requestPath === '/privacy';
            $isIndexable = $isHome || $isPrivacy;
            $pageTitle = $isPrivacy ? 'Privacy | '.$appName : $appName;
            $pageDescription = $isPrivacy
                ? 'How Cloud Drive handles metadata, direct Backblaze B2 file transfers, signed download links, and audit logs.'
                : 'Private team file management with direct Backblaze B2 uploads, download-only share links, retention, audit logs, and admin controls.';
            $canonicalUrl = url($requestPath);
            $robots = $isIndexable ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1' : 'noindex,nofollow,noarchive';
        @endphp
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ $appName }}">
        <meta name="description" content="{{ $pageDescription }}">
        <meta name="robots" content="{{ $robots }}">
        <meta name="theme-color" content="#197a68">
        @if ($isIndexable)
            <link rel="canonical" href="{{ $canonicalUrl }}">
        @endif
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:image" content="{{ url('/apple-touch-icon.png') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        <meta name="twitter:description" content="{{ $pageDescription }}">
        <meta name="twitter:image" content="{{ url('/apple-touch-icon.png') }}">

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

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

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
