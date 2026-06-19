<?php
// require_once __DIR__ . '/../includes/db.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
// 1. 若已登入則直接導向後台
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }

$message = "";
$mode = $_GET['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // --- 登入邏輯 ---
    if (isset($_POST['action_login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT id, password_hash, is_admin FROM players WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = $user['is_admin'] ?? 0;
            $pdo->prepare("UPDATE players SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            header("Location: dashboard.php");
            exit;
        } else {
            $message = "⚠️ 帳號或密碼錯誤！";
        }
    }

    // --- 註冊邏輯 ---
    elseif (isset($_POST['action_register'])) {
        $name = trim($_POST['name']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 10) {
            $message = "⚠️ 暱稱長度需為 2-10 個字元！";
            $mode = 'register';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $types = ['fire', 'water', 'wind'];
            $random_type = $types[array_rand($types)];
            
            try {
                $pdo->beginTransaction();
                
                // 1. 新增玩家
                $stmt = $pdo->prepare("INSERT INTO players (username, password_hash, name, merit) VALUES (?, ?, ?, 1000)");
                $stmt->execute([$username, $password_hash, $name]);
                $uid = $pdo->lastInsertId();
                
                // 2. 新增隨機狐狸
                $stmt_fox = $pdo->prepare("INSERT INTO foxes (player_id, type, level, exp) VALUES (?, ?, 1, 0)");
                $stmt_fox->execute([$uid, $random_type]);
                $fox_id = $pdo->lastInsertId();
                
                // 3. 直接更新 players 表的助戰狐狸
                $pdo->prepare("UPDATE players SET support_fox = ? WHERE id = ?")->execute([$fox_id, $uid]);
                
                // 4. 新增初始道具
                $init_items = [['一階混沌精魄', 10], ['靈狐蜜餞', 10]];
                $stmt_inv = $pdo->prepare("INSERT INTO player_inventory (player_id, item_name, quantity) VALUES (?, ?, ?)");
                foreach ($init_items as $item) {
                    $stmt_inv->execute([$uid, $item[0], $item[1]]);
                }
                
                $pdo->commit();
                $message = "✅ 註冊成功！請登入您的靈狐系統。";
                $mode = 'login';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "❌ 註冊失敗，帳號可能已被使用。";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>靈狐系統 - 登入/註冊</title>
    <style>
        :root { --main: #e94560; --bg: #1a1a2e; --box: #16213e; --text: #fff; }
        body { font-family: 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: var(--bg); margin: 0; }
        .box { background: var(--box); padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 320px; text-align: center; color: var(--text); }
        h2 { margin-bottom: 25px; color: var(--main); }
        input { width: 100%; padding: 14px; margin: 8px 0; background: #0f3460; border: 1px solid #333; border-radius: 8px; color: white; box-sizing: border-box; }
        button { width: 100%; padding: 14px; margin-top: 15px; background: var(--main); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; }
        button:hover { background: #ff4d6d; transform: scale(1.02); }
        .msg { color: #ffd369; margin: 10px 0; font-size: 0.9em; }
        .tab { margin-bottom: 20px; }
        .tab a { margin: 0 15px; text-decoration: none; color: #4ecca3; font-weight: bold; opacity: 0.7; }
        .tab a.active { opacity: 1; border-bottom: 2px solid #4ecca3; }
    </style>
</head>
<body>
<div class="box">
    <h2>🦊 靈狐系統</h2>
    <div class="tab">
        <a href="?mode=login" class="<?= $mode=='login'?'active':'' ?>">登入</a>
        <a href="?mode=register" class="<?= $mode=='register'?'active':'' ?>">註冊</a>
    </div>
    <p class="msg"><?= $message; ?></p>
    <form method="POST">
        <?php if ($mode == 'register'): ?><input type="text" name="name" placeholder="暱稱" required><?php endif; ?>
        <input type="text" name="username" placeholder="帳號" required>
        <input type="password" name="password" placeholder="密碼" required>
        <button type="submit" name="action_<?= $mode ?>">確認 <?= $mode=='login'?'登入':'註冊' ?></button>
    </form>
</div>
</body>
</html>