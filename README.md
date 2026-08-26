# 國中點名系統

國中校園點名系統，取代以往「學生手抄遲到名單、跑學務處更新白板」的人工流程，改由線上即時登記出席狀況，並讓導師追蹤缺席/遲到學生的聯繫情形。

完整的使用場景、權限設計、資料庫架構與各項設計決策，請見 [system_structure.md](system_structure.md)——本文件只涵蓋「怎麼把環境跑起來」。

## 技術棧

- Laravel 13 / PHP 8.5
- MariaDB 12.3
- Livewire（前端互動）+ Blade + Tailwind CSS 4
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission)（角色與權限）
- [spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog)（稽核紀錄）
- Laravel Sail（本機開發環境）

## 本機開發環境

本專案一律透過 [Laravel Sail](https://laravel.com/docs/sail) 開發，**不要**直接在 host 上跑 `composer`/`php artisan`/`npm`，以免跟容器內的 PHP/Node 版本產生落差。

### 事前準備

- Docker（Windows/WSL 環境請用 Docker Desktop，並在 Settings → Resources → WSL Integration 開啟對應的 distro）
- 若是第一次 clone 下來，`vendor/` 目錄還不存在，需要先用一個一次性容器跑 `composer install`：

  ```bash
  docker run --rm -v $(pwd):/var/www/html -w /var/www/html laravelsail/php85-composer:latest composer install --ignore-platform-reqs
  ```

### 啟動

```bash
cp .env.example .env          # 第一次 clone 才需要
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate   # 若 .env 還沒有 APP_KEY
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev             # 開發模式；正式建置用 npm run build
```

啟動後可用瀏覽器打開 http://localhost:8000，用 seeder 建立的預設帳號登入：

| 帳號 | 密碼 |
|---|---|
| `admin` | `password` |

> 帳號僅供本機開發測試，正式環境不會使用這組預設密碼。

### 常用指令

```bash
./vendor/bin/sail artisan test     # 執行測試
./vendor/bin/sail artisan pint     # 程式碼風格檢查/修正
./vendor/bin/sail artisan tinker   # 互動式 shell
./vendor/bin/sail down             # 關閉容器
```

## 生產環境部署

生產環境**不使用 Sail**，改用最小化的 Docker 部署（`compose.production.yaml`）並透過 `cloudflared` 對外公開，四個容器都不對 host 發佈任何 port。

- 從零建置、日常維運、套用更新、疑難排解：**[DEPLOYMENT.md](DEPLOYMENT.md)**
- 為什麼這樣設計：[system_structure.md](system_structure.md) 的「生產環境部署」章節

## 安全性

此系統為非公開的校內系統，帳號僅由管理者建立，不開放自主註冊；所有頁面除登入頁外均要求登入才能存取。若發現安全性問題，請勿公開回報，直接聯繫專案維護者。
