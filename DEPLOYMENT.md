# 正式環境部署與維運

這份文件涵蓋：在一台全新的 Ubuntu server 上從零把系統跑起來、日常怎麼管理、
怎麼套用更新，以及**怎麼寫更新才能保證在正式環境正確生效**。

**第一次部署請照[第 3 節](#3-從零建置)從 3.1 依序做到 3.9**，做完就是一套含自動備份
與異地鏡像的完整環境；第 4 節之後是日常維運與參考資料。

開發環境（Laravel Sail）的操作不在這裡，見 [CLAUDE.md](CLAUDE.md)。
兩套環境完全獨立，不共用任何檔案：Sail 用 `compose.yaml`，正式環境用
`compose.production.yaml`，映像也是分開建的。

---

## 1. 架構總覽

```
      網際網路
         │  https（憑證由 Cloudflare 提供）
         ▼
  ┌─────────────────┐
  │ Cloudflare edge │
  └────────┬────────┘
           │  ← 這條連線是 cloudflared 主動「往外」建立的
           ▼
  ╔══════════════════════ Ubuntu server ══════════════════════╗
  ║  ┌──────────────┐                                         ║
  ║  │ cloudflared  │                                         ║
  ║  └──────┬───────┘        docker network: internal         ║
  ║         ▼                                                 ║
  ║  ┌──────────────┐  fastcgi  ┌──────────┐   ┌───────────┐  ║
  ║  │ web (nginx)  │──────────▶│ app      │──▶│ mariadb   │  ║
  ║  │ 靜態檔直接吐 │  :9000    │ php-fpm  │   │ (volume)  │  ║
  ║  └──────────────┘           └──────────┘   └─────▲─────┘  ║
  ║                                                  │        ║
  ║                              ┌──────────┐   每日 dump     ║
  ║   主機目錄 ◀────────────────│ backup   │────────┘        ║
  ║   ${BACKUP_PATH}             └──────────┘                 ║
  ║                                                           ║
  ║  對 host 發佈的 port：完全沒有。                          ║
  ╚═══════════════════════════════════════════════════════════╝
```

**沒有任何容器對 host 發佈 port**，這是這套部署最重要的性質。對外流量的唯一入口
是 cloudflared 主動建立的 outbound tunnel，所以從網際網路（甚至從校內網路）掃這台
主機，看不到任何開放的服務；`ufw` 不需要為這個系統開任何一條規則。

這也是 [bootstrap/app.php](bootstrap/app.php) 裡 `trustProxies(at: '*')` 之所以安全的
前提：能把請求送進 app 容器的，只有上圖那個內部網路裡的 web 與 cloudflared。

### 容器一覽

| 服務 | 映像 | 狀態 | 說明 |
|---|---|---|---|
| `app` | 自建（`target: runtime`）約 235MB | 無狀態 | php-fpm 8.5。程式碼與 vendor 為 root 所有、對 www-data 唯讀 |
| `web` | 自建（`target: web`）約 93MB | 無狀態 | nginx。`public/` 靜態檔烤在映像裡 |
| `mariadb` | `mariadb:12.3` | **有狀態** | 資料在 named volume `db-data` |
| `backup` | `mariadb:12.3`（沿用，不另外建映像） | 無狀態 | 每日 dump 到 `BACKUP_PATH` 指定的**主機**目錄 |
| `cloudflared` | `cloudflare/cloudflared:2026.8.2` | 無狀態 | 版本刻意釘死，不用 `:latest` |

**整個系統唯一有狀態的地方是 `db-data` 這個 volume。** 其餘容器都可以隨時砍掉重建，
這也是備份只需要顧一個對象的原因。

### 不需要的東西

- **不需要 Redis**：session／cache／queue 全部用 `database` driver，對應資料表都在 migration 裡。
- **不需要 queue worker、不需要 Laravel 排程容器**：全專案沒有任何 `dispatch()`／
  `ShouldQueue`／Mail／Notification，`routes/console.php` 也沒有排程。
  （`backup` 服務有自己的計時迴圈，跟 Laravel 的 scheduler 無關。）
- **不需要 SMTP**：沒有註冊流程也沒有密碼重設信，帳號一律由管理者建立與重設。
- **不需要在 nginx 設定 TLS**：憑證由 Cloudflare edge 處理。

---

## 2. 前置需求

在伺服器上：

- Docker Engine + Compose plugin（`docker compose version` 要能跑）
- `git`、`rsync`
- 這個系統本身不需要對外開任何防火牆 port
- **第二顆實體硬碟（強烈建議）**：備份跟資料庫放在同一顆碟上只防誤刪、不防硬體
  故障。有第二顆碟才有真正的第二份，見 [3.8](#38-設定異地備份強烈建議)。

在 Cloudflare：

- 一個已經指向 Cloudflare 的網域
- 在 **Zero Trust → Networks → Tunnels** 建立一個 tunnel，取得 **tunnel token**
- 該 tunnel 的 **Public hostname** 設定：
  - Subdomain / Domain：你要用的網址（例如 `attendance.example.com`）
  - Service：`HTTP` → `web:80`
    （`web` 是 compose 裡的服務名稱，cloudflared 跟它在同一個 docker network 裡）

---

## 3. 從零建置

### 3.1 建立部署帳號並取得程式碼（不要用 root 操作）

**這套部署沒有任何一個環節需要 root。** 值得先花兩分鐘設定好，否則整個維運
過程都會泡在 root shell 裡：

- 沒有任何容器對 host 發佈 port，所以不需要綁定 1024 以下的特權埠
  （tunnel 架構順帶帶來的好處）。
- `compose.production.yaml` 沒有任何 host bind mount，只有一個 named volume，
  所以不會有 host 端檔案權限／UID 對應的問題。
- 唯一需要的特權是存取 docker socket，加入 `docker` 群組就有了。

```bash
# 用既有的帳號也可以，這裡示範建一個專用的
sudo adduser --disabled-password --gecos '' deploy
sudo usermod -aG docker deploy
```

```bash
# 專案目錄歸這個帳號所有，之後 git pull / 編輯 .env 都不需要 sudo
sudo mkdir -p /opt/attendance-system
sudo chown deploy:deploy /opt/attendance-system
```

之後一律以 `deploy` 登入操作（`su - deploy` 或直接用它 ssh 進來）：

```bash
git clone <這個 repo 的網址> /opt/attendance-system
cd /opt/attendance-system
```

（往後所有指令都在 `/opt/attendance-system` 底下、以 `deploy` 身分執行。）

> **要誠實說清楚 `docker` 群組的界線**：能存取 docker socket 的帳號，實際上
> 可以把 host 的根目錄掛進容器裡拿到 root，所以「加入 docker 群組」在
> **抵禦刻意攻擊**這件事上跟 root 差不多，這是 Docker 官方自己也載明的。
> 它真正買到的是另外兩件事，而這兩件事才是日常維運會遇到的：**打錯的指令
> 不會用 root 的權限執行**（一個手滑的 `rm -rf` 只會炸掉這個帳號摸得到的
> 東西，不是整台機器），以及**誰做了什麼有跡可循**。要更嚴格的隔離就得上
> rootless Docker，但那會多出一整套網路與儲存的設定要處理，對一台只跑這個
> 系統的校內主機而言不成比例——除非你有明確的稽核要求。

### 3.2 建立 `.env.production`

```bash
cp .env.production.example .env.production
chmod 600 .env.production
```

編輯它，把這幾個空值填上：

| 變數 | 怎麼產生 |
|---|---|
| `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` |
| `DB_PASSWORD` | `openssl rand -base64 32` |
| `DB_ROOT_PASSWORD` | `openssl rand -base64 32`（跟上面不同的一組） |
| `TUNNEL_TOKEN` | Cloudflare 儀表板上那串 |
| `APP_URL` | 你的正式網址，**必須是 `https://`、結尾不要有斜線** |
| `BACKUP_UID` / `BACKUP_GID` | 用 `id -u` / `id -g` 查部署帳號的值 |

`APP_URL` 填錯會讓**所有**請求被擋成 400：`trustHosts()` 只信任這個網址的主機名。

備份相關的其餘變數（`BACKUP_PATH`、`TZ`、`BACKUP_HOUR`、保留份數、過期警告）都有
合理的預設值，範本裡也已經填好，照著用即可；要改的話每一項在範本裡都有說明。
異地鏡像的 `BACKUP_MIRROR_PATH` 留到 [3.8](#38-設定異地備份強烈建議) 再填。

`.env.production` 已經在 `.gitignore` 與 `.dockerignore` 裡，不會進版本控制，也不會被
烤進映像——設定值是容器啟動時由 compose 注入的。

填完之後檢查一次有沒有漏掉或忘了填值的：

```bash
./docker/production/env-check.sh
```

往後每次 `git pull` 之後也要跑這一步，理由見[第 5.1 節](#51-功能性更新改了程式碼)。

### 3.3 設定指令捷徑（強烈建議）

正式環境的每個 compose 指令都必須帶兩個旗標，漏掉任何一個都會出事，所以先包成函式：

```bash
# 加到 deploy 帳號的 ~/.bashrc（不是 root 的——見 3.1）
attendance() {
    (cd /opt/attendance-system && \
     docker compose --env-file .env.production -f compose.production.yaml "$@")
}
```

```bash
source ~/.bashrc
```

兩個旗標都不能省：

- `-f compose.production.yaml`：不指定的話會跑到開發用的 `compose.yaml`。
- `--env-file .env.production`：`compose.production.yaml` 裡 mariadb／cloudflared 區塊
  的 `${...}` 是 **compose 自己的變數插值**，它預設只讀專案目錄下的 `.env`，不會去讀
  各服務的 `env_file:`。少了這個旗標那些變數會是空的——不過 compose 會直接報錯停下來
  （`:?` 語法），不會用空密碼把資料庫跑起來。

以下都用 `attendance` 這個捷徑。

### 3.4 建立備份目錄

**這一步要在啟動之前做。** `backup` 服務會把 `BACKUP_PATH` 指定的主機目錄掛進容器，
目錄不存在的話 docker 會自動建立，但擁有者會是 root——備份檔之後就變成 root 所有，
複製到異地時會卡權限。

```bash
mkdir -p /opt/attendance-backups
chmod 700 /opt/attendance-backups          # 裡面有全校學生資料與密碼雜湊
id -u; id -g                               # 確認跟 .env.production 的 BACKUP_UID/GID 一致
```

備份的運作方式、保留策略與還原程序見[第 9 節](#9-備份與還原)，這裡只需要把目錄準備好。

### 3.5 建置並啟動

```bash
attendance up -d --build
```

第一次會花幾分鐘（要編前端資產、裝 PHP 依賴）。啟動順序由 compose 自己處理：
mariadb 健康後才起 app，web 健康後才起 cloudflared。

確認狀態：

```bash
attendance ps
```

五個服務都應該是 `Up`，`mariadb` 與 `web` 應該是 `(healthy)`。

> app 容器啟動時，[entrypoint](docker/production/entrypoint.sh) 會自動等資料庫、跑
> `migrate --force`、同步角色與權限、重建 config／route／view cache。不需要手動做這些。

`backup` 容器啟動時如果當天還沒有備份就會先跑一次，所以裝好立刻看得到結果：

```bash
attendance logs backup
```

### 3.6 確認角色與權限

**這一步是自動的**，不需要手動執行。app 容器每次啟動時，
[entrypoint](docker/production/entrypoint.sh) 都會跑
`db:seed --class=RolePermissionSeeder`，建立內建身分（admin／homeroom_teacher／
student_rep）與所有權限。這個 seeder **不會建立任何帳號**。

之所以自動化，是因為權限是**資料**而不是程式碼（spatie 存在 `permissions` 資料表
裡）。新版程式碼加了一個權限卻沒有人重跑 seeder 的話，那個權限在資料庫裡根本不
存在——對應頁面對所有人 403、身分管理也列不出來，而且完全沒有錯誤訊息，只會看
起來像「功能沒做出來」。這種只能靠人記得的步驟遲早會被漏掉，所以綁進啟動流程。

要確認的話：

```bash
attendance exec app php artisan tinker --execute='echo Spatie\Permission\Models\Permission::count();'
```

### 3.7 建立第一個管理者帳號

```bash
attendance exec app php artisan admin:create
```

會互動詢問帳號、姓名、密碼（密碼輸入時不顯示）。建立出來的帳號帶
`must_change_password`，本人首次登入時會被強制導去變更密碼。

**密碼不接受用參數傳入**，那會留在 shell history 跟 `ps` 的輸出裡。

### 3.8 設定異地備份（強烈建議）

到這裡備份已經在跑了，但它跟資料庫在同一顆硬碟上——**只防誤刪，不防硬體故障**。
要真正安全，備份必須複製到另一顆實體硬碟。

在 `.env.production` 填上目標，然後：

```bash
./docker/production/mirror-backups.sh init      # 建目錄與標記檔（會用到 sudo）
./docker/production/mirror-backups.sh install   # 安裝每日 03:30 的 cron
./docker/production/mirror-backups.sh status    # 確認
```

沒有第二顆硬碟的話，`BACKUP_MIRROR_PATH` 留空即可，這一步跳過——但請把它記成待辦，
別當作已經完成。細節與 WSL 特有的陷阱見[第 9 節](#異地備份複製到第二顆實體硬碟)。

### 3.9 驗收

打開 `https://你的網址`，應該看到登入頁。用剛建立的帳號登入 → 會被導去變更密碼 →
改完之後進入即時看板。

確認備份也真的在運作：

```bash
attendance exec backup /scripts/backup.sh list           # 應該至少有一份
attendance exec backup /scripts/backup.sh verify         # 檢查檔案完整、夠新，並回報鏡像狀態
./docker/production/mirror-backups.sh status    # 有設定異地的話
```

**最後做一次還原演練。** 沒有還原過的備份不算備份，而演練可以在同一台機器上安全
進行（見[第 9 節的還原演練](#還原演練)）——上線前做一次，之後每學期一次。

接著照 [第 8 節的上線前檢查清單](#8-上線前資安檢查清單)跑一遍。

---

## 4. 日常管理

```bash
attendance ps                     # 看四個容器的狀態
attendance logs -f app            # 追蹤應用程式記錄（PHP 錯誤都在這）
attendance logs -f web            # nginx 存取／錯誤記錄
attendance logs --since 1h        # 全部服務，最近一小時
attendance restart app            # 只重啟應用程式（見下方警告）
attendance down                   # 停掉全部（資料保留在 volume）
attendance up -d                  # 起回來
```

> **`restart` 不會重讀 `.env.production`。** 它是把同一個容器停掉再開，容器建立當下
> 注入的環境變數不會重新讀取。改完設定檔一定要用 `attendance up -d`（會偵測到設定
> 變更並重建容器）。這點實測踩過：改了 `SESSION_COOKIE` 之後 `restart` 完全沒有效果。

進容器操作：

```bash
attendance exec app php artisan tinker
attendance exec app php artisan migrate:status
attendance exec app sh
```

備份（完整說明見[第 9 節](#9-備份與還原)）：

```bash
attendance logs backup                          # 備份記錄
attendance exec backup /scripts/backup.sh list           # 列出所有備份檔
attendance exec backup /scripts/backup.sh verify         # 檢查最新備份，並回報異地鏡像狀態
./docker/production/mirror-backups.sh status    # 異地鏡像的詳細狀態
```

管理資料庫：

```bash
# 用應用程式的帳號進 SQL client
attendance exec mariadb mariadb -u attendance -p attendance_system
```

**帳號相關的日常操作（新增老師帳號、重設密碼、停用帳號、調整身分）一律走網頁後台
`/admin/users`，不要下 SQL。** `artisan admin:create` 只是用來生出「第一個」管理者的
引導程序，之後就不需要它了。

### 危險指令對照

| 指令 | 後果 |
|---|---|
| `attendance down` | 安全。只停容器，資料留在 volume |
| `attendance down -v` | **會刪掉資料庫 volume，全校點名紀錄全滅**。除非你真的要重來，否則永遠不要打 `-v`。（備份檔在主機目錄上，不會被一起刪掉——這正是 `BACKUP_PATH` 不能用 docker volume 的原因） |
| `attendance up -d --build` | 安全。重建映像並套用 |
| `docker system prune -a` | 會刪掉沒在用的映像（含你的回滾目標），volume 不受影響。要用請加 `--volumes` 以外的形式 |

### ⚠ 這個 compose 檔在一台主機上只認一組容器，跟你在哪個目錄執行無關

`compose.production.yaml` 最上面寫死了 `name: attendance-system-production`。
**Docker Compose 是用這個名稱識別專案的，不是用目錄。** 也就是說，同一台主機上
不管從哪個目錄、用哪個使用者身分執行這個檔案，操作到的都是**同一組容器與同一個
資料庫 volume**。

這條規則造成過一次真實的資料損失：開發者在同一台機器上，從自己的專案目錄跑了
一輪冒煙測試（`up -d --build` 之後 `down -v`），結果操作到的其實是 `/opt` 底下
那套正在運作的堆疊——資料庫 volume 被 `-v` 直接刪掉，帳號、班級、學生、點名
紀錄與稽核紀錄全部消失，而且當時還沒有備份。

所以：

- **不要在已經跑著這套系統的主機上，用同一個 compose 檔做任何測試。**
- 真的需要在同一台機器上試，一定要指定一個丟棄式的專案名稱，讓它跟正式那組
  完全分開：

  ```bash
  docker compose -p attendance-smoke-test \
      --env-file .env.smoke -f compose.production.yaml up -d --build
  ```

  `-p` 的優先權高於檔案裡的 `name:`，容器與 volume 都會另外開一組，
  清理時 `docker compose -p attendance-smoke-test down -v` 也只會動到那一組。
- **`down -v` 請當成「我確定要清空資料庫」的專用指令。** 少了 `-v` 就只是停掉
  容器、資料完好；加了 `-v` 沒有任何確認、沒有復原機會，Docker 也不留副本。

反過來的方向也要注意：**不要拿掉 `compose.production.yaml` 的 `name:`**。拿掉的話
compose 會改用目錄名稱當專案名，在開發機上就會跟 Sail 的 `attendance-system`
撞在一起，正式環境的設定會把 Sail 的 mariadb 容器接管重建（也實測踩過）。

---

## 5. 套用更新

### 5.1 功能性更新（改了程式碼）

在開發機上完成、測試過、推上 `main` 之後，在伺服器上：

```bash
cd /opt/attendance-system

# 1. 先記下目前的版本，回滾時要用
git rev-parse --short HEAD

# 2. 取得新版程式碼
git pull

# 3. 檢查有沒有新增的設定要補（見下方說明，這一步很容易被忽略）
./docker/production/env-check.sh

# 4. 重建映像並套用
attendance up -d --build

# 5. 確認
attendance ps
attendance logs --tail 50 app
```

**第 3 步不能省。** `.env.production` 不進版本控制（裡面是機密），但
`.env.production.example` 會隨著新功能長出新變數——而缺少的那些**大多不會報錯**：
compose 只對少數用 `${VAR:?}` 寫法的變數會直接擋下來，其餘走 `env_file` 的設定一旦
缺少，Laravel 就安靜地用 `config/*.php` 裡的預設值。症狀因此都是「某個功能看起來
沒做出來」而不是「系統壞掉」，例如 `BACKUP_MONITOR_ENABLED` 沒設，備份過期警告就
永遠不會出現。

`env-check.sh` 會列出缺少的設定、值還沒填的設定（空值、或 `APP_URL` 仍是範本值），
以及範本裡已經沒有的舊設定。要自動補上的話：

```bash
./docker/production/env-check.sh --sync
```

它會把缺少的設定**連同範本裡的說明註解**附加到 `.env.production` 末端，你只要逐一
確認值即可（機密欄位在範本裡是空的，必須自己填）。它只新增、不修改也不刪除既有的
行——誤刪一個還在用的設定，比留著一個沒用的糟得多。

`up -d --build` 會重建映像、重建有變動的容器。app 容器啟動時 entrypoint 會自動跑
`migrate --force`、同步角色與權限（`RolePermissionSeeder`）並重建所有 cache，
**不需要手動跑 migration、seeder 或 `optimize`**。

資料庫 volume 不受影響，資料完整保留（已實測驗證）。

> 更新期間會有數十秒的短暫中斷（容器重建 + entrypoint 跑 migration/cache）。
> 這個系統的使用尖峰是早自習 7:30–7:45，更新請避開早上時段。

### 5.2 基礎映像更新（PHP／nginx／MariaDB／cloudflared 的安全性更新）

- **PHP 與 nginx**：標籤是 `php:8.5-fpm-alpine` 與 `nginx:stable-alpine`，會在修補版本
  內滾動。要拉最新的修補版：

  ```bash
  attendance build --pull app web
  attendance up -d
  ```

  （`--pull` 會強制重新拉基礎映像；沒有它 docker 會沿用本機已有的那份。）

- **MariaDB**：改 `compose.production.yaml` 裡的 `image: mariadb:12.3` 標籤，然後
  `attendance up -d`。`MARIADB_AUTO_UPGRADE=1` 已經設好，跨版本升級時會自動跑
  `mariadb-upgrade`。**升級資料庫前務必先手動備份**（見第 9 節）。

- **cloudflared**：版本刻意釘死在 `compose.production.yaml` 裡。要升級就改那一行的
  版本號再 `attendance up -d`。不要改成 `:latest`——它是整個站台的對外入口，某次
  `pull` 撈到破壞性變更就是全站無預警中斷，而且事後很難判斷是什麼變了。

### 5.3 回滾

映像是從 git commit 建出來的，所以回滾就是「切回舊 commit 再重建」：

```bash
git checkout <舊的 commit hash>
attendance up -d --build
```

**但要注意：程式碼可以回滾，資料庫 migration 不會自動回滾。** 如果那次更新含有
migration，回到舊程式碼會面對一個「比它新」的 schema。這通常沒事（多一個欄位不會讓
舊程式碼壞掉），但如果 migration 是破壞性的（改欄位型別、刪欄位），就必須先從備份
還原資料庫。這也是下一節的重點。

---

## 6. 怎麼寫更新，才能保證在正式環境正確生效

這一節是給「在開發機上寫功能的人」看的。以下每一條都對應正式環境跟開發環境的一個
實際差異——在 Sail 底下完全正常、上了正式環境才壞的那種問題。

### 6.1 Migration 必須是「可以套用在有資料的資料庫上」的

正式環境的 migration 是 entrypoint 自動跑的，沒有人在旁邊看。所以：

- **絕對不要在正式環境跑 `migrate:fresh` 或 `migrate:refresh`。** entrypoint 只跑
  `migrate --force`，這是刻意的。
- 新增欄位要嘛 `nullable()`，要嘛給 `default()`。既有資料列沒辦法回答一個
  「非空又沒有預設值」的新欄位。
- 加 unique 索引之前，先確認既有資料不會違反它。開發環境的資料庫是空的或只有
  seeder 資料，正式環境有一整所學校的真實資料——本專案的
  `make_student_number_unique_on_students_table` 就是這種需要事前確認的 migration。
- 破壞性變更（改型別、刪欄位、改名）拆成多次部署：先加新欄位並雙寫 → 部署 →
  搬資料 → 部署 → 再刪舊欄位。一次做完的話，回滾就等於資料遺失。

### 6.2 應用程式碼裡不能呼叫 `env()`

正式環境的 entrypoint 會跑 `config:cache`。**一旦 config 被快取，`env()` 在
`config/*.php` 以外的地方一律回傳 `null`。**

要讀設定值一律透過 `config('xxx.yyy')`；需要新的環境變數就在 `config/` 底下開一個
對應的 key。本專案目前沒有違規的用法，請維持。

### 6.3 新增權限一定要加進 `RolePermissionSeeder`

權限是資料庫裡的資料，不是程式碼。只在路由上寫 `can:something.manage` 而沒有把
`something.manage` 加進 `database/seeders/RolePermissionSeeder.php` 的權限清單，
結果是那個權限在資料庫裡不存在：**頁面對所有人 403（包含管理者），身分管理也列
不出這個選項可以勾**，而且沒有任何錯誤訊息。

同時記得補上 `RoleManager::PERMISSION_LABELS` 的中文標籤，否則身分管理的表頭會
顯示英文原始字串（有測試守住這一點）。

**這個 seeder 會在每次容器啟動時執行，所以必須永遠保持冪等**：只能用
`firstOrCreate`，不能建立帳號，不能刪除任何東西。目前它對三個內建角色做
`syncPermissions`，這是安全的——那三個角色的權限在 `RoleManager` 裡本來就鎖住
不能改（`PROTECTED_ROLE_NAMES`），所以不會蓋掉任何人的自訂；自訂角色完全不受影響。
如果之後修改這個 seeder，務必維持這個性質。

> 注意本機開發環境**不會**自動跑（Sail 沒有這段 entrypoint），所以在開發機上加了
> 新權限之後要自己跑一次，否則畫面上會看不到：
> `./vendor/bin/sail artisan db:seed --class=RolePermissionSeeder`

### 6.4 新增設定值要同步更新 `.env.production.example`

`.env.production` 是伺服器上手工維護的檔案，不會因為 `git pull` 自動長出新的變數。
新增了必填設定卻沒更新範本，部署的人不會知道要補，而症狀通常是某個功能靜默地用了
預設值。**在 `.env.production.example` 加上該變數並寫清楚怎麼取得。**

### 6.5 前端資產是在建置階段編的

`public/build` 在 `.gitignore` 裡，正式環境沒有 node。Vite 的產出是在映像的 `assets`
階段跑 `npm run build` 產生的，所以：

- 改了 `resources/css`／`resources/js`／任何 Blade 的 class，都必須重建映像
  （`up -d --build`）才會生效，`git pull` 加 `restart` 是不夠的。
- 改了 `package.json` 或 `vite.config.js` 同理。
- Tailwind v4 靠掃描原始碼決定產出哪些 utility，而 Dockerfile 的 `assets` 階段只複製
  `resources/`（已確認這個專案的 class 只出現在那裡）。**如果之後在 `app/` 底下的 PHP
  檔裡寫了 Tailwind class 字串**（例如在 Livewire 元件裡組 class name），那些 class 不會
  被編進 CSS，畫面在正式環境會少樣式而開發環境正常——屆時要把 `app/` 也加進
  [Dockerfile](docker/production/Dockerfile) 的 assets 階段。

### 6.6 容器裡的檔案系統對 PHP 是唯讀的

程式碼與 `vendor/` 都是 root 所有，php-fpm 以 `www-data` 執行。**唯二可寫的是
`storage/` 與 `bootstrap/cache/`。**

任何需要寫入專案目錄的新功能（產生檔案、寫快取檔到非標準位置）在正式環境會失敗。
要寫檔請寫到 `storage/` 底下。

另外 `storage/` 沒有掛 volume，**容器重建就會清空**。目前這樣是對的（記錄走 stderr、
沒有需要長期保存的上傳檔）。如果之後要加「上傳並保存檔案」的功能，就必須在
`compose.production.yaml` 補一個 volume，否則每次更新都會把使用者上傳的東西弄丟。

### 6.7 新增背景工作或排程要一起補容器

目前沒有 queue worker 也沒有 Laravel 排程容器，因為專案裡一個都沒用到
（`backup` 服務跑的是自己的 shell 計時迴圈，不經過 Laravel 的 scheduler）。如果之後加了
`ShouldQueue` 的 job 或 `routes/console.php` 的排程，**它們在正式環境不會被執行**——
不會報錯，就只是永遠不動。屆時要在 `compose.production.yaml` 加對應的服務
（`php artisan queue:work` / `php artisan schedule:work`）。

### 6.8 時間相關的邏輯

`config/app.php` 的 `timezone` 是寫死的 `Asia/Taipei`，不吃環境變數，正式環境不需要
額外設定。點名日期（`now()->toDateString()`）與點名時段限制
（`AttendanceWindow` 的 07:00–17:00）都依賴這個值——不要把它改成讀 env，否則正式
環境漏設就會整個差 8 小時，而且是那種「早上看起來正常、下班後才發現」的錯。

### 6.9 上線前一定要跑過的

```bash
./vendor/bin/sail artisan test     # 全綠
./vendor/bin/sail pint             # 過
./vendor/bin/sail npm run build    # 能編成功（正式環境會做同樣的事）
```

第三項特別重要：Tailwind v4 的 `@apply` 錯誤只有在建置時才會浮現，而正式環境的建置
失敗就是整個部署失敗。

---

## 7. 疑難排解

以下每一項都是實際踩過的。

**`attendance ps` 顯示 web 一直 `health: starting` 或 `unhealthy`**

健康檢查是 `wget --header="Host: ..." http://127.0.0.1/up`。先手動跑一次看真正的錯誤：

```bash
attendance exec web wget -qS -O- --header="Host: attendance.example.com" http://127.0.0.1/up
```

- 回 **400 Bad Request**：`APP_URL` 跟你送的 Host 不一致，`trustHosts()` 擋掉了。
  檢查 `.env.production` 的 `APP_URL`（要 `https://`、結尾不要有斜線），改完用
  `attendance up -d`（不是 `restart`）。
- **connection refused**：nginx 沒起來，看 `attendance logs web`。
  （注意：健康檢查必須用 `127.0.0.1` 不能用 `localhost`——nginx 只監聽 IPv4，
  而 busybox 的 wget 會先試 `::1`。這已經寫死在 compose 裡了，不要改回 `localhost`。）

**app 容器不斷重啟**

```bash
attendance logs app
```

- `FPM initialization failed` / `unknown entry 'xxx'`：`docker/production/php-fpm.conf`
  裡放了全域指令。pool 區塊 `[www]` 只能放 pool 指令（例如 `error_log` 就是全域的，
  不能放進去）。
- `SQLSTATE[HY000] [1045] Access denied`：`.env.production` 的 `DB_PASSWORD` 跟資料庫
  volume 裡已經建立的密碼不一致。MariaDB 只在**第一次建立空 volume 時**套用密碼環境
  變數，之後改 `.env` 不會改到資料庫裡的密碼。要嘛改回原密碼，要嘛進資料庫改密碼。
- `Please provide a valid cache path`：`storage/framework/views` 不存在。正常情況不會
  發生（Dockerfile 會建），如果動過 `.dockerignore` 的 storage 相關規則請對照檢查。

**網站可以開，但完全沒有樣式**

`public/build` 沒有正確產生或沒有被複製。確認你用的是 `up -d --build` 而不是
`up -d`，並看 build 過程中 `assets` 階段有沒有失敗。

**登入之後一直被導回登入頁**

session 寫不進去或 cookie 沒被瀏覽器接受。檢查：

- `SESSION_SECURE_COOKIE=true` 而實際是用 `http://` 連進來（例如直接連 IP 而不是走
  Cloudflare）。cookie 帶 `secure` 旗標，瀏覽器不會在 http 上送出。
- `APP_KEY` 被改過。換 `APP_KEY` 會讓所有既有 session cookie 解不開。

**改了 `.env.production` 卻沒有任何效果**

用了 `restart`。要用 `attendance up -d`。見第 4 節的警告。

**`restart` 某個容器時報 `failed to create shim task ... no such file or directory`**

發生在「bind mount 掛進容器的檔案被 `git pull` 換掉之後」。git 不是就地修改檔案，
而是寫一個新檔再 `rename` 蓋過去，所以 inode 會變；而**單一檔案**的 bind mount 綁的是
容器建立當下那個 inode，舊的消失之後就再也掛不上。執行中的容器也還是看到舊內容。

`up -d --build`（或 `up -d`）可以解決，因為那會重建容器、重新解析掛載來源。

本專案的 `backup` 服務因此改成掛**整個 `docker/production` 目錄**而不是單一檔案
（目錄的 inode 不會因為內容更換而變），所以現在 `restart` 是安全的。如果之後要再
掛任何腳本或設定檔進容器，請一律掛目錄，不要掛單一檔案。

**容器莫名其妙被重建，或資料庫突然變空的**

`compose.production.yaml` 的專案名稱是寫死的（`name: attendance-system-production`），
而 compose 用名稱而不是目錄識別專案——所以同一台主機上，**任何目錄**執行這個檔案
都會操作到同一組容器與 volume。如果有人在這台機器上另外開了一份 checkout 拿來測試，
他的 `up`／`down` 就是在動你的正式堆疊。完整說明與正確的測試方式見第 4 節的
「⚠ 這個 compose 檔在一台主機上只認一組容器」。

判斷方法——看容器實際是從哪個目錄啟動的：

```bash
docker inspect attendance-system-production-app-1 \
  --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}'
```

以及資料庫 volume 是什麼時候建立的（如果時間點就是「資料消失」的那一刻，
代表舊的被刪掉重建了）：

```bash
docker volume inspect attendance-system-production_db-data --format '{{.CreatedAt}}'
```

**開發環境的 Sail 容器突然壞掉**

反方向的同一個問題：如果拿掉了 `compose.production.yaml` 最上面的 `name:`，
compose 會改用目錄名稱當專案名，在開發機上就會跟 Sail 的 `attendance-system`
撞在一起，正式環境的設定會把 Sail 的 mariadb 容器直接接管重建。
**不要拿掉那個 `name:`。**

**已經整套用 root 部署好了，想改成一般帳號**

不需要重建任何容器或資料——容器與 volume 是 docker daemon 管的、跟哪個使用者下的
指令無關，換帳號之後 `attendance ps` 看到的還是同一組東西。以 root 執行：

```bash
# 1. 建立帳號並給 docker 權限
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy

# 2. 專案目錄改成它所有（.env.production 的 600 權限會跟著保留）
chown -R deploy:deploy /opt/attendance-system

# 3. 把 attendance 這個函式從 root 的 ~/.bashrc 搬到 deploy 的
#    （手動編輯兩個檔案，別直接 cat 過去，root 的 bashrc 裡通常還有別的東西）
```

之後改用 `deploy` 登入。確認一下能正常操作：

```bash
su - deploy
attendance ps          # 應該看得到原本那四個容器
```

都正常之後，再考慮把 root 的 SSH 直接登入關掉（`/etc/ssh/sshd_config` 的
`PermitRootLogin no`，改完 `systemctl restart ssh`）——**先確認 deploy 這個帳號
真的登得進來再關**，不然會把自己鎖在門外。

---

## 8. 上線前資安檢查清單

對應 [system_structure.md](system_structure.md) 的「上線前資安檢查清單」，這裡是可以
逐條照做的版本。

```bash
# 1. APP_DEBUG 必須是 false、APP_ENV 必須是 production
attendance exec app php artisan tinker --execute='echo config("app.debug")?"DEBUG 開著！":"debug=false OK", " / ", config("app.env");'
```

```bash
# 2. 亂猜的網址只會得到乾淨的 404／405，不會噴出堆疊追蹤
attendance exec web wget -qS -O- --header="Host: <你的網址>" http://127.0.0.1/definitely-not-a-route 2>&1 | head -3
# 應該是 404，且內容裡不能出現 /var/www/html、vendor/laravel、APP_KEY 等字樣
```

```bash
# 3. 未登入不能碰到任何資料頁
attendance exec web wget -qS -O- --header="Host: <你的網址>" http://127.0.0.1/dashboard 2>&1 | head -3
# 應該是 302 導回登入頁
```

```bash
# 4. session cookie 必須帶 secure / httponly / samesite
attendance exec web wget -qS -O /dev/null --header="Host: <你的網址>" http://127.0.0.1/ 2>&1 | grep -i set-cookie
```

```bash
# 5. 確認沒有任何 port 被發佈到 host（Ports 欄不該有 0.0.0.0:xxx->）
attendance ps --format '{{.Service}}\t{{.Ports}}'
```

```bash
# 6. 確認沒有已知密碼的帳號被 seeder 生出來
attendance exec app php artisan tinker --execute='App\Models\User::pluck("username")->each(fn($u)=>print($u.PHP_EOL));'
# 只應該有你自己用 admin:create 建的那些
```

```bash
# 7. 確認備份真的在跑，而且還原過至少一次
attendance exec backup /scripts/backup.sh verify
# 沒有做過還原演練的話，現在做（見第 9 節）——沒有還原過的備份不算備份
```

8. 用瀏覽器實測登入頻率限制：同一個帳號連續打錯密碼 5 次，第 6 次應該出現
   「登入嘗試次數過多，請 N 秒後再試一次。」

9. 用瀏覽器實測權限邊界：用一個學生身分的帳號，手動在網址列輸入
   `/admin/users`、`/attendance/{別班的 id}`，都應該得到 403。

---

## 9. 備份與還原

> **設定步驟在[第 3.4 節](#34-建立備份目錄)與[第 3.8 節](#38-設定異地備份強烈建議)**，
> 照著第 3 節從頭做一次就會全部設定好。這一節是運作方式與日常操作的參考。

### 運作方式

備份由 compose 的 `backup` 服務自動執行，`attendance up -d` 就會生效——刻意做成
容器而不是主機 cron，因為主機 cron 是主機專屬設定，換一台機器就要重做一次，
而「忘記手動步驟」這件事在這個專案已經出過兩次事。

異地鏡像則相反，是主機端的腳本（理由見[下方](#異地備份複製到第二顆實體硬碟)）。

**設定**（`.env.production`，見 `.env.production.example` 的完整說明）：

| 變數 | 說明 |
|---|---|
| `BACKUP_PATH` | 備份檔放在主機的哪個目錄。**必須是主機路徑，不能是 docker volume** |
| `BACKUP_UID` / `BACKUP_GID` | 備份檔的擁有者（用 `id -u` / `id -g` 查部署帳號） |
| `BACKUP_HOUR` | 每天幾點跑，預設 3（UTC） |
| `BACKUP_KEEP_DAILY` / `BACKUP_KEEP_MONTHLY` | 保留份數，預設 30 日 + 12 月 |
| `BACKUP_MONITOR_ENABLED` | 開啟後台的「備份過期」警告 |

`BACKUP_PATH` 一定要是主機目錄的理由很直接：`docker compose down -v` 會把專案宣告的
volume 全部刪掉，備份跟資料庫一起消失的話這整套就沒有意義——而那正是實際發生過的
事故（見第 4 節）。

備份容器啟動時，如果當天還沒有備份就會先跑一次——這樣裝好立刻看得到結果，
不必等到隔天凌晨才知道設定對不對。

**日常操作**（全部透過備份容器，在任何主機上都一樣）：

```bash
attendance logs backup                                    # 看備份記錄
attendance exec backup /scripts/backup.sh list                     # 列出所有備份檔
attendance exec backup /scripts/backup.sh once                     # 立刻備份一次
attendance exec backup /scripts/backup.sh verify                   # 檢查最新備份還在且夠新
```

**還原**：

```bash
attendance exec backup /scripts/backup.sh list
attendance exec backup /scripts/backup.sh restore <檔名>            # 不加 --confirm 只會顯示將要做什麼
attendance exec backup /scripts/backup.sh restore <檔名> --confirm  # 真的執行
attendance restart app                                    # 讓 migration 補上結構差異
```

還原會先清空現有資料表再載入，所以是破壞性操作，必須明確加上 `--confirm`。

**備份不依賴任何密碼。** dump 是純文字 SQL，裡面不含資料庫帳號（只 dump
`attendance_system`，使用者帳號在 `mysql` 系統資料庫裡）。就算 `.env.production`
連同 `DB_PASSWORD`、`APP_KEY` 全部弄丟，也能把備份還原到一組全新密碼的環境
——實測驗證過，包括使用者原本的登入密碼仍然可用（密碼是 bcrypt 雜湊，跟
`APP_KEY` 無關；專案裡也沒有任何 `encrypted` 欄位）。弄丟 `APP_KEY` 的唯一後果是
所有人被登出一次。

> 順帶一提：只要容器還在跑，`.env.production` 的內容都能從
> `docker inspect <容器> --format '{{.Config.Env}}'` 撈回來，不需要動用任何救援模式。

### 還原演練

**沒有還原過的備份不算備份。** 演練可以在同一台機器上安全進行——用 `-p` 開一個
丟棄式的 compose 專案，完全不會碰到正式那組（`-p` 的優先權高於檔案裡的 `name:`）：

```bash
# 1. 準備一份演練用的設定：換掉密碼與備份目錄，其餘照舊
sed -e 's|^BACKUP_PATH=.*|BACKUP_PATH=/tmp/restore-drill|' .env.production > /tmp/.env.drill
mkdir -p /tmp/restore-drill/daily
cp /opt/attendance-backups/daily/<要驗證的檔名> /tmp/restore-drill/daily/

# 2. 只起 mariadb 與 backup（app 不需要，它的 env_file 是寫死的路徑）
DRILL="docker compose -p attendance-restore-drill --env-file /tmp/.env.drill -f compose.production.yaml"
$DRILL up -d mariadb backup

# 3. 還原並檢查筆數
$DRILL exec backup /scripts/backup.sh restore <檔名> --confirm
$DRILL exec -T mariadb sh -c 'MYSQL_PWD=$MARIADB_PASSWORD mariadb -u $MARIADB_USER $MARIADB_DATABASE \
    -e "select (select count(*) from users) as users, (select count(*) from students) as students;"'

# 4. 清掉演練環境（-p 保證只動到演練那一組）
$DRILL down -v && rm -rf /tmp/restore-drill /tmp/.env.drill
```

建議每學期做一次，並且在做破壞性 migration 或升級 MariaDB 之前也做一次。

### 備份過期警告

備份最常見的失敗方式不是當下報錯，而是某天默默停掉、直到真的需要還原時才發現。
所以備份容器每次成功都會往資料庫寫一筆心跳，**超過 `BACKUP_WARN_AFTER_HOURS`
（預設 48）小時沒有心跳，有 `audit.view` 權限的帳號就會在每一頁看到警示橫幅**。

48 小時代表「連續漏掉兩次」——抓 24 小時的話稍微延遲就會誤報。

心跳證明的是「備份程序有跑完」，不證明「檔案現在還在」（例如有人把目錄清掉）。
後者由 `backup.sh verify` 檢查實體檔案，兩者互補，做還原演練時順手跑一次。

**心跳是「對照磁碟上的檔案補齊」，不是「備份完寫一筆就算了」。** 磁碟上的檔案才是
事實，心跳只是給應用程式看的投影，所以每次備份與每次容器啟動都會對照一次，把有
檔案卻沒有紀錄的補上（用檔案自己的時間，不是當下時間）。

這樣設計是因為 `backup` 容器不依賴 `app` 容器（兩者都只等 mariadb healthy），第一次
部署時備份很可能在 migration 建好 `backup_runs` 之前就完成了——檔案是好的，心跳卻
寫不進去，於是一套完全健康的全新環境會顯示「從來沒有成功備份過」長達一天。
現在啟動時會先等資料表最多 120 秒，就算真的錯過了，下一次啟動或下一次備份也會自動
補回來，不需要人工介入。

> 如果你在修正之前就已經部署過，`/opt/attendance-backups` 有檔案但後台仍然告警：
> `git pull` 之後 `attendance restart backup` 即可——備份腳本是 bind mount 掛進
> 容器的，不需要重建映像。

### 異地備份（複製到第二顆實體硬碟）

備份跟資料庫在同一顆硬碟上時，只防誤刪、不防硬體故障。`mirror-backups.sh` 把備份
複製到另一顆實體硬碟。

在 `.env.production` 設定目標：

```bash
BACKUP_MIRROR_PATH=/mnt/wsl/PHYSICALDRIVE2p2/attendance-backups
```

首次設定與安裝排程：

```bash
./docker/production/mirror-backups.sh init      # 建目錄與標記檔（會用到 sudo）
./docker/production/mirror-backups.sh install   # 安裝每日 03:30 的 cron
./docker/production/mirror-backups.sh status    # 確認
```

日常查看：

```bash
./docker/production/mirror-backups.sh status
attendance exec backup /scripts/backup.sh verify         # 會一併回報鏡像的同步時間
```

**這一支刻意跑在主機上，不是容器。** 目標若是 WSL 掛載的實體硬碟，掛載狀態隨時
可能變（WSL 重啟後要重新 `wsl --mount`），而 docker 的 bind mount 是在容器啟動時
解析的——主機重新掛載之後，容器裡看到的仍然是舊的那一份，會安靜地寫到錯誤的地方。
主機端的腳本每次都看得到真實狀態。

#### ⚠ WSL 掛載的陷阱，以及腳本為什麼要三重確認

`wsl --mount` 掛上的硬碟出現在 `/mnt/wsl` 底下，而 **`/mnt/wsl` 本身是 tmpfs，
也就是記憶體**。硬碟沒掛上的時候，那個路徑要嘛不存在，要嘛只是 tmpfs 上一個普通的
空目錄——`rsync` 會很開心地把備份寫進記憶體裡：看起來一切正常、佔用 RAM、然後在
下次重啟時全部消失。

所以「目錄存在」完全不足以當判斷依據。同步前會確認三件事，任何一項不過就拒絕執行：

1. 目標所在的掛載點真的是一個 mount point
2. 它的檔案系統不是 `tmpfs`／`ramfs`
3. 目標目錄裡有 `.attendance-mirror-target` 這個標記檔（`init` 建立的）——證明是
   「那一顆」碟，而不是剛好掛了別的東西上去

外加確認來源與目標在不同的裝置上，否則鏡像沒有意義。

硬碟沒掛上時，在 Windows 端重新掛載：

```
wsl --mount \\.\PHYSICALDRIVE2 --partition 2
```

#### 為什麼不用 `rsync --delete`

鏡像的目的是備援，不是做出一份一模一樣的副本。加了 `--delete` 的話，來源端不管是
正常輪替、還是有人誤刪整個目錄，都會原封不動地同步過去——那正好把備份最該防的情境
變成必然發生。

代價是鏡像端會累積：以每天約 15MB 估算，一年不到 6GB。真的需要清理時用
`mirror-backups.sh prune <天數>`，那是一個明確的決定，不是自動行為。

#### cron 在 WSL 上不會自動啟動

`install` 會提醒，但值得再說一次：

```bash
service cron status || sudo service cron start
```

WSL 每次重啟都要重新啟動 cron 服務（或設定成自動啟動）。這一點跟「硬碟要重新掛載」
一樣，是 WSL 環境特有的、搬到真正的 Ubuntu server 之後就不存在的問題。

---

## 10. 已知的缺口

- **站台掛掉沒有告警。** 備份有過期警告（見上），但「網站整個連不上」目前沒有任何
  主動通知——要有人自己發現。最低成本的做法是用 Cloudflare 的 health check。
- **`storage/` 沒有掛 volume**，容器重建就清空。目前是對的（記錄走 stderr、沒有需要
  保存的上傳檔），但若未來新增「保存上傳檔案」的功能必須補上，見 [6.6](#66-容器裡的檔案系統對-php-是唯讀的)。
- **異地備份仍在同一台機器上。** 第二顆硬碟能防硬碟故障，但防不了整台機器出事
  （失竊、火災、勒索軟體加密整台）。真正的異地要複製到另一台機器或雲端，屆時
  dump 檔請先加密——裡面有全校學生姓名、學號、性別與密碼雜湊。
