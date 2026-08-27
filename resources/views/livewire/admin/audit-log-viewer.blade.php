@php use App\Support\AuditLogPresenter; @endphp

<div class="mx-auto max-w-6xl px-4 py-10">
    <div>
        <h1 class="page-title">稽核紀錄</h1>
        <p class="page-subtitle mt-1">
            系統會記錄每一次登入、點名送出、出席狀態變更與後台管理動作。這個頁面是唯讀的
            ——紀錄一旦寫下就不會被畫面改動或刪除，這正是它能當作憑據的原因。
        </p>
    </div>

    <div class="filter-bar mt-6">
        <div class="filter-field grow">
            <label class="field-label">搜尋內容</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="動作、姓名、班級、帳號…"
                class="field-input"
            >
        </div>

        <div class="filter-field">
            <label class="field-label">類別</label>
            <select wire:model.live="categoryFilter" class="field-input">
                <option value="">全部</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="filter-field">
            <label class="field-label">操作者</label>
            <select wire:model.live="causerFilter" class="field-input">
                <option value="">全部</option>
                @foreach ($causers as $causer)
                    <option value="{{ $causer->id }}">{{ $causer->name }}（{{ $causer->username }}）</option>
                @endforeach
            </select>
        </div>

        <div class="filter-field">
            <label class="field-label">起始日期</label>
            <input type="date" wire:model.live="fromDate" class="field-input">
        </div>

        <div class="filter-field">
            <label class="field-label">結束日期</label>
            <input type="date" wire:model.live="toDate" class="field-input">
        </div>

        @if ($this->hasActiveFilters())
            <button type="button" wire:click="clearFilters" class="btn-secondary btn-xs">
                清除條件
            </button>
        @endif
    </div>

    <div class="table-wrap mt-4">
        <table class="data-table">
            <colgroup>
                <col style="width: 14%">
                <col style="width: 16%">
                <col style="width: 10%">
                <col style="width: 18%">
                <col style="width: 34%">
                <col style="width: 8%">
            </colgroup>
            <thead>
                <tr>
                    <th>時間</th>
                    <th>操作者</th>
                    <th>類別</th>
                    <th>動作</th>
                    <th>摘要</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activities as $activity)
                    <tr>
                        <td class="whitespace-nowrap text-slate-500 dark:text-slate-400">
                            {{ $activity->created_at?->format('Y-m-d H:i:s') }}
                        </td>
                        <td>
                            {{-- causer 為 null 代表這個動作發生時沒有登入者
                                 （例如「帳號不存在」的登入失敗），不是資料
                                 缺漏。 --}}
                            @if ($activity->causer)
                                {{ $activity->causer->name }}
                                <span class="text-xs text-slate-500 dark:text-slate-400">
                                    （{{ $activity->causer->username }}）
                                </span>
                            @else
                                <span class="text-slate-400 dark:text-slate-500">未登入</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge-neutral">{{ AuditLogPresenter::category($activity) }}</span>
                        </td>
                        <td>{{ AuditLogPresenter::action($activity) }}</td>
                        <td class="text-slate-600 dark:text-slate-300">
                            {{ AuditLogPresenter::summary($activity) }}
                        </td>
                        <td>
                            <div class="action-group">
                                <button type="button" wire:click="toggleDetails({{ $activity->id }})" class="btn-secondary btn-xs">
                                    {{ $expandedId === $activity->id ? '收合' : '明細' }}
                                </button>
                            </div>
                        </td>
                    </tr>

                    @if ($expandedId === $activity->id)
                        <tr>
                            <td colspan="6" class="bg-slate-50 dark:bg-slate-800/40">
                                @php $details = AuditLogPresenter::details($activity); @endphp
                                @if (empty($details))
                                    <p class="text-sm text-slate-500 dark:text-slate-400">這筆紀錄沒有額外的明細資料。</p>
                                @else
                                    <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                        @foreach ($details as $label => $value)
                                            <div class="flex gap-2 text-sm">
                                                <dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ $label }}：</dt>
                                                <dd class="break-all text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-slate-500 dark:text-slate-400">
                            找不到符合條件的紀錄。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $activities->links() }}
    </div>
</div>
