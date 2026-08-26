<div class="mx-auto max-w-3xl px-4 py-10 print:max-w-none print:px-0 print:py-0">
    {{-- 只有紙本才看得到的抬頭：瀏覽器自己印的頁首頁尾（網址、頁碼、
         系統日期）網頁端關不掉，而且它印的是「列印當下」而不是「這份
         名單是哪一天的」，補印昨天的名單時那個日期會是錯的，所以標題
         跟日期一定要自己畫一份。 --}}
    <div class="hidden print:mb-4 print:block">
        <h1 class="text-center text-xl font-bold">上午缺席詳細清單</h1>
        <p class="mt-1 text-center text-sm">
            {{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
            ｜ 日期：{{ \Illuminate\Support\Carbon::parse($date)->format('Y-m-d') }}
            ｜ 列印時間：{{ now()->format('Y-m-d H:i') }}
        </p>
    </div>

    <div class="flex items-start justify-between print:hidden">
        <div>
            <h1 class="page-title">上午缺席詳細清單</h1>
            <p class="page-subtitle mt-1">
                顯示範圍：
                <span @unless (\App\Support\AcademicPeriod::isSelectedCurrent()) class="font-medium text-amber-600 dark:text-amber-400" @endunless>
                    {{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
                    @unless (\App\Support\AcademicPeriod::isSelectedCurrent())
                        （非本學期）
                    @endunless
                </span>
                ，只列出上午時段「缺席」或「早退」的學生（遲到算有到校，不列入）。
            </p>
        </div>

        <button type="button" onclick="window.print()" class="btn-primary shrink-0">
            列印
        </button>
    </div>

    <div class="mt-6 flex flex-wrap items-end gap-4 print:hidden">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">日期</label>
            <div class="mt-1 flex items-center gap-1.5">
                <input type="date" wire:model.live="date" class="field-input mt-0 py-1.5">
                {{-- 理由同 Recorder/StatusBoard：切到別天後很容易忘記，
                     而印出來的紙看起來跟今天的一模一樣。 --}}
                @unless ($date === now()->toDateString())
                    <span class="badge-warning whitespace-nowrap" title="今天是 {{ now()->format('Y-m-d') }}">
                        ⚠ 非本日
                    </span>
                @endunless
            </div>
        </div>
    </div>

    @if ($rows->isEmpty())
        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">這個學年度／學期底下還沒有班級。</p>
    @else
        <div class="table-wrap mt-6 print:mt-0 print:overflow-visible">
            <table class="data-table print-table">
                <colgroup>
                    <col style="width: 25%">
                    <col style="width: 25%">
                    <col style="width: 50%">
                </colgroup>
                <thead>
                    <tr>
                        <th>班級</th>
                        <th>座號</th>
                        <th>姓名</th>
                    </tr>
                </thead>
                @foreach ($rows as $row)
                    {{-- 一個班一個 tbody：rowspan 的班級欄跟它底下的學生
                         必須整組留在同一頁，不然第二頁會出現沒有班級欄的
                         孤兒列（rowspan 留在上一頁）。分頁控制寫在 tbody
                         上，見 app.css 的 @media print。 --}}
                    <tbody>
                        @if ($row['absentees']->isEmpty())
                            <tr>
                                <td>{{ $row['code'] }}</td>
                                {{-- 「到齊」跟「未送出」意義完全相反（一個是已回報且全員到，
                                     一個是根本還沒回報），螢幕上要一眼分得出來，不能只是
                                     兩個同樣灰色的詞。列印時徽章造型會被拆掉回到純文字，
                                     見 app.css 的 @media print。 --}}
                                <td colspan="2">
                                    @if ($row['submitted'])
                                        <span class="badge-success">到齊</span>
                                    @else
                                        <span class="badge-danger">未送出</span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            @foreach ($row['absentees'] as $index => $absentee)
                                <tr>
                                    @if ($index === 0)
                                        <td rowspan="{{ $row['absentees']->count() }}" class="align-top">
                                            {{ $row['code'] }}
                                        </td>
                                    @endif
                                    <td>{{ $absentee['seat_number'] }}</td>
                                    <td>
                                        {{ $absentee['name'] }}
                                        <span class="text-xs text-slate-500 dark:text-slate-400">（{{ $absentee['status'] }}）</span>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>
