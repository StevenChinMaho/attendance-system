#!/bin/sh
#
# 比對 .env.production 與 .env.production.example，找出缺少的設定。
#
# 為什麼需要這支：.env.production 不進版本控制（裡面是機密），但
# .env.production.example 會隨著功能一起長出新變數。`git pull` 之後，
# 少掉的那些**大多不會報錯**——compose 只對少數用 ${VAR:?} 寫法的變數
# 會擋下來，其餘走 env_file 的設定一旦缺少，Laravel 就安靜地用
# config/*.php 裡的預設值。
#
# 症狀因此都是「功能看起來沒做出來」而不是「系統壞掉」，例如：
#   BACKUP_MONITOR_ENABLED 沒設 → 備份過期警告永遠不出現
#   SESSION_SECURE_COOKIE 沒設  → session cookie 少了 secure 旗標
#   LOG_CHANNEL 沒設            → 記錄寫進容器裡沒人輪替的檔案
#
# 用法（在專案目錄下執行）：
#   ./docker/production/env-check.sh          只檢查並回報
#   ./docker/production/env-check.sh --sync   把缺少的設定連同說明附加到檔案末端
#
set -eu

EXAMPLE=".env.production.example"
TARGET=".env.production"
MODE="${1:-check}"

[ -f "$EXAMPLE" ] || { echo "找不到 $EXAMPLE，請在專案根目錄執行" >&2; exit 1; }
[ -f "$TARGET" ] || { echo "找不到 $TARGET。第一次設定請先 cp $EXAMPLE $TARGET" >&2; exit 1; }

keys_of() {
    # 只取「行首就是變數名稱＝」的行，跳過註解與空行。
    grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$1" | cut -d= -f1 | sort -u
}

tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

keys_of "$EXAMPLE" > "$tmp_dir/example"
keys_of "$TARGET" > "$tmp_dir/target"

comm -23 "$tmp_dir/example" "$tmp_dir/target" > "$tmp_dir/missing"
comm -13 "$tmp_dir/example" "$tmp_dir/target" > "$tmp_dir/extra"

# 把某個變數在範本裡的「說明註解＋那一行」整段抓出來，讓 --sync 附加過去
# 的內容是看得懂的，而不是一堆沒有上下文的 KEY=VALUE。
block_for() {
    awk -v key="$1" '
        /^[[:space:]]*$/ { buf = ""; next }
        /^#/             { buf = buf $0 "\n"; next }
        {
            split($0, parts, "=")
            if (parts[1] == key) { printf "%s%s\n", buf, $0; exit }
            buf = ""
        }
    ' "$EXAMPLE"
}

missing_count=$(wc -l < "$tmp_dir/missing" | tr -d ' ')
extra_count=$(wc -l < "$tmp_dir/extra" | tr -d ' ')

# ---------------------------------------------------------------------
# 值看起來還沒填的：範本裡的機密欄位一律留空，所以「空值」幾乎都代表
# 忘了填。APP_URL 另外比對，因為它的範本值是一個看起來很正常的網址，
# 忘了改的話 trustHosts() 會把所有請求擋成 400。
# ---------------------------------------------------------------------
unfilled=""
for key in $(keys_of "$TARGET"); do
    value=$(grep -E "^${key}=" "$TARGET" | head -1 | cut -d= -f2-)
    case "$key" in
        APP_URL)
            example_value=$(grep -E '^APP_URL=' "$EXAMPLE" | head -1 | cut -d= -f2-)
            [ "$value" = "$example_value" ] && unfilled="$unfilled $key(仍是範本值)"
            ;;
        *)
            [ -z "$value" ] && unfilled="$unfilled $key(空值)"
            ;;
    esac
done

if [ "$MODE" = "--sync" ]; then
    if [ "$missing_count" -eq 0 ]; then
        echo "沒有缺少的設定，$TARGET 不需要更動。"
    else
        {
            echo ""
            echo "# ====================================================================="
            echo "# 以下由 env-check.sh --sync 於 $(date '+%Y-%m-%d %H:%M') 自動補上"
            echo "# 請逐一確認值是否正確（機密欄位在範本裡是空的，必須自己填）"
            echo "# ====================================================================="
        } >> "$TARGET"

        while read -r key; do
            [ -n "$key" ] || continue
            echo "" >> "$TARGET"
            block_for "$key" >> "$TARGET"
            echo "  已補上 $key"
        done < "$tmp_dir/missing"

        echo ""
        echo "已把 $missing_count 個缺少的設定連同說明附加到 $TARGET 末端。"
        echo "請打開檔案確認補上的值，尤其是空值的欄位。"
        echo "改完之後用 'up -d' 讓設定生效（restart 不會重讀 .env）。"
    fi
    exit 0
fi

# ---------------------------------------------------------------------
# 檢查模式
# ---------------------------------------------------------------------
status=0

if [ "$missing_count" -gt 0 ]; then
    echo "⚠ $TARGET 缺少 $missing_count 個設定："
    sed 's/^/    /' "$tmp_dir/missing"
    echo ""
    echo "  這些多半不會讓系統壞掉，只會安靜地用預設值——症狀通常是"
    echo "  「某個功能看起來沒做出來」。要自動補上（含說明註解）："
    echo "      ./docker/production/env-check.sh --sync"
    echo ""
    status=1
fi

if [ -n "$unfilled" ]; then
    echo "⚠ 以下設定看起來還沒填值："
    for item in $unfilled; do echo "    $item"; done
    echo ""
    status=1
fi

if [ "$extra_count" -gt 0 ]; then
    # 這個只是提醒，不算錯誤：可能是已經被移除的舊設定，也可能是刻意加的
    # 覆寫值。不自動刪除——誤刪一個還在用的設定，比留著一個沒用的糟得多。
    echo "ℹ $TARGET 有 $extra_count 個設定不在範本裡（可能是已移除的舊設定，也可能是你刻意加的）："
    sed 's/^/    /' "$tmp_dir/extra"
    echo ""
fi

if [ "$status" -eq 0 ]; then
    echo "✓ $TARGET 與範本一致，所有設定都有值。"
fi

exit "$status"
