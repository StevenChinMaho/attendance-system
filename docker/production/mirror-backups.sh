#!/bin/sh
#
# 把備份複製到第二顆實體硬碟。
#
# 為什麼這一支跑在主機上，而不是像 backup.sh 那樣做成容器：
# 目標是 WSL 掛載的實體硬碟（/mnt/wsl/...），而 /mnt/wsl 本身是 tmpfs、
# 掛載狀態隨時可能變（WSL 重啟後要重新 `wsl --mount`）。docker 的 bind
# mount 是在容器啟動時解析的，之後主機重新掛載，容器裡看到的仍然是舊的
# 那一份，會安靜地寫到錯誤的地方。主機端的腳本每次都看得到真實狀態。
#
# 用法（在專案目錄下執行）：
#   ./docker/production/mirror-backups.sh          同步一次
#   ./docker/production/mirror-backups.sh status   看目前狀態
#   ./docker/production/mirror-backups.sh init     第一次設定目標目錄
#   ./docker/production/mirror-backups.sh install  安裝每日 cron（冪等）
#   ./docker/production/mirror-backups.sh prune N  刪除鏡像端超過 N 天的備份
#
set -eu

ENV_FILE=".env.production"
# 目標目錄裡必須存在這個檔案才會同步。這是整支腳本最重要的一道防線，
# 理由見 verify_target()。
SENTINEL=".attendance-mirror-target"

[ -f "$ENV_FILE" ] || { echo "找不到 $ENV_FILE，請在專案根目錄執行" >&2; exit 1; }

read_env() {
    grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'
}

SOURCE=$(read_env BACKUP_PATH)
MIRROR=$(read_env BACKUP_MIRROR_PATH)

[ -n "$SOURCE" ] || { echo "$ENV_FILE 裡沒有 BACKUP_PATH" >&2; exit 1; }
[ -n "$MIRROR" ] || { echo "$ENV_FILE 裡沒有 BACKUP_MIRROR_PATH" >&2; exit 1; }

log() {
    echo "[mirror] $(date '+%Y-%m-%d %H:%M:%S') $*"
}

# ---------------------------------------------------------------------
# 目標檢查——這是這支腳本存在的主要理由
#
# WSL 用 `wsl --mount` 掛上的實體硬碟出現在 /mnt/wsl 底下，而 /mnt/wsl
# 本身是 tmpfs（也就是記憶體）。如果硬碟沒有掛上，那個路徑要嘛不存在，
# 要嘛只是 tmpfs 上一個普通的空目錄——rsync 會很開心地把備份寫進
# 記憶體裡：看起來一切正常、佔用 RAM、然後在下次重啟時全部消失。
#
# 所以「目錄存在」完全不足以當作判斷依據，這裡要三重確認：
#   1. 掛載點根目錄真的是一個 mount point
#   2. 它的檔案系統不是 tmpfs
#   3. 目標目錄裡有我們自己放的 sentinel 檔（證明是「那一顆」碟，
#      而不是剛好掛了別的東西上去）
# ---------------------------------------------------------------------
verify_target() {
    quiet="${1:-}"

    # 從 MIRROR 往上找到實際的掛載點
    mount_root="$MIRROR"
    while [ "$mount_root" != "/" ] && ! mountpoint -q "$mount_root" 2>/dev/null; do
        mount_root=$(dirname "$mount_root")
    done

    if [ "$mount_root" = "/" ]; then
        [ -n "$quiet" ] || echo "✗ $MIRROR 不在任何獨立的掛載點底下——目標硬碟可能沒有掛上"
        return 1
    fi

    fstype=$(df -T "$mount_root" 2>/dev/null | tail -1 | awk '{print $2}')

    if [ "$fstype" = "tmpfs" ] || [ "$fstype" = "ramfs" ]; then
        [ -n "$quiet" ] || echo "✗ $mount_root 是 $fstype（記憶體），不是實體硬碟——目標硬碟沒有掛上"
        return 1
    fi

    if [ ! -f "$MIRROR/$SENTINEL" ]; then
        [ -n "$quiet" ] || {
            echo "✗ $MIRROR/$SENTINEL 不存在"
            echo "  這個檔案是用來證明「目標硬碟確實掛著、而且是原本那一顆」。"
            echo "  第一次設定請執行：$0 init"
        }
        return 1
    fi

    # 來源與目標必須在不同的裝置上，否則整件事沒有意義。
    src_dev=$(stat -c %d "$SOURCE" 2>/dev/null || echo "?")
    dst_dev=$(stat -c %d "$MIRROR" 2>/dev/null || echo "??")

    if [ "$src_dev" = "$dst_dev" ]; then
        [ -n "$quiet" ] || echo "✗ $SOURCE 與 $MIRROR 在同一個裝置上，鏡像沒有意義"
        return 1
    fi

    return 0
}

