#!/bin/sh
#
# 資料庫自動備份。跑在 compose 的 backup 服務裡（見 compose.production.yaml）。
#
# 為什麼是容器而不是主機 cron：主機 cron 是「主機專屬」的設定，換一台
# 機器就要重做一次，而忘記手動步驟這件事在這個專案已經出過兩次事。
# 容器化的話 `docker compose up -d` 就自動生效，換到哪台主機都一樣，
# 備份目錄則由 BACKUP_PATH 這個環境變數決定，換主機只要改一行。
#
# 用 mariadb:12.3 這個映像（compose 裡本來就有這個服務用它）——它內建
# mariadb-dump，不必為了備份多拉或多建一個映像。
#
# 密碼一律透過 MYSQL_PWD 環境變數傳給 client，不放在命令列參數上，
# 免得出現在容器內的 process list（`ps` 看得到 argv，看不到 env）。
#
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_HOUR="${BACKUP_HOUR:-3}"
KEEP_DAILY="${BACKUP_KEEP_DAILY:-30}"
KEEP_MONTHLY="${BACKUP_KEEP_MONTHLY:-12}"

# 檔案小於這個大小就視為失敗。一份只有結構、沒有資料的 dump 壓縮後
# 大約是幾 KB；真的備份成功時遠大於此。這是用來擋「dump 中途失敗但
# 檔案還是生出來了」——那種檔案看起來存在，還原時才發現是空的。
MIN_BYTES="${BACKUP_MIN_BYTES:-2048}"

# 異地鏡像的目標（容器內路徑）。空的就是沒有設定異地備份，整段跳過。
# compose 只在 BACKUP_MIRROR_PATH 有值時把它設成 /mirror，見 compose.production.yaml。
MIRROR_DIR="${BACKUP_MIRROR:-}"

# 目標目錄裡必須有這個檔案才會同步。理由見 mirror_ready()。
MIRROR_SENTINEL=".attendance-mirror-target"

log() {
    echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') $*"
}

fail() {
    log "錯誤：$*"
    return 1
}

require_env() {
    for name in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do
        eval "value=\${$name:-}"
        [ -n "$value" ] || { log "錯誤：環境變數 $name 沒有設定"; exit 1; }
    done
}

# mariadb client 的共用呼叫方式。密碼走 MYSQL_PWD，不進 argv。
db_query() {
    MYSQL_PWD="$DB_PASSWORD" mariadb \
        --host="$DB_HOST" --user="$DB_USERNAME" \
        --skip-column-names --batch "$DB_DATABASE" -e "$1"
}

wait_for_db() {
    i=0
    while ! db_query 'select 1' >/dev/null 2>&1; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            log "錯誤：資料庫在 60 秒內沒有回應"
            return 1
        fi
        sleep 1
    done
}

# 等 backup_runs 資料表出現。
#
# backup 容器不依賴 app 容器（兩者都只等 mariadb healthy），所以第一次
# 部署時，備份很可能在 app 跑完 migration 之前就完成了——那一刻
# backup_runs 還不存在，心跳寫不進去。備份檔本身是好的，但後台會顯示
# 「從來沒有成功備份過」直到隔天凌晨的下一次備份為止。
#
# **一個在上線第一天就誤報的監控訊號，會教會使用者忽略它**，那等於毀掉
# 它的全部價值，所以這裡寧可等一下。等不到也不放棄備份——備份檔遠比
# 心跳重要，而且 sync_heartbeats() 之後會把漏掉的補回來。
wait_for_table() {
    i=0
    while ! db_query 'select 1 from backup_runs limit 1;' >/dev/null 2>&1; do
        i=$((i + 1))

        [ "$i" -eq 1 ] && log "等待 backup_runs 資料表（app 容器正在跑 migration）..."

        if [ "$i" -ge 120 ]; then
            log "警告：等了 120 秒 backup_runs 仍不存在，先繼續備份"
            return 1
        fi

        sleep 1
    done
}

