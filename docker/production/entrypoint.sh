#!/bin/sh
#
# app 容器的進入點。每次容器啟動（含 `docker compose up -d` 之後的
# 每一次重啟）都會跑過一遍，而且必須是冪等的——重啟不是「重新部署」，
# 不能因為多跑一次就出事。
#
set -eu

echo "[entrypoint] 等待資料庫 ${DB_HOST}:${DB_PORT} ..."

# compose 那邊已經用 depends_on: condition: service_healthy 等過 mariadb 的
# healthcheck，這裡是第二道保險：healthcheck 通過到真的能接受連線之間
# 仍有短暫空窗，而且 mariadb 容器單獨重啟時 depends_on 不會重新生效。
i=0
until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT"), $e, $s, 2) ? 0 : 1);'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] 資料庫在 60 秒內沒有回應，放棄啟動。" >&2
        exit 1
    fi
    sleep 1
done

echo "[entrypoint] 套用資料庫 migration ..."
# --force：正式環境的 migrate 預設會互動詢問，容器裡沒有人可以回答。
# 放在啟動流程裡而不是手動步驟，是為了保證「程式碼與資料庫結構永遠
# 同時生效」——不會出現映像更新了、但有人忘記跑 migration 的狀態。
php artisan migrate --force

echo "[entrypoint] 同步角色與權限 ..."
# 權限是「資料」不是程式碼——spatie 把它們存在 permissions/roles 資料表
# 裡。新版程式碼加了一個權限（例如 audit.view）卻沒有人重跑這個 seeder
# 的話，那個權限在資料庫裡根本不存在：對應頁面對所有人 403、身分管理
# 也列不出來，而且完全沒有錯誤訊息，只會看起來像「功能沒做出來」。
# （實際踩過一次。）
#
# 所以跟 migration 同樣的道理：跟著啟動流程自動跑，保證程式碼與它需要
# 的資料永遠同時生效，不依賴任何人記得多下一道指令。
#
# 這個 seeder 必須永遠保持冪等：它只用 firstOrCreate 建立權限與三個
# 內建角色，並對那三個角色 syncPermissions。三個內建角色的權限在
# RoleManager 裡是鎖住不能改的（PROTECTED_ROLE_NAMES），所以同步回去
# 不會蓋掉任何人的自訂設定；自訂角色則完全不受影響。
# 若之後修改這個 seeder，務必維持這個性質——見 DEPLOYMENT.md 第 6 節。
php artisan db:seed --class=RolePermissionSeeder --force

echo "[entrypoint] 重建設定快取 ..."
# 這三個 cache 刻意在「啟動時」做，不是在 Dockerfile 建置時做：
#   config:cache 會把當下的環境變數值固化進 bootstrap/cache/config.php。
#   若在建置階段做，.env 就等於被烤進映像，之後改 .env 不重建映像
#   就不會生效——那是很難察覺的一類故障。
#
# 但要注意：改完 .env.production 之後要用 `up -d`，不能用 `restart`。
# restart 是把「同一個容器」停掉再開，容器建立當下注入的環境變數
# 不會重新讀取，於是這裡的 config:cache 只是把舊值再快取一次，
# 看起來有跑、實際沒有生效（實測踩過：改了 SESSION_COOKIE 之後
# restart 沒有任何變化，up -d 才會重建容器並套用）。
#
# 反過來說，config:cache 之後 env() 只在 config/*.php 裡有效，
# 應用程式碼裡不能直接呼叫 env()（本專案沒有這種用法）。
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] 啟動 $*"
exec "$@"
