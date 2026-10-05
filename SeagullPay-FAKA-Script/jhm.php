<?php
session_start();

// ====== 配置区 ======
$admin_password = 'admin'; // 默认管理密码，请自行修改
$webhook_secret = 'wh'; // 【安全注意】Webhook密钥！防止他人伪造支付成功请求，请务必修改！
$db_file = __DIR__ . '/faka.db';
$api_url = 'http://127.0.0.1:54321/api/create_link'; // 1.py的API地址


// ====== 数据库初始化 ======
$db = new PDO('sqlite:' . $db_file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)");
$db->exec("CREATE TABLE IF NOT EXISTS cdks (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, code TEXT, is_used INTEGER DEFAULT 0, order_id INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT, product_id INTEGER, amount REAL, status INTEGER DEFAULT 0, contact TEXT, code TEXT, cdk TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$action = $_GET['action'] ?? 'home';
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

function json_resp($status, $msg = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'msg' => $msg, 'data' => $data]);
    exit;
}

// ====== 频率限制防刷机制 ======
$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (ip TEXT, action TEXT, count INTEGER, reset_time INTEGER, PRIMARY KEY (ip, action))");
function get_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}
function enforce_rate_limit($db, $action, $limit, $window) {
    global $is_ajax;
    $ip = get_client_ip();
    $stmt = $db->prepare("SELECT count, reset_time FROM rate_limits WHERE ip = ? AND action = ?");
    $stmt->execute([$ip, $action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $now = time();
    
    if ($row) {
        if ($now > $row['reset_time']) {
            $db->prepare("UPDATE rate_limits SET count = 1, reset_time = ? WHERE ip = ? AND action = ?")->execute([$now + $window, $ip, $action]);
        } else {
            if ($row['count'] >= $limit) {
                if ($is_ajax) json_resp('error', '操作过于频繁，请稍后再试 (触发风控)');
                else die('操作过于频繁，请稍后再试 (触发风控)');
            }
            $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE ip = ? AND action = ?")->execute([$ip, $action]);
        }
    } else {
        $db->prepare("INSERT INTO rate_limits (ip, action, count, reset_time) VALUES (?, ?, 1, ?)")->execute([$ip, $action, $now + $window]);
    }
}

// 全局频率限制：单 IP 每分钟最多 120 次请求
enforce_rate_limit($db, 'global', 120, 60);


// ====== Webhook 回调接口 (供1.py调用) ======
if ($action === 'webhook') {
    if (($_GET['secret'] ?? '') !== $webhook_secret) die('invalid secret');
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (is_array($data) && isset($data['note'])) {
        $note = $data['note'];
        $stmt = $db->prepare("SELECT * FROM orders WHERE note = ? AND status = 0");
        $stmt->execute([$note]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT status FROM orders WHERE id = ?");
                $stmt->execute([$order['id']]);
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $db->prepare("SELECT * FROM cdks WHERE product_id = ? AND is_used = 0 LIMIT 1");
                    $stmt->execute([$order['product_id']]);
                    $cdk = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $cdk_code = '库存不足，请凭订单号联系客服补发';
                    if ($cdk) {
                        $cdk_code = $cdk['code'];
                        $db->prepare("UPDATE cdks SET is_used = 1, order_id = ? WHERE id = ?")->execute([$order['id'], $cdk['id']]);
                    }
                    
                    $db->prepare("UPDATE orders SET status = 1, cdk = ? WHERE id = ?")->execute([$cdk_code, $order['id']]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
            }
        }
        echo 'ok';
    } else {
        echo 'invalid request';
    }
    exit;
}

// ====== 检查订单状态 (Ajax前端轮询使用) ======
if ($action === 'api_check_status') {
    enforce_rate_limit($db, 'api_check_status', 30, 60); // 状态轮询限制每分钟 30 次
    header('Content-Type: application/json');
    $note = $_GET['note'] ?? '';
    $stmt = $db->prepare("SELECT status, cdk FROM orders WHERE note = ?");
    $stmt->execute([$note]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($order ?: ['status' => 0]);
    exit;
}

// ====== 创建购买订单 ======
if ($action === 'buy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    enforce_rate_limit($db, 'buy', 5, 60); // 下单接口限制单IP每分钟5次
    $product_id = $_POST['product_id'] ?? 0;
    $contact = $_POST['contact'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) { $is_ajax ? json_resp('error', '商品不存在') : die("商品不存在"); }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM cdks WHERE product_id = ? AND is_used = 0");
    $stmt->execute([$product_id]);
    if ($stmt->fetchColumn() <= 0) { $is_ajax ? json_resp('error', '库存不足，请等待补货') : die("库存不足，请等待补货"); }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . "?amount=" . $product['price']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $res = json_decode($response, true);
    if ($res && $res['status'] === 'success') {
        $note = $res['data']['note'];
        $code = $res['data']['code'];
        
        $stmt = $db->prepare("INSERT INTO orders (note, product_id, amount, contact, code) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$note, $product_id, $product['price'], $contact, $code]);
        
        if ($is_ajax) json_resp('success', '下单成功！即将跳转支付', ['redirect' => '?action=checkout&note=' . $note]);
        else { header("Location: ?action=checkout&note=" . $note); exit; }
    } else {
        if ($is_ajax) json_resp('error', '创建支付链接失败，请检查 1.py 支付系统是否运行中。');
        else die("创建支付链接失败。");
    }
}

// ====== 管理后台逻辑 ======
if (strpos($action, 'admin') === 0) {
    if ($action === 'admin_login') {
        enforce_rate_limit($db, 'admin_login', 5, 60); // 防止密码暴力破解
        if (($_POST['password'] ?? '') === $admin_password) {
            $_SESSION['admin'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
            if ($is_ajax) json_resp('success', '登录成功', ['redirect' => '?action=admin']);
            else { header("Location: ?action=admin"); exit; }
        } else {
            if ($is_ajax) json_resp('error', '密码错误');
            else die("密码错误 <a href='?action=admin'>返回</a>");
        }
        exit;
    }
    if (empty($_SESSION['admin'])) {
        $action = 'admin_login_page';
    } else {
        if ($action === 'admin_logout') {
            unset($_SESSION['admin']);
            if ($is_ajax) json_resp('success', '已退出', ['redirect' => '?action=admin']);
            else { header("Location: ?action=admin"); exit; }
        }
        if ($action === 'admin_add_product') {
            if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) { $is_ajax ? json_resp('error', '非法请求') : die("非法请求"); }
            $name = $_POST['name'] ?? '';
            $price = floatval($_POST['price'] ?? 0);
            if ($name && $price > 0) {
                $db->prepare("INSERT INTO products (name, price) VALUES (?, ?)")->execute([$name, $price]);
            }
            if ($is_ajax) json_resp('success', '添加成功', ['redirect' => '?action=admin']);
            else { header("Location: ?action=admin"); exit; }
        }
        if ($action === 'admin_del_product') {
            if (($_GET['csrf_token'] ?? '') !== $_SESSION['csrf_token']) { $is_ajax ? json_resp('error', '非法请求') : die("非法请求"); }
            $id = $_GET['id'] ?? 0;
            $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM cdks WHERE product_id = ?")->execute([$id]);
            if ($is_ajax) json_resp('success', '删除成功', ['redirect' => '?action=admin']);
            else { header("Location: ?action=admin"); exit; }
        }
        if ($action === 'admin_add_cdk') {
            if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) { $is_ajax ? json_resp('error', '非法请求') : die("非法请求"); }
            $product_id = $_POST['product_id'] ?? 0;
            $cdks = explode("\n", $_POST['cdks'] ?? '');
            $stmt = $db->prepare("INSERT INTO cdks (product_id, code) VALUES (?, ?)");
            $count = 0;
            foreach ($cdks as $code) {
                $code = trim($code);
                if ($code) { $stmt->execute([$product_id, $code]); $count++; }
            }
            if ($is_ajax) json_resp('success', "成功导入 {$count} 个卡密", ['redirect' => '?action=admin&view=cdks&product_id=' . $product_id]);
            else { header("Location: ?action=admin&view=cdks&product_id=" . $product_id); exit; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>鸥卡 - 极简自动发卡系统</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.4); }
        /* 进度条动画 */
        #nprogress { pointer-events: none; }
        #nprogress .bar { background: #3b82f6; position: fixed; z-index: 1031; top: 0; left: 0; width: 100%; height: 3px; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center p-4 sm:p-10 text-gray-800">

<div id="nprogress" style="display:none;"><div class="bar"></div></div>

<div class="w-full max-w-5xl">
    <!-- Header -->
    <header class="flex justify-between items-center mb-10 pl-2 pr-2">
        <h1 class="text-3xl font-extrabold tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600">
            <a href="?action=home" data-pjax>鸥卡平台</a>
        </h1>
        <nav class="space-x-6">
            <a href="?action=home" class="hover:text-blue-600 font-semibold transition" data-pjax>购买商品</a>
            <a href="?action=query" class="hover:text-blue-600 font-semibold transition" data-pjax>订单查询</a>
        </nav>
    </header>

    <main class="w-full" id="pjax-container">
    <?php if ($action === 'home'): ?>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-8 fade-in">
            <div class="md:col-span-3 glass p-8 sm:p-10 rounded-3xl shadow-xl shadow-blue-900/5">
                <h2 class="text-2xl font-bold mb-8 text-gray-900 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                    选购商品
                </h2>
                <form action="?action=buy" method="POST" class="space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">商品列表</label>
                        <select name="product_id" required class="w-full p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition shadow-sm text-gray-800 font-medium appearance-none">
                            <option value="">请选择商品...</option>
                            <?php
                            $products = $db->query("SELECT p.*, (SELECT COUNT(*) FROM cdks WHERE product_id = p.id AND is_used = 0) as stock FROM products p")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($products as $p):
                            ?>
                            <option value="<?= $p['id'] ?>" <?= $p['stock']<=0?'disabled':'' ?>>
                                <?= htmlspecialchars($p['name']) ?> - ￥<?= number_format($p['price'], 2) ?> (库存: <?= $p['stock'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">联系方式 <span class="text-xs text-gray-400 font-normal">(用于付款后找回订单)</span></label>
                        <input type="email" name="contact" required placeholder="请输入您的常用邮箱" class="w-full p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition shadow-sm">
                    </div>
                    <div class="pt-4">
                        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-4 rounded-2xl shadow-lg shadow-blue-500/30 transform transition hover:-translate-y-1">
                            去结算
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="md:col-span-2 flex flex-col justify-center space-y-6">
                <div class="bg-blue-50/80 backdrop-blur text-blue-900 p-6 rounded-3xl shadow-sm border border-blue-100">
                    <h3 class="font-bold text-lg mb-2 flex items-center"><span class="text-xl mr-2">⚡</span> 极速发货</h3>
                    <p class="text-sm opacity-90 leading-relaxed">系统接入 SeagullPay 自动化回调。付款成功后秒级验证，页面自动展示卡密。</p>
                </div>
                <div class="bg-green-50/80 backdrop-blur text-green-900 p-6 rounded-3xl shadow-sm border border-green-100">
                    <h3 class="font-bold text-lg mb-2 flex items-center"><span class="text-xl mr-2">🛡️</span> 资金安全</h3>
                    <p class="text-sm opacity-90 leading-relaxed">提供金额校验与自动退款兜底策略，遇到任何转账金额不符、重复打款均会自动原路退回，保障权益。</p>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'checkout'): 
        $note = $_GET['note'] ?? '';
        $stmt = $db->prepare("SELECT o.*, p.name FROM orders o JOIN products p ON o.product_id = p.id WHERE o.note = ?");
        $stmt->execute([$note]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) die("订单不存在");
        $pay_url = "https://seagull.teft.cn/pay.php?id=" . $order['code'];
    ?>
        <div class="glass max-w-2xl mx-auto p-10 rounded-3xl shadow-xl shadow-blue-900/5 text-center fade-in">
            <h2 class="text-3xl font-extrabold mb-8 text-gray-900">支付订单</h2>
            
            <div class="bg-white rounded-2xl p-8 mb-8 text-left border border-gray-100 shadow-sm">
                <p class="mb-4 text-gray-600 flex justify-between items-center"><span class="font-semibold">订单编号：</span> <span class="font-mono text-gray-800 bg-gray-100 px-3 py-1 rounded-lg"><?= htmlspecialchars($order['note']) ?></span></p>
                <p class="mb-4 text-gray-600 flex justify-between items-center"><span class="font-semibold">商品名称：</span> <span class="font-medium text-gray-800"><?= htmlspecialchars($order['name']) ?></span></p>
                <p class="mb-4 text-gray-600 flex justify-between items-center"><span class="font-semibold">联系方式：</span> <span class="font-medium text-gray-800"><?= htmlspecialchars($order['contact']) ?></span></p>
                <div class="w-full h-px bg-gray-100 my-4"></div>
                <p class="text-gray-600 flex justify-between items-center text-lg"><span class="font-semibold">支付金额：</span> <span class="font-bold text-red-500 text-2xl">￥<?= number_format($order['amount'], 2) ?></span></p>
            </div>
            
            <div id="status-container">
                <?php if($order['status'] == 1): ?>
                    <div class="bg-green-50 text-green-800 p-8 rounded-2xl mb-6 border border-green-100">
                        <div class="flex justify-center mb-4 text-green-500">
                            <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-4">支付成功</h3>
                        <p class="mb-3 text-sm font-semibold">您的激活码/卡密如下：</p>
                        <div class="font-mono text-xl bg-white p-5 rounded-xl border border-green-200 shadow-sm select-all text-center"><?= htmlspecialchars($order['cdk']) ?></div>
                    </div>
                <?php else: ?>
                    <a href="<?= $pay_url ?>" target="_blank" class="inline-block w-full px-10 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold rounded-2xl shadow-lg shadow-blue-500/30 transform transition hover:-translate-y-1 mb-8 text-lg">
                        前往收银台支付
                    </a>
                    
                    <div class="flex items-center justify-center text-blue-600 bg-blue-50 py-3 px-6 rounded-full inline-flex font-medium">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        正在等待支付... (付款后自动刷新)
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if($order['status'] == 0): ?>
        <script>
            // 确保只有一个轮询在运行
            if (window.checkInterval) clearInterval(window.checkInterval);
            window.checkInterval = setInterval(() => {
                fetch('?action=api_check_status&note=<?= $order['note'] ?>')
                .then(res => res.json())
                .then(data => {
                    if(data.status == 1) {
                        clearInterval(window.checkInterval);
                        document.getElementById('status-container').innerHTML = `
                            <div class="bg-green-50 text-green-800 p-8 rounded-2xl mb-6 border border-green-100 transform transition-all scale-105 duration-500">
                                <div class="flex justify-center mb-4 text-green-500">
                                    <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <h3 class="text-2xl font-bold mb-4">支付成功</h3>
                                <p class="mb-3 text-sm font-semibold">您的激活码/卡密如下：</p>
                                <div class="font-mono text-xl bg-white p-5 rounded-xl border border-green-200 shadow-sm select-all text-center">${data.cdk}</div>
                            </div>
                        `;
                    }
                });
            }, 3000);
        </script>
        <?php endif; ?>

    <?php elseif ($action === 'query'): ?>
        <div class="glass max-w-2xl mx-auto p-10 rounded-3xl shadow-xl shadow-blue-900/5 fade-in">
            <h2 class="text-2xl font-bold mb-8 text-gray-900 text-center">查询历史订单</h2>
            <form action="?action=query" method="POST" class="space-y-4 mb-10">
                <div class="relative">
                    <input type="text" name="search" required placeholder="请输入您的邮箱或16位订单号" value="<?= htmlspecialchars($_POST['search'] ?? '') ?>" class="w-full p-4 pl-12 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition shadow-sm">
                    <svg class="w-6 h-6 text-gray-400 absolute left-4 top-1/2 transform -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg transition">立即查询</button>
            </form>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['search'])) {
                enforce_rate_limit($db, 'query', 15, 60); // 查询接口限制每分钟15次
                $search = $_POST['search'];
                $stmt = $db->prepare("SELECT o.*, p.name FROM orders o JOIN products p ON o.product_id = p.id WHERE o.contact = ? OR o.note = ? ORDER BY o.id DESC");
                $stmt->execute([$search, $search]);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($orders)): ?>
                    <div class="text-center bg-gray-50 p-8 rounded-2xl border border-gray-100">
                        <p class="text-gray-500">找不到相关订单记录</p>
                    </div>
                <?php else: 
                    foreach($orders as $o): ?>
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 mb-6 hover:shadow-md transition">
                        <div class="flex justify-between items-center mb-4">
                            <span class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($o['name']) ?></span>
                            <?php if($o['status'] == 1): ?>
                                <span class="px-3 py-1 bg-green-100 text-green-700 text-xs font-bold rounded-full">已支付</span>
                            <?php else: ?>
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-700 text-xs font-bold rounded-full">未支付</span>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mb-4 text-sm text-gray-600">
                            <div><span class="font-semibold">订单编号:</span> <span class="font-mono"><?= $search === $o['note'] ? htmlspecialchars($o['note']) : '****** (安全隐藏)' ?></span></div>
                            <div class="text-right"><span class="font-semibold">创建时间:</span> <?= $o['created_at'] ?></div>
                            <div><span class="font-semibold">付款金额:</span> <span class="text-red-500 font-bold">￥<?= number_format($o['amount'],2) ?></span></div>
                        </div>
                        <div class="w-full h-px bg-gray-100 my-4"></div>
                        <?php if($o['status'] == 1): ?>
                            <div class="p-4 bg-gray-50 border border-gray-200 rounded-xl text-sm font-mono break-all select-all text-gray-800">
                                <?php 
                                if ($search === $o['note']) {
                                    echo htmlspecialchars($o['cdk']);
                                } else {
                                    $cdk_str = $o['cdk'];
                                    $len = mb_strlen($cdk_str, 'UTF-8');
                                    if ($len > 6) {
                                        echo htmlspecialchars(mb_substr($cdk_str, 0, 3, 'UTF-8') . '******' . mb_substr($cdk_str, -3, NULL, 'UTF-8'));
                                    } else {
                                        echo '******';
                                    }
                                    echo '<span class="text-xs text-red-500 ml-2 block mt-1 select-none">⚠️ 出于安全保护，通过邮箱查询仅显示部分卡密。请使用 16 位订单号查询以查看完整卡密。</span>';
                                }
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="text-right">
                                <a href="?action=checkout&note=<?= $o['note'] ?>" data-pjax class="text-sm font-bold text-blue-600 hover:text-blue-800 bg-blue-50 px-4 py-2 rounded-lg transition">继续支付 &rarr;</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach;
                endif;
            }
            ?>
        </div>
        
    <?php elseif ($action === 'admin_login_page'): ?>
        <div class="glass max-w-md mx-auto p-10 rounded-3xl shadow-xl mt-12 fade-in">
            <h2 class="text-3xl font-bold mb-8 text-gray-900 text-center">系统管理</h2>
            <form action="?action=admin_login" method="POST" class="space-y-6">
                <div>
                    <input type="password" name="password" required placeholder="请输入管理员密码" class="w-full p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition shadow-sm text-center tracking-widest text-lg">
                </div>
                <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg transition">进入后台</button>
            </form>
        </div>

    <?php elseif (strpos($action, 'admin') === 0): ?>
        <!-- Admin Dashboard -->
        <div class="w-full fade-in">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-3xl font-bold text-gray-900">控制面板</h2>
                <a href="?action=admin_logout" data-pjax class="text-sm text-red-600 hover:text-white hover:bg-red-600 font-bold border border-red-200 bg-white px-5 py-2.5 rounded-xl transition shadow-sm">退出登录</a>
            </div>

            <?php if(empty($_GET['view'])): ?>
            <!-- Products Management -->
            <div class="glass p-8 rounded-3xl shadow-xl shadow-blue-900/5 mb-8">
                <h3 class="text-xl font-bold mb-6 text-gray-800 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                    发布新商品
                </h3>
                <form action="?action=admin_add_product" method="POST" class="flex flex-col sm:flex-row gap-4 mb-8">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="text" name="name" required placeholder="填写商品名称" class="flex-1 p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 outline-none shadow-sm">
                    <input type="number" step="0.01" name="price" required placeholder="价格 (元)" class="w-full sm:w-48 p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 outline-none shadow-sm font-mono">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-2xl font-bold transition shadow-lg shadow-blue-500/30">确认添加</button>
                </form>

                <h3 class="text-xl font-bold mb-6 text-gray-800">商品库存管理</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse bg-white rounded-2xl overflow-hidden shadow-sm border border-gray-100">
                        <thead>
                            <tr class="bg-gray-50 text-gray-600 text-sm border-b border-gray-100">
                                <th class="p-5 font-semibold">ID</th>
                                <th class="p-5 font-semibold">商品名称</th>
                                <th class="p-5 font-semibold">价格</th>
                                <th class="p-5 font-semibold">余量库存</th>
                                <th class="p-5 font-semibold">累计售出</th>
                                <th class="p-5 font-semibold">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php
                            $products = $db->query("SELECT p.*, 
                                (SELECT COUNT(*) FROM cdks WHERE product_id = p.id) as total_cdks,
                                (SELECT COUNT(*) FROM cdks WHERE product_id = p.id AND is_used = 1) as sold_cdks
                                FROM products p ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($products as $p):
                            ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="p-5 text-gray-400 font-mono">#<?= $p['id'] ?></td>
                                <td class="p-5 font-bold text-gray-800"><?= htmlspecialchars($p['name']) ?></td>
                                <td class="p-5 text-red-500 font-bold font-mono">￥<?= number_format($p['price'], 2) ?></td>
                                <td class="p-5">
                                    <span class="px-3 py-1 <?= ($p['total_cdks'] - $p['sold_cdks']) > 0 ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700' ?> rounded-lg text-sm font-bold">
                                        <?= $p['total_cdks'] - $p['sold_cdks'] ?> 个
                                    </span>
                                </td>
                                <td class="p-5 text-gray-500 font-medium"><?= $p['sold_cdks'] ?></td>
                                <td class="p-5 space-x-4">
                                    <a href="?action=admin&view=cdks&product_id=<?= $p['id'] ?>" data-pjax class="text-blue-600 hover:text-blue-800 font-bold text-sm bg-blue-50 px-3 py-1.5 rounded-lg transition">补充卡密</a>
                                    <a href="?action=admin_del_product&id=<?= $p['id'] ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" data-confirm="确定删除该商品及其所有库存卡密吗？此操作不可逆！" class="text-red-500 hover:text-red-700 font-bold text-sm bg-red-50 px-3 py-1.5 rounded-lg transition">删除</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="glass p-8 rounded-3xl shadow-xl shadow-blue-900/5">
                <h3 class="text-xl font-bold mb-6 text-gray-800">最新流水的订单 (最近20笔)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse bg-white rounded-2xl overflow-hidden shadow-sm border border-gray-100 text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-gray-600 border-b border-gray-100">
                                <th class="p-5 font-semibold">系统订单号</th>
                                <th class="p-5 font-semibold">购买商品</th>
                                <th class="p-5 font-semibold">金额</th>
                                <th class="p-5 font-semibold">留存联系方式</th>
                                <th class="p-5 font-semibold">支付状态</th>
                                <th class="p-5 font-semibold">下单时间</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php
                            $orders = $db->query("SELECT o.*, p.name FROM orders o LEFT JOIN products p ON o.product_id = p.id ORDER BY o.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($orders as $o):
                            ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="p-5 font-mono text-gray-500"><?= $o['note'] ?></td>
                                <td class="p-5 font-bold text-gray-800"><?= htmlspecialchars($o['name'] ?? '已删除失效商品') ?></td>
                                <td class="p-5 text-red-500 font-bold font-mono">￥<?= number_format($o['amount'], 2) ?></td>
                                <td class="p-5 text-gray-600"><?= htmlspecialchars($o['contact']) ?></td>
                                <td class="p-5">
                                    <?php if($o['status']==1): ?>
                                        <span class="text-green-700 bg-green-100 px-2 py-1 rounded font-bold text-xs">付款成功</span>
                                    <?php else: ?>
                                        <span class="text-yellow-700 bg-yellow-100 px-2 py-1 rounded font-bold text-xs">等待付款</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-5 text-gray-500 text-xs"><?= $o['created_at'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif($_GET['view'] === 'cdks'): 
                $pid = $_GET['product_id'] ?? 0;
                $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
                $stmt->execute([$pid]);
                $product = $stmt->fetch();
                if(!$product) die("商品不存在");
            ?>
            <div class="glass p-10 rounded-3xl shadow-xl shadow-blue-900/5">
                <div class="flex items-center mb-8">
                    <a href="?action=admin" data-pjax class="text-gray-400 hover:text-gray-900 bg-white border border-gray-200 p-2 rounded-xl mr-4 shadow-sm transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    </a>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900">库存卡密管理</h3>
                        <p class="text-gray-500 mt-1">当前商品：<span class="font-bold text-blue-600"><?= htmlspecialchars($product['name']) ?></span></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-10">
                    <div class="md:col-span-1">
                        <h4 class="font-bold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            批量导入卡密
                        </h4>
                        <form action="?action=admin_add_cdk" method="POST">
                            <input type="hidden" name="product_id" value="<?= $pid ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <textarea name="cdks" rows="12" required placeholder="一行一条卡密，支持任何文本格式..." class="w-full p-4 bg-white border border-gray-200 rounded-2xl focus:ring-4 focus:ring-blue-500/20 outline-none mb-4 shadow-sm text-sm font-mono leading-relaxed"></textarea>
                            <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg transition">确认写入库存</button>
                        </form>
                    </div>
                    <div class="md:col-span-2">
                        <h4 class="font-bold text-gray-800 mb-4">卡密明细列表 <span class="text-gray-400 font-normal text-sm">(显示最近100条)</span></h4>
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden max-h-[450px] overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 sticky top-0 border-b border-gray-100 shadow-sm">
                                    <tr>
                                        <th class="p-4 font-semibold text-gray-600">卡密内容</th>
                                        <th class="p-4 font-semibold text-gray-600 w-32">当前状态</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <?php
                                    $stmt = $db->prepare("SELECT * FROM cdks WHERE product_id = ? ORDER BY id DESC LIMIT 100");
                                    $stmt->execute([$pid]);
                                    $cdks = $stmt->fetchAll();
                                    foreach($cdks as $c):
                                    ?>
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="p-4 font-mono text-gray-700 break-all select-all"><?= htmlspecialchars($c['code']) ?></td>
                                        <td class="p-4">
                                            <?php if($c['is_used']): ?>
                                                <span class="text-xs bg-red-100 text-red-700 px-3 py-1.5 rounded-lg font-bold">已售出</span>
                                            <?php else: ?>
                                                <span class="text-xs bg-green-100 text-green-700 px-3 py-1.5 rounded-lg font-bold">待出售</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    <?php endif; ?>
    </main>
    
    <footer class="mt-20 text-center text-sm text-gray-400 pb-10">
        <p>&copy; <?= date('Y') ?> 鸥卡系统 | 单文件极致体验</p>
        <p class="mt-2 text-xs opacity-70">Powered by SeagullPay Payment WebHook</p>
    </footer>
</div>

<script>
    // 全局 AJAX 无刷新逻辑框架
    function showProgress() { document.getElementById('nprogress').style.display = 'block'; }
    function hideProgress() { document.getElementById('nprogress').style.display = 'none'; }

    async function pjaxLoad(url, pushState = true) {
        showProgress();
        try {
            const res = await fetch(url, { headers: {'X-Requested-With': 'XMLHttpRequest'} });
            const html = await res.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newMain = doc.querySelector('main');
            if (newMain) {
                if (window.checkInterval) clearInterval(window.checkInterval);
                document.querySelector('main').innerHTML = newMain.innerHTML;
                if (pushState) history.pushState({url}, '', url);
                bindEvents();
                // 执行内部脚本
                newMain.querySelectorAll('script').forEach(s => {
                    const script = document.createElement('script');
                    script.textContent = s.textContent;
                    document.body.appendChild(script);
                    document.body.removeChild(script);
                });
            }
        } catch (e) {
            Swal.fire('Error', '页面加载失败，请检查网络连接', 'error');
        } finally {
            hideProgress();
        }
    }

    async function handleAjaxResponse(res, submitBtn, originalText) {
        const contentType = res.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            const data = await res.json();
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
            if (data.status === 'success') {
                Swal.fire({icon: 'success', title: data.msg, timer: 1000, showConfirmButton: false});
                if (data.data && data.data.redirect) {
                    setTimeout(() => pjaxLoad(data.data.redirect), 600);
                }
            } else {
                Swal.fire({icon: 'error', title: '操作失败', text: data.msg});
            }
        } else {
            // HTML response (查询页面等直接返回结果)
            const html = await res.text();
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            if (window.checkInterval) clearInterval(window.checkInterval);
            document.querySelector('main').innerHTML = doc.querySelector('main').innerHTML;
            bindEvents();
        }
        hideProgress();
    }

    function bindEvents() {
        // 接管所有表单提交
        document.querySelectorAll('form:not([data-bound])').forEach(form => {
            form.setAttribute('data-bound', '1');
            form.addEventListener('submit', async e => {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '处理中...'; }
                showProgress();
                
                try {
                    const res = await fetch(form.action || location.href, {
                        method: form.method || 'POST',
                        body: new FormData(form),
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    });
                    await handleAjaxResponse(res, submitBtn, originalText);
                } catch (err) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
                    hideProgress();
                    Swal.fire('Error', '网络请求异常', 'error');
                }
            });
        });

        // 接管所有站内跳转链接与带确认框的操作
        document.querySelectorAll('a[href^="?action="]:not([data-bound]), a[data-pjax]:not([data-bound])').forEach(a => {
            a.setAttribute('data-bound', '1');
            a.addEventListener('click', e => {
                e.preventDefault();
                const url = a.getAttribute('href');
                const confirmMsg = a.getAttribute('data-confirm');
                
                if (confirmMsg) {
                    Swal.fire({
                        title: '请确认',
                        text: confirmMsg,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: '确定执行',
                        cancelButtonText: '取消'
                    }).then(async (result) => {
                        if (result.isConfirmed) {
                            showProgress();
                            try {
                                const res = await fetch(url, { headers: {'X-Requested-With': 'XMLHttpRequest'} });
                                await handleAjaxResponse(res, null, null);
                            } catch (err) {
                                hideProgress();
                                Swal.fire('Error', '请求失败', 'error');
                            }
                        }
                    });
                } else {
                    pjaxLoad(url);
                }
            });
        });
    }

    window.addEventListener('popstate', e => {
        if (e.state && e.state.url) pjaxLoad(e.state.url, false);
    });

    document.addEventListener('DOMContentLoaded', () => {
        history.replaceState({url: location.href}, '', location.href);
        bindEvents();
    });
</script>
</body>
</html>