# 心跳：讓應用程式知道「上一次成功備份是什麼時候」，用來在後台顯示
# 過期警告（見 config/backup.php 與 App\Support\BackupStatus）。
#
# 這裡刻意做成「對照磁碟上的檔案補齊」而不是「備份完寫一筆」：**磁碟上
# 的檔案才是事實**，心跳只是給應用程式看的投影。用對照的寫法，任何一次
# 寫入失敗（第一次部署的競態、資料庫短暫不通）都會在下一次備份或下一次
# 容器啟動時自動補回來，不需要人工介入——原本的寫法只記一行警告就算了，
# 於是那筆心跳永遠不會出現。
#
# 用檔案本身的時間當 completed_at，所以補回來的紀錄仍然反映真實的備份
# 時間；補一份三天前的舊檔案不會讓過期警告誤判成健康。
sync_heartbeats() {
    existing=$(db_query 'select file_name from backup_runs;' 2>/dev/null) || {
        log "警告：讀不到 backup_runs（資料表可能還沒建立），心跳留待下次補上"
        return 0
    }

    inserted=0

    for path in "$BACKUP_DIR"/daily/*.sql.gz; do
        [ -f "$path" ] || continue

        name=$(basename "$path")
        printf '%s\n' "$existing" | grep -qxF "$name" && continue

        completed=$(date -r "$path" '+%Y-%m-%d %H:%M:%S')
        size=$(wc -c < "$path" | tr -d ' ')

        if db_query "insert into backup_runs (completed_at, file_name, size_bytes, created_at, updated_at)
                     values ('$completed', '$name', $size, '$completed', '$completed');" >/dev/null 2>&1; then
            inserted=$((inserted + 1))
        else
            log "警告：$name 的心跳寫入失敗"
        fi
    done

    [ "$inserted" -eq 0 ] || log "補上 $inserted 筆備份心跳"
}

rotate() {
    # 每日：只留最新的 N 份。`ls -1 | sort` 因為檔名開頭是日期，
    # 字典序就等於時間序。
    count=$(find "$BACKUP_DIR/daily" -name '*.sql.gz' | wc -l)
    if [ "$count" -gt "$KEEP_DAILY" ]; then
        remove=$((count - KEEP_DAILY))
        find "$BACKUP_DIR/daily" -name '*.sql.gz' | sort | head -n "$remove" | while read -r old; do
            rm -f "$old"
            log "輪替：刪除舊的每日備份 $(basename "$old")"
        done
    fi

    # 每月：只留最新的 M 份。
    count=$(find "$BACKUP_DIR/monthly" -name '*.sql.gz' | wc -l)
    if [ "$count" -gt "$KEEP_MONTHLY" ]; then
        remove=$((count - KEEP_MONTHLY))
        find "$BACKUP_DIR/monthly" -name '*.sql.gz' | sort | head -n "$remove" | while read -r old; do
            rm -f "$old"
            log "輪替：刪除舊的每月備份 $(basename "$old")"
        done
    fi
}

run_backup() {
    mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/monthly"

    stamp="$(date '+%Y-%m-%d-%H%M%S')"
    month="$(date '+%Y-%m')"
    name="attendance-${stamp}.sql.gz"
    target="$BACKUP_DIR/daily/$name"
    # 先寫到 .tmp 再改名：中途失敗的半份檔案永遠不會被誤認成可用備份，
    # 因為它不會有正式的檔名。
    tmp="$target.tmp"

    log "開始備份 $DB_DATABASE"

    # --single-transaction：對 InnoDB 取一致性快照而「不鎖表」。學校白天
    #   整天都在點名，備份不能讓系統卡住。
    # --quick：逐列串流而不是整張表讀進記憶體。
    # --no-tablespaces：一般使用者沒有 PROCESS 權限，不加會直接失敗。
    if ! MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
            --host="$DB_HOST" --user="$DB_USERNAME" \
            --single-transaction --quick --no-tablespaces \
            --default-character-set=utf8mb4 \
            "$DB_DATABASE" 2>/tmp/dump-err | gzip > "$tmp"; then
        rm -f "$tmp"
        fail "mariadb-dump 失敗：$(cat /tmp/dump-err 2>/dev/null | tail -3)"
        return 1
    fi

    size=$(wc -c < "$tmp" | tr -d ' ')

    if [ "$size" -lt "$MIN_BYTES" ]; then
        rm -f "$tmp"
        fail "產生的備份只有 ${size} bytes，小於下限 ${MIN_BYTES}，視為失敗"
        return 1
    fi

    # gzip 自我檢驗，確認檔案不是壞的。
    if ! gzip -t "$tmp" 2>/dev/null; then
        rm -f "$tmp"
        fail "備份檔的 gzip 檢驗沒有通過"
        return 1
    fi

    mv "$tmp" "$target"
    chmod 600 "$target" 2>/dev/null || true

    log "完成 $name（${size} bytes）"

    # 每個月的第一份備份另外留一份長期的。用「這個月的檔案還不存在」
    # 判斷，所以不管當月第一次備份發生在幾號都成立，重跑也不會重複。
    if [ ! -f "$BACKUP_DIR/monthly/attendance-${month}.sql.gz" ]; then
        cp "$target" "$BACKUP_DIR/monthly/attendance-${month}.sql.gz"
        chmod 600 "$BACKUP_DIR/monthly/attendance-${month}.sql.gz" 2>/dev/null || true
        log "另存每月備份 attendance-${month}.sql.gz"
    fi

    sync_heartbeats
    rotate

    # 鏡像失敗不能讓整次備份被判定為失敗——本地備份檔已經好好地寫完了，
    # 那是主要目標。失敗原因會記在 log 裡，而且 verify 會因為 .last-mirror
    # 沒有更新而報出來。
    mirror_sync || true
}

# ---------------------------------------------------------------------
# 異地鏡像：把備份複製到第二顆實體硬碟
#
# 這一段本來是主機上的獨立腳本加 cron，理由是「目標是 WSL 掛載的硬碟，
# 掛載狀態隨時會變，而 docker 的 bind mount 只在容器啟動時解析一次」。
# 搬到獨立的 Ubuntu server 之後那個前提不成立了——第二顆碟由 /etc/fstab
# 開機掛好就一直在——所以整段收進備份容器，跟備份用同一支腳本、同一個
# 排程、同一個身分執行。少一支腳本、少一份 cron、少一個「換機器要重做」
# 的手動步驟，而且 .last-mirror 由寫備份的同一個使用者寫入，不會再出現
# 擁有者對不上而寫不進去的情況。
# ---------------------------------------------------------------------

# 目標可不可以寫。只剩兩項檢查（原本在 WSL 上還要另外確認掛載點不是
# tmpfs，那是 /mnt/wsl 特有的問題，一般 server 沒有）：
#
#   1. sentinel 檔存在——這是最關鍵的一項。硬碟沒掛上的時候，掛載點
#      會是根檔案系統上一個空目錄，看起來完全正常，備份會安靜地寫進
#      系統碟、跟來源躺在一起，等於沒有異地備份。sentinel 是放在「那顆
#      碟上」的，碟沒掛就讀不到。
#   2. 來源與目標在不同的裝置上，否則整件事沒有意義。
mirror_ready() {
    [ -d "$MIRROR_DIR" ] || { log "警告：鏡像目標 $MIRROR_DIR 不存在，略過"; return 1; }

    if [ ! -f "$MIRROR_DIR/$MIRROR_SENTINEL" ]; then
        log "警告：$MIRROR_DIR/$MIRROR_SENTINEL 不存在——目標硬碟可能沒有掛上，略過鏡像"
        return 1
    fi

    src_dev=$(stat -c %d "$BACKUP_DIR" 2>/dev/null || echo "?")
    dst_dev=$(stat -c %d "$MIRROR_DIR" 2>/dev/null || echo "??")

    if [ "$src_dev" = "$dst_dev" ]; then
        log "警告：$MIRROR_DIR 與備份目錄在同一顆碟上，鏡像沒有意義，略過"
        return 1
    fi

    return 0
}

# 只複製「目標端還沒有的檔案」。
#
# 備份檔一旦寫好就不會再被修改（檔名帶時間戳，每月檔也只在當月第一次
# 建立），所以不需要 rsync 的差異比對——一個 for 迴圈就夠，而且 mariadb
# 映像裡本來就沒有 rsync，這樣也不必為了它多裝套件。
#
# 刻意不刪除目標端多出來的檔案。鏡像的目的是備援，不是做出一份一模一樣
# 的副本：來源端不管是正常輪替、還是有人誤刪整個目錄，「同步刪除」都會
# 原封不動地照做一次，那正好把備份最該防的情境變成必然發生。代價是鏡像
# 端會累積，要清理用 `backup.sh mirror-prune <天數>`，那是一個明確的決定。
mirror_sync() {
    [ -n "$MIRROR_DIR" ] || return 0
    mirror_ready || return 1

    copied=0

    for path in "$BACKUP_DIR"/daily/*.sql.gz "$BACKUP_DIR"/monthly/*.sql.gz; do
        [ -f "$path" ] || continue

        rel=${path#"$BACKUP_DIR"/}
        dest="$MIRROR_DIR/$rel"

        [ -f "$dest" ] && continue

        mkdir -p "$(dirname "$dest")"

        # 一樣先寫暫存再改名：複製到一半斷掉的檔案不會有正式檔名，
        # 不可能在還原時被當成可用的備份。-p 保留原本的修改時間，
        # mirror-prune 與人工判斷「這份多舊」才會準。
        if cp -p "$path" "$dest.tmp" && mv "$dest.tmp" "$dest"; then
            copied=$((copied + 1))
        else
            rm -f "$dest.tmp"
            log "警告：複製 $rel 到鏡像失敗"
            return 1
        fi
    done

    # 先刪再寫。用 `>` 直接覆蓋的話，只要這個檔案曾經被別的使用者建立過
    # （例如手動用 sudo 跑過一次），就會 Permission denied——而 rsync／複製
    # 本身是成功的，於是時間戳永遠停在過去，verify 一天比一天喊「鏡像過期」，
    # 看起來壞掉其實沒壞。這種假警報比真的壞掉更麻煩，它會訓練人忽略警告。
    rm -f "$BACKUP_DIR/.last-mirror"
    if ! date '+%Y-%m-%d %H:%M:%S' > "$BACKUP_DIR/.last-mirror"; then
        log "警告：檔案已複製完成，但寫不進 .last-mirror（權限？）"
        return 1
    fi

    log "鏡像同步完成：新增 ${copied} 份 → $MIRROR_DIR"
}

# 刪除鏡像端超過 N 天的備份。刻意做成手動指令而不是自動行為，
# 理由見 mirror_sync() 裡「不刪除」那一段。
mirror_prune() {
    days="${1:-}"
    [ -n "$days" ] || { echo "用法: backup.sh mirror-prune <天數>" >&2; return 64; }
    [ -n "$MIRROR_DIR" ] || { echo "沒有設定 BACKUP_MIRROR_PATH" >&2; return 1; }

    mirror_ready || return 1

    removed=$(find "$MIRROR_DIR" -name '*.sql.gz' -mtime "+$days" -print -delete | wc -l | tr -d ' ')
    log "刪除鏡像端超過 $days 天的備份共 $removed 份"
}

# 檢查最新的備份檔是不是還在、而且夠新。這一項跟資料庫心跳互補：
# 心跳證明「備份程序有跑完」，這裡證明「檔案現在確實還在」。
verify() {
    hours="${1:-48}"
    newest=$(find "$BACKUP_DIR/daily" -name '*.sql.gz' 2>/dev/null | sort | tail -1)

    if [ -z "$newest" ]; then
        echo "沒有找到任何備份檔（$BACKUP_DIR/daily）"
        return 1
    fi

    age_seconds=$(( $(date '+%s') - $(date -r "$newest" '+%s') ))
    age_hours=$(( age_seconds / 3600 ))

    echo "最新備份：$(basename "$newest")（${age_hours} 小時前，$(wc -c < "$newest" | tr -d ' ') bytes）"

    if [ "$age_hours" -gt "$hours" ]; then
        echo "警告：已經超過 ${hours} 小時沒有新的備份"
        return 1
    fi

    if ! gzip -t "$newest" 2>/dev/null; then
        echo "警告：最新備份的 gzip 檢驗沒有通過，檔案可能損壞"
        return 1
    fi

    # 異地鏡像的狀態。時間戳寫在備份目錄裡，所以「檢查備份」一次看完兩邊。
    # 沒有設定鏡像的話不提，避免對還沒做這件事的環境變成雜訊。
    if [ -f "$BACKUP_DIR/.last-mirror" ]; then
        mirror_age=$(( ( $(date '+%s') - $(date -r "$BACKUP_DIR/.last-mirror" '+%s') ) / 3600 ))
        echo "異地鏡像：最後同步於 $(cat "$BACKUP_DIR/.last-mirror")（${mirror_age} 小時前）"

        if [ "$mirror_age" -gt "$hours" ]; then
            echo "警告：異地鏡像已經超過 ${hours} 小時沒有更新，目標硬碟可能沒有掛上"
            return 1
        fi
    elif [ -n "$MIRROR_DIR" ]; then
        # 有設定卻一次都沒成功過——最需要講出來的狀況，代表從一開始就沒設對。
        echo "警告：有設定異地鏡像，但從來沒有成功同步過（$MIRROR_DIR）"
        return 1
    fi

    echo "檢查通過"
}

seconds_until_next_run() {
    now_h=$(date '+%-H')
    now_m=$(date '+%-M')
    now_s=$(date '+%-S')

    now_total=$((now_h * 3600 + now_m * 60 + now_s))
    target_total=$((BACKUP_HOUR * 3600))

    if [ "$target_total" -gt "$now_total" ]; then
        echo $((target_total - now_total))
    else
        echo $((86400 - now_total + target_total))
    fi
}

# 從備份還原。做成子指令而不是另一支主機腳本：備份容器本身就有資料庫
# 連線與備份目錄，在任何主機上都是同一套操作，不必在主機端另外處理
# compose 的參數與 env 檔。
#
# 這是破壞性操作，所以一定要明確加上 --confirm 才會真的執行。
# 把使用者給的檔名解析成實際路徑，順便把各種打錯的情況講清楚。
#
# 這一步刻意跟 restore() 分開，而且在連資料庫「之前」執行：檔名打錯是最
# 常見的失誤，不該讓人先等 60 秒的資料庫逾時才被告知。
resolve_backup_file() {
    file="${1:-}"

    [ -n "$file" ] || { echo "用法: backup.sh restore <檔名> --confirm" >&2; return 64; }

    # 只接受檔名，不接受路徑。容器裡的備份目錄是 $BACKUP_DIR，跟主機上的
    # BACKUP_PATH 不同，所以照著主機的 `ls` 貼路徑進來一定找不到——而
    # 「找不到」配上「我明明看得到這個檔案」是很難自己想通的組合。實際上
    # 有人因此以為是壓縮格式的問題，把備份 gunzip 掉，反而讓那份備份從
    # list／輪替／心跳／異地鏡像裡一起消失。
    case "$file" in
        */*)
            echo "只接受檔名，不要帶路徑：$file" >&2
            echo "（容器裡的備份目錄是 $BACKUP_DIR，跟主機上的路徑不一樣）" >&2
            echo "請改用：backup.sh restore $(basename "$file")" >&2
            return 1
            ;;
    esac

    for candidate in "$BACKUP_DIR/daily/$file" "$BACKUP_DIR/monthly/$file"; do
        if [ -f "$candidate" ]; then
            echo "$candidate"
            return 0
        fi
    done

    echo "找不到備份檔：$file" >&2

    # 少打 .gz
    if [ -f "$BACKUP_DIR/daily/$file.gz" ] || [ -f "$BACKUP_DIR/monthly/$file.gz" ]; then
        echo "目錄裡有 $file.gz——檔名要連副檔名一起打。" >&2
    fi

    # 被 gunzip 過：備份一律以 .sql.gz 保存，解開之後檔名不再符合，
    # 整套機制都會略過它。
    plain=${file%.gz}
    if [ "$plain" != "$file" ] &&
       { [ -f "$BACKUP_DIR/daily/$plain" ] || [ -f "$BACKUP_DIR/monthly/$plain" ]; }; then
        echo "目錄裡有解壓縮過的 $plain。備份一律以 .sql.gz 保存，解開之後" >&2
        echo "list／restore／輪替／心跳／異地鏡像都會看不到它——請先壓回去：" >&2
        echo "  gzip <該檔案>" >&2
    fi

    return 1
}

