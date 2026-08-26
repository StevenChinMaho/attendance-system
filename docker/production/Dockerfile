# syntax=docker/dockerfile:1
#
# 正式環境映像。跟開發用的 compose.yaml（Laravel Sail）完全無關，
# 兩者不共用任何東西——Sail 的映像帶著 xdebug、composer、node、
# 整個 /var/www/html 的 bind mount，那是開發便利性，不是能拿去對外
# 服務的東西（見 system_structure.md 生產環境部署）。
#
# 分成三段：資產編譯 → 依賴安裝 → 執行環境。前兩段的工具鏈
# （node、npm、composer、編譯器）都不會出現在最終映像裡。

# ---------------------------------------------------------------------
# base：執行期真正需要的 PHP 環境，builder 與 runtime 共用同一份，
# 確保 composer 解析平台需求時看到的擴充跟正式跑起來的完全一致。
# ---------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS base

# php:8.5-fpm-alpine 已內建 ctype/dom/fileinfo/filter/hash/iconv/json/
# libxml/mbstring/openssl/pcre/PDO/session/SimpleXML/tokenizer/xml/
# xmlreader/xmlwriter/OPcache/zlib，實際缺的只有這三個：
#   pdo_mysql — 連 MariaDB
#   zip、gd    — phpoffice/phpspreadsheet 的 composer 平台需求
#                （/admin/students/import 讀學校的 Excel 匯出檔）
# 其餘一律不裝，維持最小攻擊面。
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev; \
    apk add --no-cache libzip libpng libjpeg-turbo freetype; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd; \
    apk del .build-deps

WORKDIR /var/www/html

# 建立 Laravel 執行期需要的目錄骨架。整個 storage/ 在 .dockerignore 裡
# 被排除（映像內容不該取決於開發機的 storage 裡剛好有什麼），所以這裡
# 要自己補回來——而且必須在 base 這一層，因為 vendor 階段的
# `artisan package:discover` 就已經需要 storage/framework/views 了：
# 少了它會失敗在一句看不出原因的 "Please provide a valid cache path."。
#
# 注意：這裡不能用 `{a,b,c}` 這種 brace expansion。Alpine 的 /bin/sh
# 是 busybox ash，不支援它，會安靜地建出一個名字裡真的有大括號的目錄，
# 而該建的目錄一個都沒建到，且 build 完全不會報錯（已經踩過一次）。
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# ---------------------------------------------------------------------
# assets：編譯 Tailwind / Livewire 的前端資產。
# public/build 在 .gitignore 裡，正式環境沒有 node，所以一定要在這裡產出。
# ---------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app

# 先只複製 lock 檔，讓 npm ci 這層在原始碼變動時還能命中快取。
COPY package.json package-lock.json ./
RUN npm ci

# Tailwind v4 靠掃描原始碼決定要產出哪些 utility；這個專案的 class
# 只出現在 resources/ 底下（app/ 的 PHP 檔沒有任何 class 字串，
# 已實際 grep 確認過），所以不需要把整包原始碼搬進來。
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------
# vendor：安裝 PHP 依賴。--no-dev 會排除 sail/pint/phpunit/faker/pail。
# ---------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

# --no-scripts：post-autoload-dump 會跑 `artisan package:discover`，
# 但此時原始碼還沒複製進來，一定會失敗。等原始碼到齊後再一次做完。
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

# classmap-authoritative：正式環境的檔案是不可變的，把整個 classmap
# 固化下來，執行期不再做任何檔案系統掃描找 class。
# 這一步也會觸發 composer 的 post-autoload-dump 腳本，也就是
# `artisan package:discover`（產生 bootstrap/cache/packages.php），
# 所以不需要另外再呼叫一次。
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------
# runtime：最終映像。沒有 composer、沒有 node、沒有編譯器、沒有 dev 依賴。
# ---------------------------------------------------------------------
FROM base AS runtime

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# 依序：已裝好依賴＋已產生 cache 的原始碼 → 編譯好的前端資產。
# 刻意「不」加 --chown：這樣複製進來的原始碼與 vendor 都是 root 所有，
# 而 php-fpm 是以 www-data 執行的，等於整份程式碼對執行中的 PHP 程序
# 而言是唯讀的。萬一應用層被找到任意寫入的漏洞，攻擊者也沒辦法把
# web shell 寫進 public/ 或竄改既有的 PHP 檔案來取得持續性。
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

# 承上，storage 與 bootstrap/cache 是唯二需要開放寫入的目錄
# （Livewire 上傳暫存檔、config/route/view cache）。目錄骨架本身建在
# base 那一層，隨上面的 COPY --from=vendor 一起進來。
RUN set -eux; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R u+rwX storage bootstrap/cache

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------
# web：nginx。靜態檔直接烤進映像，而不是跟 app 共用一個 named volume。
#
# 共用 volume 的作法有一個很難察覺的陷阱：named volume 只在「第一次
# 建立、內容為空」時才會從映像複製資料進去。之後每次更新版本，volume
# 裡留著的仍然是舊的 public/build，而 index.html 引用的檔名是帶雜湊的，
# 結果就是全站樣式壞掉、而且 docker compose 看起來一切正常。
# 各自烤進映像就沒有這個狀態問題——更新一律是「換映像」。
# ---------------------------------------------------------------------
FROM nginx:stable-alpine AS web

COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /var/www/html/public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
