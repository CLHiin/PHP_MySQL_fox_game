<?php
// 確保啟動了 Session 才能銷毀它
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. 清空 $_SESSION 陣列
$_SESSION = [];

// 2. 清除 Session Cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000, 
        $params["path"], 
        $params["domain"], 
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. 銷毀伺服器端 Session
session_destroy();

// 4. 強制跳轉並停止
header("Location: login.php");
exit;