restore() {
    path="${1:-}"
    confirm="${2:-}"
    file=$(basename "$path")

    gzip -t "$path" 2>/dev/null || { echo "備份檔的 gzip 檢驗沒有通過，拒絕還原：$file" >&2; return 1; }

    if [ "$confirm" != "--confirm" ]; then
        echo "即將把資料庫 $DB_DATABASE 的內容整個換成 $file"
        echo "（$(wc -c < "$path" | tr -d " ") bytes，$(date -r "$path" "+%Y-%m-%d %H:%M")）"
        echo
        echo "這會清掉目前資料庫裡的所有資料表。確定的話請加上 --confirm："
        echo "  backup.sh restore $file --confirm"
        return 1
    fi

    log "還原前先清空 $DB_DATABASE 的資料表"

    # 逐一 drop 而不是 drop database：只需要資料表層級的權限，
    # 應用程式用的帳號一定有，不必動用 root。
    #
    # 一定要先關掉 foreign_key_checks——資料表之間有外鍵，照任意順序
    # drop 一定會撞到「還被別的表參照」而失敗。
    tables=$(db_query "select table_name from information_schema.tables where table_schema = '$DB_DATABASE';")

    if [ -n "$tables" ]; then
        stmt="set foreign_key_checks = 0;"
        for t in $tables; do
            stmt="$stmt drop table if exists \`$t\`;"
        done
        stmt="$stmt set foreign_key_checks = 1;"
        db_query "$stmt" >/dev/null
    fi

    log "載入 $file"

    if ! gunzip -c "$path" | MYSQL_PWD="$DB_PASSWORD" mariadb \
            --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE"; then
        fail "還原失敗，資料庫目前可能處於不完整的狀態，請改用其他備份重試"
        return 1
    fi

    log "還原完成"
    log "提醒：如果這份備份的結構比目前的程式碼舊，請接著重啟 app 容器讓 migration 補上差異"
}

