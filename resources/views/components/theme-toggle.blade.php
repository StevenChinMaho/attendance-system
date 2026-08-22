{{--
    深色／淺色切換。用 Alpine（Livewire 已內建 @livewireScripts 裡，不需要
    另外安裝套件）——初始狀態直接讀 <html> 目前有沒有 .dark（layouts/app.blade.php
    的行內 script 在畫面畫出來之前就決定好了），切換時同時更新 class 跟
    localStorage，之後整個 session 都跟著這個選擇走，直到使用者自己再切換。
--}}
<div
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    x-init="$watch('dark', value => {
        document.documentElement.classList.toggle('dark', value);
        localStorage.setItem('theme', value ? 'dark' : 'light');
    })"
>
    <button
        type="button"
        x-on:click="dark = !dark"
        class="btn-ghost btn-xs"
        :aria-label="dark ? '切換為淺色模式' : '切換為深色模式'"
        title="切換深色／淺色模式"
    >
        <span x-show="!dark" aria-hidden="true">🌙</span>
        <span x-show="dark" aria-hidden="true" style="display: none;">☀️</span>
    </button>
</div>
