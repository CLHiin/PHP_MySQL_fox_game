<?php
require_once '../includes/db.php';
require_once '../config/config.php';
;
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];

// --- 1. 處理購買與抽卡請求 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_item') {
    $itemName = $_POST['item_name'];
    $qty = max(1, intval($_POST['quantity']));

    if (!isset($fox_items[$itemName])) {
        echo json_encode(['status' => 'error', 'message' => '道具不存在']);
        exit;
    }

    $item = $fox_items[$itemName];
    $stmt = $pdo->prepare("SELECT merit FROM players WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $merit = $stmt->fetchColumn();
    $totalPrice = $item['price'] * $qty;

    if ($merit < $totalPrice) {
        echo json_encode(['status' => 'error', 'message' => '功德不足！']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $gachaResults = [];
        
        if ($itemName === '神秘靈狐') {
            // 權重配置: 普通(30%*3=90%), 特殊(5%*2=10%)
            $pool = [
                'fire' => 30, 'water' => 30, 'wind' => 30,
                'light' => 5,  'dark' => 5
            ];

            for ($i = 0; $i < $qty; $i++) {
                $rand = random_int(1, 100);
                $currentWeight = 0;
                foreach ($pool as $key => $weight) {
                    $currentWeight += $weight;
                    if ($rand <= $currentWeight) {
                        $foxName = $fox_templates[$key]['name']; 
                        $stmt = $pdo->prepare("INSERT INTO foxes (player_id, type) VALUES (?, ?)");
                        $stmt->execute([$user_id, $key]);
                        $gachaResults[] = $foxName; // 將名稱存入結果給前端顯示
                        break;
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO player_inventory (player_id, item_name, quantity) VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
            $stmt->execute([$user_id, $itemName, $qty]);
        }

        $pdo->prepare("UPDATE players SET merit = merit - ? WHERE id = ?")->execute([$totalPrice, $user_id]);
        $pdo->commit();

        echo json_encode([
            'status' => 'success', 
            'message' => "交易成功！",
            'gacha_results' => $gachaResults,
            'details' => ['item' => $itemName, 'qty' => $qty, 'total' => $totalPrice, 'remaining' => $merit - $totalPrice]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => '交易失敗，請稍後重試。']);
    }
    exit;
}

// --- 2. 頁面載入邏輯 ---
$stmt = $pdo->prepare("SELECT name, merit FROM players WHERE id = ?");
$stmt->execute([$user_id]);
$player = $stmt->fetch();

$stmt = $pdo->prepare("SELECT item_name, quantity FROM player_inventory WHERE player_id = ?");
$stmt->execute([$user_id]);
$inventory = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <title>靈狐商店</title>
</head>
<body>
<div class="main-container">
    <header class="header-top">
        <div class="text-h1">🛒 靈狐商店</div>
        <div class="text-gold">當前功德: <span id="merit-display"><?= $player['merit'] ?></span></div>
    </header>

    <div class="modal-scroll">
        <?php foreach($game_manual['item_list']['groups'] as $groupName => $items): ?>
            <div class="group-title" style="margin-top: 15px;"><?= $groupName ?></div>
            <div class="item-grid">
                <?php foreach($items as $name): 
                    $item = $fox_items[$name];
                    $qty = $inventory[$name] ?? 0;
                    $colorClass = str_contains($item['type'], 'exp') ? 'text-green' : 'text-gold';
                ?>
                <div class="item-card">
                    <div class="flex-between">
                        <div class="<?= $colorClass ?> text-bold">
                            <span class="text-small">[<?= strtoupper(explode('_', $item['type'])[0]) ?>]</span> <?= $name ?>
                        </div>
                        <div class="text-small">擁有: <span class="text-white"><?= $qty ?></span></div>
                    </div>
                    <div class="text-small" style="color:#aaa; margin: 4px 0;"><?= $item['desc'] ?></div>
                    <div class="buy-control">
                        <input type="number" class="qty-input" id="qty_<?= str_replace(' ', '_', $name) ?>" value="1" min="1" max="99">
                        <button class="btn-base btn-main buy-btn" onclick="buyItem('<?= $name ?>')">
                            購買 (<?= $item['price'] ?> 功德)
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <button class="btn-base btn-info" onclick="window.location.href='dashboard.php'">返回領地</button>
</div>

<script>
function buyItem(itemName) {
    const inputId = 'qty_' + itemName.replace(/\s+/g, '_');
    const quantity = document.getElementById(inputId).value;
    if(!confirm(`確定要購買 ${quantity} 個 ${itemName} 嗎？`)) return;

    fetch('shop.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=buy_item&item_name=${encodeURIComponent(itemName)}&quantity=${quantity}`
    })
    .then(res => res.json())
    .then(res => {
        if(res.status === 'success') {
            const d = res.details;
            let displayMsg = `✅ 交易成功！\n-----------------------\n項目: ${d.item} x${d.qty}\n總價: ${d.total} 功德\n剩餘: ${d.remaining} 功德`;
            if (res.gacha_results && res.gacha_results.length > 0) {
                displayMsg = `✨ 祈願結果 ✨\n-----------------------\n恭喜獲得：${res.gacha_results.join(', ')}\n\n剩餘功德: ${d.remaining}`;
            }
            alert(displayMsg);
            location.reload();
        } else {
            alert(`❌ ${res.message}`);
        }
    });
}
</script>
</body>
</html>