case "${1:-loop}" in
    once)
        require_env
        wait_for_db
        wait_for_table || true
        run_backup
        ;;

    verify)
        verify "${2:-48}"
        ;;

    mirror)
        [ -n "$MIRROR_DIR" ] || { echo "沒有設定 BACKUP_MIRROR_PATH" >&2; exit 1; }
        mirror_sync
        ;;

    mirror-prune)
        mirror_prune "${2:-}"
        ;;

    restore)
        require_env
        # 先解析檔名（不需要資料庫），再連線。打錯字不該等 60 秒才知道。
        resolved=$(resolve_backup_file "${2:-}") || exit $?
        wait_for_db
        restore "$resolved" "${3:-}"
        ;;

    list)
        echo "每日："
        find "$BACKUP_DIR/daily" -name '*.sql.gz' 2>/dev/null | sort | while read -r f; do
            printf '  %-44s %10s bytes  %s\n' "$(basename "$f")" "$(wc -c < "$f" | tr -d ' ')" "$(date -r "$f" '+%Y-%m-%d %H:%M')"
        done
        echo "每月："
        find "$BACKUP_DIR/monthly" -name '*.sql.gz' 2>/dev/null | sort | while read -r f; do
            printf '  %-44s %10s bytes  %s\n' "$(basename "$f")" "$(wc -c < "$f" | tr -d ' ')" "$(date -r "$f" '+%Y-%m-%d %H:%M')"
        done

        # 沒有壓縮的檔案要主動講出來。整套機制都只 glob *.sql.gz，所以一個
        # 被 gunzip 過的備份會同時從 list、restore、輪替、心跳、異地鏡像消失
        # ——而它就躺在目錄裡看得見，`ls` 看得到、系統卻說沒有，是最容易
        # 卡住的一種狀況（實際遇過）。
        stray=$(find "$BACKUP_DIR/daily" "$BACKUP_DIR/monthly" -name '*.sql' 2>/dev/null | sort)
        if [ -n "$stray" ]; then
            echo ""
            echo "⚠ 下面這些沒有壓縮，整套備份機制都看不到它們"
            echo "  （list／restore／輪替／心跳／異地鏡像都只認 *.sql.gz）："
            printf '%s\n' "$stray" | while read -r f; do
                printf '  %-44s %10s bytes  %s\n' "$(basename "$f")" "$(wc -c < "$f" | tr -d ' ')" "$(date -r "$f" '+%Y-%m-%d %H:%M')"
            done
            echo "  壓回去就會恢復正常：gzip <檔案路徑>"
        fi
        ;;

    loop)
        require_env
        log "備份服務啟動：每天 ${BACKUP_HOUR}:00，保留每日 ${KEEP_DAILY} 份、每月 ${KEEP_MONTHLY} 份，輸出到 ${BACKUP_DIR}"
        wait_for_db
        wait_for_table || true

        # 先把磁碟上已有、但資料庫裡沒有紀錄的備份補上心跳。這一步讓
        # 「上一次寫心跳時失敗」的狀態在重啟後自己修好，不需要人工介入。
        sync_heartbeats

        # 同理補一次鏡像：上次同步失敗（碟沒掛、目錄權限）之後，重新掛好
        # 再 restart 就會自己補齊，不必記得手動跑一次。
        mirror_sync || true

        # 啟動時如果今天還沒有備份就先跑一次。這樣「剛裝好」立刻看得到
        # 結果，不必等到隔天凌晨才知道設定對不對——而設定錯了卻要等
        # 一天才發現，正是備份最容易被忽略的失敗方式。
        if [ -z "$(find "$BACKUP_DIR/daily" -name "attendance-$(date '+%Y-%m-%d')-*.sql.gz" 2>/dev/null)" ]; then
            log "今天還沒有備份，先執行一次"
            run_backup || log "啟動時的備份失敗，將在下一個排程時間重試"
        fi

        while true; do
            wait_seconds=$(seconds_until_next_run)
            log "下一次備份在 ${wait_seconds} 秒後"
            sleep "$wait_seconds"
            run_backup || log "備份失敗，將在下一個排程時間重試"
        done
        ;;

    *)
        echo "用法: backup.sh [loop|once|list|verify [時數]|mirror|mirror-prune <天數>|restore <檔名> --confirm]" >&2
        exit 64
        ;;
esac
