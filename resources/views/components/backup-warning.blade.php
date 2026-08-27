{{--
    備份過期警告。

    放在共用 layout 裡而不是某個頁面：備份最常見的失敗方式是「默默停掉
    很久都沒有人發現」，所以它必須出現在使用者本來就會看到的地方，而不是
    等人想起來去查。

    只顯示給有稽核權限的帳號——導師與學生看到也無從處理（見
    config/backup.php 的 warning_permission）。

    print:hidden：這是維運訊息，印出來的點名單上不該出現。
--}}
@if (\App\Support\BackupStatus::enabled() && auth()->user()?->can(config('backup.warning_permission')) && \App\Support\BackupStatus::isStale())
    <div class="mx-auto max-w-6xl px-4 pt-4 print:hidden">
        <div class="alert-error flex items-start gap-2">
            <span aria-hidden="true">⚠</span>
            <span>
                <strong>資料庫備份異常</strong><br>
                {{ \App\Support\BackupStatus::warningMessage() }}
            </span>
        </div>
    </div>
@endif
