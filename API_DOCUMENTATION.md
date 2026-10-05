# 众鸥支付 (Seagull Pay) API 文档

本文档提供了众鸥支付后端接口（`api.php`）的全面说明。

## 基础信息
- **接口地址**: `api.php` ( `https://seagull.teft.cn/api.php`)
- **请求格式**: 
  - `GET` 请求参数附加在 URL 后面 (例如 `?action=check_session`)
  - `POST` 请求参数使用 `application/x-www-form-urlencoded` (表单数据) 或者在 URL 附加 `action` 并 POST 其余数据。
- **响应格式**: `JSON`，基础结构为：
  ```json
  {
      "status": "success" | "error" | "success_verify_needed",
      "message": "描述信息",
      "data": [] 
  }
  ```
- **鉴权方式**: 基于 Cookie 的 Session 认证。登录后，客户端（浏览器或 Python Requests 携带 Session）会自动带上 `PHPSESSID` 进行后续操作。

## 全局频率限制 (Rate Limits)
系统根据接口的敏感程度做了基于 IP 的严格频控：
1. **敏感操作 (Sensitive)**: `register`, `verify_register`, `forgot_password`, `reset_password` —— **每分钟限 5 次**
2. **金融操作 (Financial)**: `transfer`, `withdraw`, `deposit` —— **每分钟限 10 次**
3. **普通操作 (Standard)**: 其它所有接口 —— **每分钟限 120 次**

---

## 1. 账户与认证接口

### 1.1 注册 (Register)
- **Action**: `register`
- **Method**: `POST`
- **参数**:
  - `email` (string): 必填，用户邮箱
  - `password` (string): 必填，密码
- **响应**: 成功后会发送 6 位验证码到邮箱，返回状态为 `success_verify_needed`。如果该邮箱已注册但未验证，则会重置验证码。需调用 `verify_register` 接口完成注册。

### 1.2 验证注册码 (Verify Register)
- **Action**: `verify_register`
- **Method**: `POST`
- **参数**:
  - `email` (string): 必填，用户邮箱
  - `code` (string): 必填，6位邮箱验证码
- **响应**: 成功则激活账户并返回 `success`。验证码有效期为 10 分钟。连续错误 5 次将导致该验证码失效。

### 1.3 忘记密码 (Forgot Password)
- **Action**: `forgot_password`
- **Method**: `POST`
- **参数**:
  - `email` (string): 必填，已验证的用户邮箱
- **响应**: 发送包含重置验证码（有效期 10 分钟）的邮件，返回 `success`。

### 1.4 重置密码 (Reset Password)
- **Action**: `reset_password`
- **Method**: `POST`
- **参数**:
  - `email` (string): 必填，用户邮箱
  - `code` (string): 必填，邮箱验证码
  - `new_password` (string): 必填，新密码
- **响应**: 重置成功返回 `success`。需满足验证码有效且错误次数未达到 5 次。

### 1.5 登录 (Login)
- **Action**: `login`
- **Method**: `POST`
- **参数**:
  - `email` (string): 必填，用户邮箱
  - `password` (string): 必填，登录密码
- **说明**: 会校验账户是否已激活。成功登录后创建 Session 会话。**补充说明**：如果请求时带有有效的 `lang` Cookie（如 `zh` 或 `en`），系统会在登录时自动更新并保存该用户的语言偏好，用于后续邮件通知的语言。

### 1.6 登出 (Logout)
- **Action**: `logout`
- **Method**: `POST / GET`
- **说明**: 销毁当前 Session 会话。

### 1.7 会话状态检查 (Check Session)
- **Action**: `check_session`
- **Method**: `GET`
- **响应**: 返回当前会话状态。
  ```json
  {
      "is_logged_in": true/false
  }
  ```

---

## 2. 资金与交易接口 (需普通登录权限)

### 2.1 获取余额 (Get Balance)
- **Action**: `get_balance`
- **Method**: `GET`
- **响应**: 
  ```json
  {
      "status": "success",
      "balance": "100.00",
      "email": "user@example.com"
  }
  ```
- **注意**: 由于后端的处理机制，`balance` 字段可能会以字符串 (String) 形式返回。客户端在进行数学计算或格式化时应先将其转换为浮点数 (Float)。

### 2.2 转账 (Transfer)
- **Action**: `transfer`
- **Method**: `POST`
- **参数**:
  - `receiver_email` (string): 必填，收款人邮箱
  - `amount` (float): 必填，转账金额 (必须大于 0)
  - `note` (string): 选填，转账备注
  - `password` (string): 必填，发送者账户密码（作为支付密码）
