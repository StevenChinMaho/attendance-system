# 技術棧

- laravel 13.x
- php 8.5
- mariadb 12.3
- spatie/laravel-permission（角色與權限管理，供後台彈性調整權限階級）
- spatie/laravel-activitylog（關鍵資料異動稽核紀錄）

# 開發守則

- 使用laravel sail進行開發，本機測試，git版本控制。開發過程必須嚴格檢查程式碼安全性，盡可能避免資安漏洞，特別是權限認證必須謹慎檢查，金鑰或.env等私密資料也必須謹慎管理。
- 所有功能必須盡可能依照框架設計來實現，不能一味地只想實現功能而跳脫框架。
- 所有路由除登入頁面外一律要求登入（`auth` middleware），未登入者一律只會看到登入介面，不會有任何資料外洩的路由或 API。

# 開發流程規劃

## Git 分支策略

- `main` 永遠保持可部署狀態，不直接在上面開發。
- 每個功能/階段開一個 feature branch（例如 `feature/school-classes-crud`），完成後合併回 `main`。個人專案不強制 PR review，但合併前必須跑過 `sail artisan test` 全綠、`sail artisan pint` 過。
- Commit message 清楚描述「改了什麼」與「為什麼」，中英文皆可，不需要嚴格遵循 Conventional Commits 格式。

## 開發階段順序

依功能相依性排序，後面的階段建立在前面階段的資料表/權限之上：

1. **帳號與登入基礎建設**（已完成）：`users` 表、spatie 角色權限套件、稽核套件、最小登入功能。
2. **角色權限初始化**：建立 `admin`/`homeroom_teacher`/`student_rep` 角色與對應權限的 seeder；管理者後台建立/停用帳號的介面。
3. **學年制度骨架**：`school_classes`、`students`、`teachers` 的 migration + model + 管理者 CRUD 介面（新增班級、編輯學生資訊、指派導師）。
4. **點名核心功能**：`attendance_sessions`、`attendance_records`，含「一鍵全到」、股長/導師的點名操作介面。
5. **處理情形與稽核**：`attendance_follow_ups`，串接 `spatie/laravel-activitylog` 記錄異動歷程。
6. **即時狀態看板**：全校班級點名進度/缺席名單的 Dashboard（輪詢更新）。
7. **正式環境部署**：最小化 Docker 映像、cloudflared 設定、上線前的資安複查。

每個階段開始前，先確認 [system_structure.md](system_structure.md) 裡對應的資料庫設計與業務規則沒有遺漏的疑問，避免中途發現設計缺口要回頭改 schema。

## 測試策略

- 每個階段至少要有 Feature test 覆蓋核心流程（例如：點名送出後 `attendance_records` 數量正確、「一鍵全到」預設狀態正確）。
- 權限邊界一定要有對應測試：例如副班長無法讀取/修改別班的點名資料、一般學生（無帳號）无法登入、停用帳號無法登入。這類測試對應到 Policy 的實作，是最容易因為疏忽而出安全漏洞的地方。
- 合併回 `main` 前跑 `sail artisan test`，測試沒過不合併。

## Code Review

每個階段開發完、合併前，用 `/code-review` 自我審查一次目前分支的變更，特別留意權限認證相關的程式碼（呼應開發守則裡「權限認證必須謹慎檢查」的要求）。

# 生產環境部署

在遠端ubuntu server上也使用docker部署，使用docker cloudflared公開至網路，不可使用在生產環境使用laravel sail運行，須保證輕量、最小攻擊面等。

# 使用場景

此為國中校園點名系統。
以往人工的做法是，班上負責點名的學生(副班長)要在第一節上課前(7:45AM)整理出一個遲到名單，親自交到學務處，並更新學務處所在的一個統計全校各班級應到與未到人數的白板。後續遲到學生有來了也要送出資料更新狀態，學校老師需要追蹤缺席學生，連絡學生家長了解缺席原因。這個系統就是為了智慧化這個程序。

# 帳號與權限設計

