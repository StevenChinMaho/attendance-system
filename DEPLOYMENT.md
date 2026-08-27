# 正式環境部署與維運

這份文件涵蓋：在一台全新的 Ubuntu server 上從零把系統跑起來、日常怎麼管理、
怎麼套用更新，以及**怎麼寫更新才能保證在正式環境正確生效**。

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
  ║  └──────────────┘           └──────────┘   └───────────┘  ║
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
| `cloudflared` | `cloudflare/cloudflared:2026.8.2` | 無狀態 | 版本刻意釘死，不用 `:latest` |

**整個系統唯一有狀態的地方是 `db-data` 這個 volume。** 其餘容器都可以隨時砍掉重建，
這也是備份只需要顧一個對象的原因。

### 不需要的東西

- **不需要 Redis**：session／cache／queue 全部用 `database` driver，對應資料表都在 migration 裡。
- **不需要 queue worker、不需要 cron 容器**：全專案沒有任何 `dispatch()`／`ShouldQueue`／
  Mail／Notification，`routes/console.php` 也沒有排程。
- **不需要 SMTP**：沒有註冊流程也沒有密碼重設信，帳號一律由管理者建立與重設。
- **不需要在 nginx 設定 TLS**：憑證由 Cloudflare edge 處理。

---

## 2. 前置需求

在伺服器上：

- Docker Engine + Compose plugin（`docker compose version` 要能跑）
- `git`
- 這個系統本身不需要對外開任何防火牆 port

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

`APP_URL` 填錯會讓**所有**請求被擋成 400：`trustHosts()` 只信任這個網址的主機名。

`.env.production` 已經在 `.gitignore` 與 `.dockerignore` 裡，不會進版本控制，也不會被
烤進映像——設定值是容器啟動時由 compose 注入的。

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

### 3.4 建置並啟動

```bash
attendance up -d --build
```

第一次會花幾分鐘（要編前端資產、裝 PHP 依賴）。啟動順序由 compose 自己處理：
mariadb 健康後才起 app，web 健康後才起 cloudflared。

確認狀態：

```bash
attendance ps
```

四個服務都應該是 `Up`，`mariadb` 與 `web` 應該是 `(healthy)`。

> app 容器啟動時，[entrypoint](docker/production/entrypoint.sh) 會自動等資料庫、跑
> `migrate --force`、重建 config／route／view cache。不需要手動做這些。

### 3.5 確認角色與權限

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

### 3.6 建立第一個管理者帳號

```bash
attendance exec app php artisan admin:create
```

會互動詢問帳號、姓名、密碼（密碼輸入時不顯示）。建立出來的帳號帶
`must_change_password`，本人首次登入時會被強制導去變更密碼。

**密碼不接受用參數傳入**，那會留在 shell history 跟 `ps` 的輸出裡。

### 3.7 驗收

打開 `https://你的網址`，應該看到登入頁。用剛建立的帳號登入 → 會被導去變更密碼 →
改完之後進入即時看板。

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
| `attendance down -v` | **會刪掉資料庫 volume，全校點名紀錄全滅**。除非你真的要重來，否則永遠不要打 `-v` |
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

# 3. 重建映像並套用
attendance up -d --build

# 4. 確認
attendance ps
attendance logs --tail 50 app
```

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

目前沒有 queue worker 也沒有 cron 容器，因為專案裡一個都沒用到。如果之後加了
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

7. 用瀏覽器實測登入頻率限制：同一個帳號連續打錯密碼 5 次，第 6 次應該出現
   「登入嘗試次數過多，請 N 秒後再試一次。」

8. 用瀏覽器實測權限邊界：用一個學生身分的帳號，手動在網址列輸入
   `/admin/users`、`/attendance/{別班的 id}`，都應該得到 403。

---

## 9. 待辦（重要）

### 資料庫備份 —— 尚未實作

**目前沒有任何自動備份。** 整個系統唯一有狀態的地方是 `db-data` volume，一旦伺服器
硬碟損壞或有人誤下 `attendance down -v`，全校的點名紀錄與稽核歷程就沒了。

> 這不是假設性的風險：測試環境已經因為 `down -v` 整個資料庫被清空過一次
> （見第 4 節的專案名稱說明）。當時沒有備份，資料完全救不回來。

手動備份（升級 MariaDB 或做破壞性 migration 前**務必**先跑）：

```bash
mkdir -p /opt/attendance-backups
attendance exec -T mariadb \
    mariadb-dump -u root -p"$(grep '^DB_ROOT_PASSWORD=' /opt/attendance-system/.env.production | cut -d= -f2-)" \
    --single-transaction --routines --events attendance_system \
    | gzip > /opt/attendance-backups/attendance-$(date +%F-%H%M).sql.gz
```

還原：

```bash
gunzip -c /opt/attendance-backups/attendance-2026-08-27-0300.sql.gz \
  | attendance exec -T mariadb mariadb -u root -p"<root 密碼>" attendance_system
```

之後要做的自動化方向：把上面那段包成腳本放進 host 的 cron（每日凌晨），保留 N 天，
並且**實際測試過一次還原**——沒有還原過的備份不算備份。

### 其他

- 監控／告警（例如站台掛掉時通知）目前沒有。最低限度可以用 Cloudflare 的 health check。
- `storage/` 沒有掛 volume，若未來新增「保存上傳檔案」的功能必須補上（見 6.6）。
