{{--
    全螢幕常駐顯示的邏輯放在這個內層 wrapper 上，不是放在 Livewire 元件
    的根節點：根節點帶著 wire:poll.15s，每次輪詢 Livewire 都會 morph 它，
    x-data 的狀態（目前算出來的字級、MutationObserver）會比較容易被重建。

    fit() 用二分搜尋找出「剛好塞得下這個高度」的字級：全螢幕時 CSS 把
    表格容器固定成剩餘高度且 overflow:hidden，這裡只負責找出讓
    scrollHeight 不超過 clientHeight 的最大字級，避免要捲動才看得完。
    表格內所有字級／內距在全螢幕時都改用 em（見 app.css），所以只要調
    這一個根字級，整張表會等比例縮放。

    MutationObserver 而不是掛 Livewire 的 hook：每 15 秒輪詢回來的資料
    可能讓「需留意學生」變多、列變高，需要重算。用 DOM 層的觀察者跟
    框架的內部 API 解耦，Livewire 換版本也不會壞。觀察對象是表格內容，
    字級設在外層容器上，不會觸發自己再觀察到自己造成無限迴圈。
--}}
<div wire:poll.15s class="status-board mx-auto max-w-5xl px-4 py-10">
    <div
        x-data="{
            active: false,
            observer: null,
            toggle() {
                if (document.fullscreenElement || document.webkitFullscreenElement) {
                    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                } else {
                    const el = document.documentElement;
                    (el.requestFullscreen || el.webkitRequestFullscreen).call(el);
                }
            },
            sync() {
                this.active = !! (document.fullscreenElement || document.webkitFullscreenElement);
                document.documentElement.classList.toggle('board-fullscreen', this.active);

                if (this.active) {
                    this.schedule();
                } else {
                    document.documentElement.style.removeProperty('--board-font-size');
                    document.documentElement.classList.remove('board-overflow');
                }
            },
            /*
             * 等版面真的重排完再量。fullscreenchange 觸發的當下瀏覽器還在
             * 做全螢幕轉場，此時量到的高度不是最終值；$nextTick 只是
             * microtask，排在瀏覽器重排之前，量到的一樣是舊值。連續兩個
             * requestAnimationFrame 才能確定跨過一次真正的重排。
             */
            schedule() {
                requestAnimationFrame(() => requestAnimationFrame(() => this.fit()));
            },
            fit() {
                if (! this.active) return;

                const wrap = this.$refs.tableWrap;
                const root = document.documentElement;
                const apply = (size) => root.style.setProperty('--board-font-size', size + 'px');

                // 版面還沒成形時量出來的高度沒有意義，硬算會得到一個過小
                // 或過大的字級並且就這樣停在那裡（下次重算要等輪詢或
                // 改變視窗大小）。這種時候直接重排一次再試。
                if (wrap.clientHeight < 50) {
                    this.schedule();

                    return;
                }

                let low = 8;
                let high = 96;
                let best = low;

                for (let i = 0; i < 12; i++) {
                    const mid = (low + high) / 2;
                    apply(mid);

                    if (wrap.scrollHeight <= wrap.clientHeight) {
                        best = mid;
                        low = mid;
                    } else {
                        high = mid;
                    }
                }

                apply(best);

                // 極端情況（班級太多／需留意學生太長）連最小字級都塞不下時，
                // 容器的 overflow:hidden 會把後面的班級直接裁掉、而且完全
                // 看不出來少了東西。這種時候寧可退回可捲動，也不要靜靜地
                // 把資料藏起來——常駐看板上「漏掉一個班」比「要捲一下」
                // 嚴重得多。
                root.classList.toggle('board-overflow', wrap.scrollHeight > wrap.clientHeight);
            },
            init() {
                this.sync();

                this.observer = new MutationObserver(() => this.schedule());
                this.observer.observe(this.$refs.tableWrap, { childList: true, subtree: true, characterData: true });
            },
            destroy() {
                this.observer?.disconnect();
            },
        }"
        x-on:fullscreenchange.document="sync()"
        x-on:webkitfullscreenchange.document="sync()"
        x-on:resize.window.debounce.150ms="schedule()"
        class="board-shell"
    >
    <div class="board-chrome flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">即時點名看板</h1>
            <p class="page-subtitle mt-1">
                顯示範圍：
                <span @unless (\App\Support\AcademicPeriod::isSelectedCurrent()) class="font-medium text-amber-600 dark:text-amber-400" @endunless>
                    {{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
                    @unless (\App\Support\AcademicPeriod::isSelectedCurrent())
                        （非本學期）
                    @endunless
                </span>
            </p>
        </div>

        <div class="flex items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">日期</label>
                <div class="mt-1 flex items-center gap-1.5">
                    <input type="date" wire:model.live="date" class="field-input mt-0 py-1.5">
                    {{-- 這個看板本來就是查「某一天」的點名狀況，切到別天很正常，
                         但一樣容易忘記自己不是在看今天——跟學年度／學期的
                         「非本學期」提示同一個理由。 --}}
                    @unless ($date === now()->toDateString())
                        <span class="badge-warning whitespace-nowrap" title="今天是 {{ now()->format('Y-m-d') }}">
                            ⚠ 非本日
                        </span>
                    @endunless
                </div>
            </div>

            {{--
                這個看板會被放在辦公室的螢幕上整天常駐顯示。全螢幕時畫面
                上只留表格本身，標題／說明／日期／這顆按鈕都會被藏起來
                （.board-chrome，見 app.css），離開按 Esc。

                光是全螢幕不會讓字變大——requestFullscreen() 只是把瀏覽器
                外框讓出來，內容仍以相同的 CSS 像素渲染。真正解決「字太小」
                的是上面 x-data 的 fit()：量出剩餘高度後二分搜尋出剛好塞得
                下的字級，所以放大的同時也保證不會需要捲動。

                對 document.documentElement 全螢幕，不是對表格容器：這頁有
                wire:poll.15s，每 15 秒 Livewire 會回來重繪 DOM，如果全螢幕
                的目標節點在 morph 過程被換掉，瀏覽器會直接跳出全螢幕，變成
                每 15 秒閃一次。<html> 永遠不會被 Livewire 動到，「只顯示
                表格」則交給 CSS 把其他東西藏起來，效果一樣但沒有這個風險。

                監聽 fullscreenchange 而不是只在點擊時切換自己的狀態：
                使用者按 Esc 離開時不會經過這顆按鈕，沒有這個監聽，
                .board-fullscreen 這個 class 會留在 <html> 上收不回來。

                放大的樣式掛在 <html> 的 .board-fullscreen class 上，而不是
                用 :fullscreen 偽類——舊瀏覽器只認得 :-webkit-full-screen，
                而 CSS 選擇器清單只要其中一個不合法，整條規則會被整個丟掉
                （不是只丟掉那一個選擇器），所以混寫前綴版跟標準版反而更
                危險。自己掛 class 就完全不用碰這個相容性問題。
            --}}
            <button type="button" x-on:click="toggle()" class="btn-secondary" title="全螢幕時只顯示表格本身，按 Esc 離開">
                全螢幕
            </button>
        </div>
    </div>

    <p class="board-chrome mt-2 text-xs text-slate-400 dark:text-slate-500">每 15 秒自動更新一次。上午/中午/下午三個時段一次顯示，遲到算「出席」、早退算「缺席」。</p>

    <div x-ref="tableWrap" class="table-wrap mt-4">
        <table class="data-table">
            {{--
                班級／應到／需留意學生各自 rowspan 兩列，時段名稱橫跨
                出席/缺席兩個子欄，欄寬用 colgroup 固定比例，不受「已點名
                /尚未點名」文字或未點名時 colspan 併格影響。
            --}}
            <colgroup>
                <col style="width: 14%">
                <col style="width: 8%">
                @foreach ($periods as $periodValue => $periodLabel)
                    <col style="width: 7%">
                    <col style="width: 7%">
                @endforeach
                <col style="width: 36%">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2">班級</th>
                    <th rowspan="2">應到</th>
                    @foreach ($periods as $periodValue => $periodLabel)
                        <th colspan="2">{{ $periodLabel }}</th>
                    @endforeach
                    <th rowspan="2">需留意學生</th>
                </tr>
                <tr>
                    {{--
                        divide-x（.data-table thead tr）只在同一列的相鄰
                        儲存格之間畫線，但這一列最後一格「缺席」右邊緊接
                        的是上一列的 rowspan="2"「需留意學生」——兩者不在
                        同一個 <tr> 裡，divide-x 的 sibling 選擇器完全不會
                        套用到它們之間，導致這條理應延續兩列的直向格線在
                        第二列憑空斷掉。這裡手動幫最後一個「缺席」補上同
                        色的右邊框，接上第一列在「需留意學生」左邊那條線
                        ——用 border-r-slate-200（只設定右邊框顏色）而不是
                        plain 的 border-slate-200，理由見 app.css 裡
                        .data-table thead th 那條規則的說明：plain 的
                        border-{color} 會連這格自己的下邊框顏色也一起蓋掉。
                    --}}
                    @foreach ($periods as $periodValue => $periodLabel)
                        <th>出席</th>
                        <th @if ($loop->last) class="border-r border-r-slate-200 dark:border-r-slate-800" @endif>缺席</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($summaries as $summary)
                    <tr>
                        <td class="align-top font-medium text-slate-900 dark:text-slate-100">{{ $summary['class']->shortLabel() }}</td>
                        <td class="align-top">{{ $summary['total'] }}</td>

                        @foreach ($periods as $periodValue => $periodLabel)
                            @php $period = $summary['periods'][$periodValue]; @endphp
                            @if ($period['submitted'])
                                <td class="align-top">{{ $period['present'] }}</td>
                                <td class="align-top">{{ $period['absent'] }}</td>
                            @else
                                <td colspan="2" class="align-top text-xs text-slate-400 dark:text-slate-500">未點名</td>
                            @endif
                        @endforeach

                        <td class="align-top">
                            @forelse ($summary['exceptions'] as $exception)
                                <div
                                    class="mb-0.5 inline-block"
                                    x-data="{ open: false, style: '' }"
                                    x-on:mouseenter="
                                        const rect = $el.getBoundingClientRect();
                                        style = 'left:' + rect.left + 'px; top:' + (rect.bottom + 4) + 'px;';
                                        open = true;
                                    "
                                    x-on:mouseleave="open = false"
                                >
                                    <span class="cursor-help text-xs text-slate-600 underline decoration-dotted decoration-slate-400 dark:text-slate-400">
                                        {{ $exception['name'] }}（{{ $exception['period'] }}{{ $exception['status'] }}）
                                    </span>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition
                                        :style="style"
                                        class="surface fixed z-50 w-64 p-3 text-xs text-slate-600 shadow-lg dark:text-slate-300"
                                    >
                                        @if ($exception['followUps']->isEmpty())
                                            <p class="text-slate-400 dark:text-slate-500">尚無處理情形記錄。</p>
                                        @else
                                            <ul class="space-y-1">
                                                @foreach ($exception['followUps'] as $followUp)
                                                    <li>
                                                        <span class="text-slate-400 dark:text-slate-500">{{ $followUp->created_at->format('H:i') }}</span>
                                                        {{ $followUp->content }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </div>
                                <br>
                            @empty
                                <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 3 + count($periods) * 2 }}" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            目前還沒有任何班級資料。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </div>
</div>
