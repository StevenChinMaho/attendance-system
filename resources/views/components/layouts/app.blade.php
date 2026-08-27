<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        // 在任何內容畫出來之前就決定深/淺色，避免先閃一次錯的顏色再跳到
        // 使用者實際選擇的模式。沒存過選擇時跟隨系統設定。
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored ? stored === 'dark' : prefersDark);
            } catch (e) {}
        })();
    </script>
    <title>{{ $title ?? '國中點名系統' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased">
    @auth
        <x-nav-bar />
        <x-backup-warning />
    @endauth
    {{ $slot }}
    @livewireScripts
</body>
</html>