- **说明**: 扣除发送者余额并增加收款人余额。禁止向自己转账。创建交易记录并根据双方语言偏好分别发送邮件通知。

### 2.3 提现 / 铸造代金券 (Withdraw)
- **Action**: `withdraw`
- **Method**: `POST`
- **参数**:
  - `amount` (float): 必填，提现/铸造金额 (必须大于 0)
  - `password` (string): 必填，账户密码
- **说明**: 扣除余额并生成一张价值相等的 **16 位字符 (A-Z, 0-9 随机组合)** 代金券 (Voucher)。返回包含完整提现代码的成功信息，并自动记录一条对应备注的系统交易。

### 2.4 充值 / 兑换代金券 (Deposit)
- **Action**: `deposit`
- **Method**: `POST`
- **参数**:
  - `code` (string): 必填，16 位代金券/充值码 (需严格为 16 位字符)
  - `password` (string): 必填，账户密码
- **说明**: 验证代金券有效性及是否已被使用。兑换成功后标记代金券已使用，并增加对应余额，同时生成一条充值交易记录。

### 2.5 获取交易记录 (Get Transactions)
- **Action**: `get_transactions`
- **Method**: `GET`
- **参数**:
  - `page` (int): 选填，分页页码 (默认 1)
- **响应**: 返回当前用户作为发送方或接收方的交易流水。每页返回 5 条记录，附加返回总页数 (`total_pages`)、当前页码 (`current_page`)、当前用户 ID (`current_user_id`) 以及对应的 `sender_email` 和 `receiver_email`。

### 2.6 获取我创建的代金券 (Get My Vouchers)
- **Action**: `get_my_vouchers`
- **Method**: `GET`
- **参数**:
  - `page` (int): 选填，分页页码 (默认 1)
- **响应**: 返回当前用户生成的代金券列表 (每页 5 条)。代金券码会进行打码掩码处理（显示前 6 位，后跟 10 个 `*`）。包含 `used_by_email` 以指示使用者。

### 2.7 查看完整代金券码 (Reveal Voucher)
- **Action**: `reveal_voucher`
- **Method**: `POST`
- **参数**:
  - `voucher_id` (int): 必填，代金券 ID
  - `password` (string): 必填，账户密码
- **响应**: 验证密码后返回无打码的完整 16 位代金券码。

---

## 3. 支付链接接口 (需普通登录权限)

### 3.1 创建支付链接 (Create Payment Link)
- **Action**: `create_payment_link`
- **Method**: `POST`
- **参数**:
  - `amount` (float): 必填，收款金额 (必须大于 0)
  - `note` (string): 选填，收款备注
- **响应**: 返回生成的 16 位字符的大写随机特征支付链接提取码 (Code)。

### 3.2 获取创建的支付链接 (Get Payment Links)
- **Action**: `get_payment_links`
- **Method**: `GET`
- **参数**:
  - `page` (int): 选填，分页页码 (默认 1)
- **响应**: 返回当前用户创建的收款链接列表。每页 5 条，附带分页数据。

### 3.3 撤销支付链接 (Cancel Payment Link)
- **Action**: `cancel_payment_link`
- **Method**: `POST`
- **参数**:
  - `id` (int): 必填，支付链接 ID
- **说明**: 仅能撤销状态为 `pending` 且属于当前用户的支付链接。成功后状态更新为 `canceled`。

### 3.4 支付他人链接 (Pay Link)
- **Action**: `pay_link`
- **Method**: `POST`
- **参数**:
  - `link_code` (string): 必填，支付链接代码
  - `password` (string): 必填，支付者账户密码
- **说明**: 根据链接代码向创建者付款。不可支付自己创建的链接。扣除支付者余额，增加创建者余额，状态更新为 `completed`，并发送双方通知邮件。

### 3.5 获取支付链接详情 (Get Pay Link Info)
- **Action**: `get_pay_link_info`
- **Method**: `GET`
- **参数**:
  - `id` (string): 必填，支付链接代码
- **响应**: 无需登录即可调用。只能返回状态为 `pending` 的链接详细信息，包含 `creator_email` 等字段。若链接不存在或已失效则返回 error。

---
**提示**：在所有涉及扣除余额、验证凭证及查看隐私操作时，均要求用户传输当前账号的 `password` 作为支付和验证密码，并同时携带有效的 `PHPSESSID` 凭证。全局邮件通知基于 `lang.php` 提供中英文支持。