- 帳號（`users`）與業務資料（`students`、`teachers`）分離管理：`students`、`teachers` 各自可透過 nullable 的 `user_id` 選擇性關聯一個帳號，並非每一筆學生/教師資料都需要登入權限（例如一般學生不需要帳號，只有副班長需要；一般任課教師不一定需要帳號，只有導師/管理者需要）。
- 帳號僅能由管理者於後台建立，不開放自主註冊；`users` 保留 `is_active` 欄位供管理者停用帳號而不需刪除資料。
- 角色與權限透過 `spatie/laravel-permission` 套件管理，預設對應下方「權限階級」三種角色，但角色與細部權限均可由管理者在後台新增/調整，未來甲方需求變動時不需修改程式碼或資料庫結構。

# 權限階級

此系統為非公開網站，帳號僅限於校內管理者分發，不可自主建立，並且所有存取與修改操作均須登入帳號，外來者永遠應該只能看到沒有任何資訊的登入介面。
- 學生(副班長): 只能看見並管理自身班級的學生出席狀況
- 班級導師: 擁有學生的權限，並可以針對缺席或遲到學生建立 "處理情形"，標註聯繫家長後了解到的學生資訊，例如 "遲到"、"病假"、"電聯未接"、"9:19已到"等等資訊。
- 管理者: 除了擁有學生與班級導師的權限外、還可以編輯學生資訊、學生所在班級、新增或修改班級 (通常用於新學期升年級用)、以及建立使用者帳號並管理其權限。

以上為預設角色設計，實際權限細節皆可透過權限套件於後台調整，保留彈性。

# 系統設計要點

## 介面設計
針對介面設計與操作流程，這套系統的 UI/UX 核心精神在於減輕第一線人員（如副班長與導師）的負擔，並結合國中校園的實務需求設計防呆機制。以下是具體的介面規劃細節：

1. 股長與點名人員介面 (前端操作)

「一鍵全到」功能：由於多數日子的班級狀態是全員到齊，系統特別設計了「一鍵全到」的快捷按鈕。副班長不需要逐一勾選學生，只需一鍵即可完成大部分的日常點名，大幅縮短操作時間。

跨裝置的流暢體驗：考量到實際點名場景，介面針對不同裝置進行了最佳化：

手機端：設計為適合單手操作、快速點擊與滑動的介面，方便股長在教室走動時快速點名。

電腦端：提供適合大螢幕的「整批勾選」與列表預覽功能，方便在辦公室使用電腦的老師進行批次管理。

2. 教師與管理端介面 (後台與看板)

即時狀態看板 (Dashboard)：為導師與生教組設計了直覺的視覺化看板。介面上會清晰呈現當前各班的點名進度，以及缺席、請假學生的即時名單，讓管理者能一目了然地掌握全校狀態。看板採輪詢（polling）方式更新即可，不需要 WebSocket。

3. 防呆與防錯機制

「未確實送出」提示：為了明確區分「全班全勤」與「忘記點名」，介面上會有明顯的狀態指引與防呆設計。股長必須確實點擊「送出點名單」並看到成功提示，系統才會認定該堂課點名完成，避免因中斷操作而產生的漏點名誤判。

（資料庫層面不需要額外欄位處理此區分：一個 `attendance_sessions` 紀錄的存在本身就代表「該班該時段已完成點名」，尚未送出則該筆 session 根本不存在，Dashboard 可直接以此判斷「已點名/尚未點名」。）

## 學年制度

關於「學年制度與升級機制」的設計，核心目標是確保當學生升級、轉班或轉學時，過去的歷史點名紀錄不會產生關聯錯亂。

以下是針對這個部分的詳細規劃架構：

1. 學年度與學期的格式標準

民國年整數格式：為了符合台灣校務行政的慣例，系統內的學年度會採用「民國年整數」（例如：112、113）作為業務代號。

儲存位置：這些學年度與學期的資訊會直接儲存與綁定在「班級表」當中。

2. 班級的生命週期管理 (獨立實體設計)

系統將「班級」視為一個具有生命週期的實體。

切斷歷史與未來的牽連：每一學年的班級都是「獨立存在」的紀錄。也就是說，112 學年度的 101 班，與 113 學年度升上二年級的 201 班，在資料庫中是完全獨立的兩筆班級資料。這種設計能確保舊學年的歷史資料被完整且安全地凍結保存，不會因為新學年的變動而遭到覆蓋。

3. 升學年 / 轉班 / 轉學的處理原則

升學年：直接更新該學生 `students` 記錄的 `school_class_id`，指向新學年的班級即可。歷史出席紀錄（`attendance_records`）是透過 `attendance_sessions.school_class_id` 對應到「當時」的班級，不受學生目前所屬班級變動影響，因此不需要新增資料表或版本化學生紀錄。

