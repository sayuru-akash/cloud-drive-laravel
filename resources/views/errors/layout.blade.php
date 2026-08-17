<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#197a68">
    <title>{{ $title }} | Cloud Drive</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script>
        (function () {
            var storedTheme = localStorage.getItem('theme');
            var cookieTheme = document.cookie.match(/(?:^|; )theme=([^;]+)/);
            var theme = storedTheme || (cookieTheme ? decodeURIComponent(cookieTheme[1]) : 'system');
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', isDark);
        })();
    </script>
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; background: #f7f4ee; color: #111827; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: flex; flex-direction: column; }
        header, footer { border-color: rgba(15, 23, 42, .1); border-style: solid; }
        header { border-width: 0 0 1px; }
        footer { border-width: 1px 0 0; }
        .bar { width: min(100% - 40px, 1200px); min-height: 64px; margin: auto; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .brand { display: inline-flex; align-items: center; gap: 10px; color: inherit; font-weight: 650; text-decoration: none; }
        .brand img { width: 38px; height: 38px; }
        main { width: min(100% - 40px, 880px); margin: auto; padding: 72px 0; }
        .code { color: #197a68; font-size: 14px; font-weight: 650; }
        h1 { max-width: 760px; margin: 18px 0 0; font-size: clamp(42px, 8vw, 72px); line-height: 1.05; font-weight: 650; }
        p { max-width: 650px; margin: 24px 0 0; color: #5f6877; font-size: 18px; line-height: 1.7; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }
        .button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; border: 1px solid rgba(15, 23, 42, .1); border-radius: 6px; padding: 0 18px; background: #fffdf8; color: inherit; font-size: 14px; font-weight: 650; text-decoration: none; }
        .button.primary { border-color: #111827; background: #111827; color: #fff; }
        footer .bar { color: #5f6877; font-size: 13px; }
        :root.dark { color-scheme: dark; background: #111827; color: #f8fafc; }
        :root.dark header, :root.dark footer { border-color: rgba(255, 255, 255, .12); }
        :root.dark p, :root.dark footer .bar { color: #c7cfdb; }
        :root.dark .button { border-color: rgba(255, 255, 255, .12); background: #18212f; }
        :root.dark .button.primary { border-color: #fff; background: #fff; color: #111827; }
    </style>
</head>
<body>
    <header><div class="bar"><a class="brand" href="/"><img src="/favicon.svg" alt=""><span>Cloud Drive</span></a></div></header>
    <main>
        <div class="code">{{ $status }}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            <a class="button primary" href="/">Go to home</a>
            <a class="button" href="/login">Log in</a>
        </div>
    </main>
    <footer><div class="bar">Cloud Drive by Codezela Technologies</div></footer>
</body>
</html>