case "${1:-once}" in
    init)
        echo "目標目錄：$MIRROR"

        if [ ! -d "$MIRROR" ]; then
            echo "建立目錄（需要 sudo，因為掛載點根目錄是 root 所有）"
            sudo mkdir -p "$MIRROR"
            sudo chown "$(id -u):$(id -g)" "$MIRROR"
            sudo chmod 700 "$MIRROR"
        fi

        # sentinel 只在硬碟真的掛著的時候寫得進去；硬碟沒掛的時候，
        # 這個檔案自然就不存在，同步就會被擋下來。
        {
            echo "這個檔案是 attendance-system 備份鏡像的目標標記。"
            echo "請不要刪除——mirror-backups.sh 靠它確認目標硬碟確實掛著。"
            echo "建立於 $(date '+%Y-%m-%d %H:%M:%S')"
        } > "$MIRROR/$SENTINEL"

        echo "✓ 完成。現在可以執行：$0 once"
        ;;

    status)
        echo "來源：$SOURCE"
        echo "鏡像：$MIRROR"
        echo ""

        if verify_target; then
            echo "✓ 目標硬碟已掛載且通過檢查"
            echo "  檔案系統：$(df -Th "$MIRROR" | tail -1 | awk '{print $1, $2, "可用", $5}')"
            echo "  來源檔案數：$(find "$SOURCE" -name '*.sql.gz' 2>/dev/null | wc -l | tr -d ' ')"
            echo "  鏡像檔案數：$(find "$MIRROR" -name '*.sql.gz' 2>/dev/null | wc -l | tr -d ' ')"

            if [ -f "$SOURCE/.last-mirror" ]; then
                last=$(cat "$SOURCE/.last-mirror")
                echo "  最後一次同步：$last"
            else
                echo "  最後一次同步：（還沒有同步過）"
            fi
        else
            echo ""
            echo "同步目前無法進行。硬碟沒掛上的話，在 Windows 端執行："
            echo "  wsl --mount \\\\.\\PHYSICALDRIVE2 --partition 2"
            exit 1
        fi
        ;;

    install)
        script_path=$(cd "$(dirname "$0")" && pwd)/$(basename "$0")
        project_dir=$(pwd)
        marker="# attendance-system backup mirror"

        # 排在備份之後：備份預設 03:00（容器已設 TZ=Asia/Taipei），
        # 這裡用 03:30 給它足夠的時間跑完。
        entry="30 3 * * * cd $project_dir && $script_path once >> $project_dir/storage/logs/mirror.log 2>&1 $marker"

        current=$(crontab -l 2>/dev/null | grep -v "$marker" || true)
        printf '%s\n%s\n' "$current" "$entry" | grep -v '^$' | crontab -

        echo "✓ 已安裝／更新 cron（每天 03:30）"
        echo "  目前的排程："
        crontab -l | grep "$marker" | sed 's/^/    /'
        echo ""
        echo "  注意：WSL 預設不會自動啟動 cron。確認一下："
        echo "    service cron status || sudo service cron start"
        ;;

    prune)
        days="${2:-}"
        [ -n "$days" ] || { echo "用法: $0 prune <天數>" >&2; exit 64; }
        verify_target || exit 1

        removed=$(find "$MIRROR" -name '*.sql.gz' -mtime "+$days" -print -delete | wc -l | tr -d ' ')
        log "刪除鏡像端超過 $days 天的備份共 $removed 份"
        ;;

    once)
        [ -d "$SOURCE" ] || { log "來源目錄不存在：$SOURCE"; exit 1; }

        if ! verify_target; then
            log "目標檢查沒有通過，這次不同步（詳見上方訊息）"
            exit 1
        fi

        # 刻意「不」加 --delete。
        #
        # 鏡像的目的是備援，不是做出一份一模一樣的副本。加了 --delete 的話，
        # 來源端不管是正常輪替、還是有人誤刪整個目錄，都會原封不動地同步過去
        # ——那正好把備份最該防的情境變成必然發生。代價是鏡像端會累積：
        # 以每天約 15MB 估算，一年不到 6GB，這顆碟有 227GB 可用，撐很多年
        # 都不成問題。真的需要清理時用 `prune <天數>`，那是一個明確的決定。
        rsync -a --info=stats2 "$SOURCE/" "$MIRROR/" 2>&1 | grep -E "Number of|Total transferred" | sed 's/^/  /'

        date '+%Y-%m-%d %H:%M:%S' > "$SOURCE/.last-mirror"

        log "同步完成 → $MIRROR"
        ;;

    *)
        echo "用法: mirror-backups.sh [once|status|init|install|prune <天數>]" >&2
        exit 64
        ;;
esac