轉班／轉學：國中實務上極少發生轉班，轉學後若轉回通常也會被分配到新學號/座號。這種情況不特別設計額外機制，由管理者透過既有的「編輯學生資訊」權限，直接手動調整該學生的 `student_number`／`seat_number`／`school_class_id` 即可涵蓋絕大多數情境，不需要為此增加資料庫複雜度。

## 出席狀態列舉

`attendance_records.status` 使用 PHP 8.1+ 原生 backed enum 實作（對應資料庫 string 欄位），初期列舉值：

- `PRESENT`（出席）
- `LATE`（遲到）
- `SICK_LEAVE`（病假）
- `PERSONAL_LEAVE`（事假）
- `OFFICIAL_LEAVE`（公假）
- `ABSENT`（缺席/曠課，未請假亦未到）

導師與家長聯繫後了解到的細節（例如「電聯未接」「9:19已到」）不屬於狀態本身，而是記錄在 `attendance_follow_ups`（見下方資料庫設計），因為同一筆出席紀錄可能經歷多次追蹤，需要保留時間序列，而非覆蓋單一欄位。

## 稽核紀錄

`attendance_records`（狀態變更）與 `attendance_follow_ups`（新增聯繫紀錄）透過 `spatie/laravel-activitylog` 記錄異動歷程（異動人、異動時間、異動前後內容），以應對後續可能的家長糾紛或行政追查需求。

## 資料庫設計
```
erDiagram
    users {
        int id PK
        string name
        string username
        string password
        boolean is_active
        timestamp last_login_at
    }
    teachers {
        int id PK
        int user_id FK
        string teacher_name
    }
    students {
        int id PK
        int school_class_id FK
        int user_id FK
        string student_number
        string seat_number
        string name
        string gender
    }
    school_classes {
        int id PK
        int academic_year
        int semester
        int grade
        string class_number
        int homeroom_teacher_id FK
    }
    attendance_sessions {
        int id PK
        int school_class_id FK
        date date
        string period
        int recorded_by FK
    }
    attendance_records {
        int id PK
        int attendance_session_id FK
        int student_id FK
        string status
        int updated_by FK
    }
    attendance_follow_ups {
        int id PK
        int attendance_record_id FK
        int teacher_id FK
        string content
        timestamp created_at
    }

    users     o|--o| teachers : "可選登入帳號"
    users     o|--o| students : "可選登入帳號(副班長)"
    users     ||--o{ attendance_sessions : "送出點名"
    users     ||--o{ attendance_records : "最後修改"
    teachers  ||--o{ school_classes : "擔任導師"
    teachers  ||--o{ attendance_follow_ups : "記錄人"
    school_classes ||--o{ students : has
    school_classes ||--o{ attendance_sessions : has
    students  ||--o{ attendance_records : has
    attendance_sessions ||--o{ attendance_records : contains
    attendance_records  ||--o{ attendance_follow_ups : has
```

### 補充說明

- **角色/權限資料表**：`spatie/laravel-permission` 會自行建立 `roles`、`permissions`、`model_has_roles`、`model_has_permissions`、`role_has_permissions` 等資料表，故不列於上方 ERD。
- **`attendance_sessions.period`**：刻意保留為一般字串而非資料庫層 enum。目前實務上只會有 `MORNING`（早上）、`NOON`（中午）、`AFTERNOON`（下午）三種值，對應甲方「一天三次」的需求；若未來需求變更為逐節點名，可直接擴充為 `1`～`8` 節，不需更動資料表結構。屆時若需記錄科目與任課教師，可再新增 `subjects` 表與對應欄位，現階段不預先建立以避免未使用的欄位/資料表增加維護負擔。
- **`attendance_sessions.recorded_by`**：記錄此次點名是由哪個帳號送出（通常是副班長，也可能是導師代為補登），供權責追溯用，區別於「一個時段的點名是否已完成」（後者由 session 是否存在判斷）。
- **唯一索引建議**：`attendance_records` 應對 `(attendance_session_id, student_id)` 建立唯一索引，避免同一學生在同一 session 出現重複紀錄。
- **`teachers.user_id` / `students.user_id`**：皆為 nullable + unique，僅需要登入權限的教師/學生才會有值。
