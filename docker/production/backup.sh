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

    # 異地鏡像的狀態。標記檔由主機端的 mirror-backups.sh 寫在備份目錄裡
    # ——這個容器看得到那個目錄，所以「檢查備份」可以一次看完兩邊。
    # 沒有設定鏡像的話不提，避免對還沒做這件事的環境變成雜訊。
    if [ -f "$BACKUP_DIR/.last-mirror" ]; then
        mirror_age=$(( ( $(date '+%s') - $(date -r "$BACKUP_DIR/.last-mirror" '+%s') ) / 3600 ))
        echo "異地鏡像：最後同步於 $(cat "$BACKUP_DIR/.last-mirror")（${mirror_age} 小時前）"

        if [ "$mirror_age" -gt "$hours" ]; then
            echo "警告：異地鏡像已經超過 ${hours} 小時沒有更新，目標硬碟可能沒有掛上"
            return 1
        fi
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
restore() {
    file="${1:-}"
    confirm="${2:-}"

    [ -n "$file" ] || { echo "用法: backup.sh restore <檔名> --confirm" >&2; return 64; }

    # 只接受檔名，不接受路徑——避免打錯路徑指到別的地方。
    path="$BACKUP_DIR/daily/$file"
    [ -f "$path" ] || path="$BACKUP_DIR/monthly/$file"
    [ -f "$path" ] || { echo "找不到備份檔：$file" >&2; return 1; }

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

    restore)
        require_env
        wait_for_db
        restore "${2:-}" "${3:-}"
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
        ;;

    loop)
        require_env
        log "備份服務啟動：每天 ${BACKUP_HOUR}:00，保留每日 ${KEEP_DAILY} 份、每月 ${KEEP_MONTHLY} 份，輸出到 ${BACKUP_DIR}"
        wait_for_db
        wait_for_table || true

        # 先把磁碟上已有、但資料庫裡沒有紀錄的備份補上心跳。這一步讓
        # 「上一次寫心跳時失敗」的狀態在重啟後自己修好，不需要人工介入。
        sync_heartbeats

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
        echo "用法: backup.sh [loop|once|list|verify [時數]|restore <檔名> --confirm]" >&2
        exit 64
        ;;
esac
