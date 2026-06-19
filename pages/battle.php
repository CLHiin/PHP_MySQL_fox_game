<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/db_functions.php';
require_once '../config/config.php';

// 檢查是否有戰鬥數據
if (!isset($_SESSION['battle'])) {
    echo "<button onclick='window.location.href=`stage.php`'>返回冒險區</button><br>";
    die('這裡暫時沒有戰鬥喔～');
}
$battle = $_SESSION['battle'];
$enemy_team = $battle['enemy_team'];
$my_team = $battle['my_team'];
$helper = $battle['helper'];

// 2. 助戰覆蓋
if ($helper) $my_team["pos_" . $helper['pos']] = $helper['stats'];
// 3. 過濾並格式化陣容 (確保只有 pos_1~3 的陣列)
for ($i = 1; $i <= 3; $i++) {
    $pos = 'pos_' . $i;
    $enemy_team[$pos] = $enemy_team[$pos] ?? null;
    $my_team[$pos]    = $my_team[$pos] ?? null;
}
$enemy_team_F = $enemy_team;
$my_team_F = $my_team;

function simulateBattle(&$my_team, &$enemy_team) {
    $log = ['rounds' => [], 'result' => 'draw'];
    $max_rounds = 20;

    for ($round = 1; $round <= $max_rounds; $round++) {
        $round_actions = [];
        
        foreach (['pos_1', 'pos_2', 'pos_3'] as $pos) {
            // 己方行動
            executeAttack($my_team[$pos], $pos, $enemy_team, $round_actions, false);
            if (empty(array_filter($enemy_team, fn($u) => $u && $u['hp'] > 0))) {
                $log['result'] = '勝利';
                $log['rounds'][] = ['round' => $round, 'actions' => $round_actions];
                return $log;
            }

            // 敵方行動
            executeAttack($enemy_team[$pos], $pos, $my_team, $round_actions, true);
            if (empty(array_filter($my_team, fn($u) => $u && $u['hp'] > 0))) {
                $log['result'] = '失敗';
                $log['rounds'][] = ['round' => $round, 'actions' => $round_actions];
                return $log;
            }
        }
        $log['rounds'][] = ['round' => $round, 'actions' => $round_actions];
    }

    $log['result'] = '失敗'; // 超過20回合自動視為失敗
    return $log;
}
function executeAttack(&$attacker, $attacker_pos, &$defending_team, &$round_actions, $is_enemy = false) {
    if (!$attacker || $attacker['hp'] <= 0) return;

    $target_pos = getTarget($attacker_pos, $defending_team);
    if (!$target_pos) return;

    $target = &$defending_team[$target_pos];
    
    $multiplier = get_damage_multiplier($attacker['type'], $target['type']);
    $base_dmg = max(1, ($attacker['atk'] - $target['def']) );
    $dmg = (int)($base_dmg * $multiplier);
    
    $target['hp'] -= $dmg;
    $remaining_hp = max(0, $target['hp']);

    // 區分前綴
    $side = $is_enemy ? "敵方" : "己方";
    $round_actions[] = sprintf(
        "%s行動: %s [%s] 攻擊 %s [%s] 造成 %d 點傷害 (血量剩餘: %d) %s",
        $side,
        $attacker['name'], $attacker_pos, 
        $target['name'], $target_pos, 
        $dmg, $remaining_hp,
        ($multiplier > 1 
        ? "<span style='color:red;'>(屬性克制 x{$multiplier})</span>"
        : ($multiplier < 1 ? "<span style='color:lightblue;'>(屬性被剋 x{$multiplier})</span>" : ""))
    );
}
function getTarget($attacker_pos, $defending_team) {
    // 定義優先級順序
    $priorities = [
        'pos_1' => ['pos_1', 'pos_2', 'pos_3'],
        'pos_2' => ['pos_1', 'pos_2', 'pos_3'],
        'pos_3' => ['pos_1', 'pos_3', 'pos_2']
    ];

    foreach ($priorities[$attacker_pos] as $target_pos) {
        if (!empty($defending_team[$target_pos]) && $defending_team[$target_pos]['hp'] > 0) {
            return $target_pos;
        }
    }
    return null; // 全滅
}
// 執行模擬
$battle_data = simulateBattle($my_team, $enemy_team);
$result = $battle_data['result'];
// 替換掉原本的發獎呼叫
$actual_rewards = null;
if ($result === '勝利') {
    $actual_rewards = distributeRewards($_SESSION['user_id'], $battle['stage_id'], $battle['rewards'], $pdo);
    if ($actual_rewards == false) {
        die("發獎過程發生錯誤。");
    }
    
}

// echo "<h3>目前的結構：</h3>";
// echo "<pre>";
// print_r($actual_rewards);
// echo "</pre>";
// exit("除錯中斷：檢查完畢請刪除或註解此段代碼");
?>

<!DOCTYPE html>
<html>
<head>
    <title>戰鬥結算</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="main-container" style="max-width: 60rem; overflow-y: auto;">

    <div class="battle-prep-layout">
        <div>
            <div class="text-green">我方隊伍</div>
            <div class="squad-grid2-A">
                <?php foreach($my_team_F as $pos => $fox): ?>
                    <div class="squad-block <?= $pos == 'pos_1' ? 'squad-front' : '' ?>">
                    <?php if($fox):?>
                        <div><?= $fox['name'].' Lv.'.$fox['level'] ?></div>
                        <div class="text-gold">戰力：<?= number_format($fox['power']); ?></div>
                        <div><?= '❤️'.($fox['hp']??0).' | ⚔️'.($fox['atk']??0).' | 🛡️'.($fox['def']??0) ?></div>
                    <?php else: ?><div>無</div><div>對</div><div>象</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="flex-center"><div class="text-gold text-h2">VS</div></div>
        <div>
            <div class="text-red">敵方隊伍</div>
            <div class="squad-grid2">
                <?php foreach($enemy_team_F as $pos => $fox): ?>
                    <div class="squad-block <?= $pos == 'pos_1' ? 'squad-front' : '' ?>">
                    <?php if($fox):?>
                        <div><?= $fox['name'].' Lv.'.$fox['level'] ?></div>
                        <div class="text-gold">戰力：<?= number_format($fox['power']); ?></div>
                        <div><?= '❤️'.($fox['hp']??0).' | ⚔️'.($fox['atk']??0).' | 🛡️'.($fox['def']??0) ?></div>
                    <?php else: ?><div>無</div><div>對</div><div>象</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="stage-detail-content" style="overflow-y: auto;">
        <div class="text-nomal text-red">⚔️ 戰鬥詳細紀錄</div>
        <?php foreach ($battle_data['rounds'] as $round_data): ?>
            <div style="border:1px solid #444; margin:10px 0; padding:10px;">
                <h3>第 <?= $round_data['round'] ?> 回合</h3>
                <?php foreach ($round_data['actions'] as $msg): ?>
                    <p><?= $msg ?></p>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($result === '勝利'): ?>
        <div style="background:#222; padding:15px; margin: 10px 0; border-radius: 8px;">
            <div class="text-h2 text-red" style="margin-bottom:10px;">🎁 戰鬥戰利品</div>
            
            <?php 
            // 額外保險：檢查結構是否存在
            $merits = $actual_rewards['merit'] ?? [];
            $items = $actual_rewards['item'] ?? [];
            ?>

            <?php if (empty($merits) && empty($items)): ?>
                <div class="text-gray">無獎勵資料。</div>
            <?php else: ?>
                <?php foreach ($merits as $m): ?>
                    <div style="padding:5px; border-bottom:1px solid #333;">
                        <span class="icon">✨</span> 功德 x<?= $m['data']['amount'] ?> 
                        <small>(機率: <?= ($m['data']['probability'] * 100) ?>%)</small>
                        <?= $m['won'] ? '<span class="text-gold"> ✅ 獲得</span>' : '<span class="text-gray"> ❌ 沒中</span>' ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($items as $i): ?>
                    <div style="padding:5px; border-bottom:1px solid #333;">
                        <span class="icon">📦</span> <?= $i['data']['item_name'] ?> x<?= $i['data']['amount'] ?> 
                        <small>(機率: <?= ($i['data']['probability'] * 100) ?>%)</small>
                        <?= $i['won'] ? '<span class="text-gold"> ✅ 獲得</span>' : '<span class="text-gray"> ❌ 沒中</span>' ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <button class="manual-btn-close" onclick="window.location.href='stage.php'">返回冒險區</button>

</div>
</body>
</html>

<?php
// 6. 重要：結算後清除戰鬥 Session，防止重新整理重複領獎
// unset($_SESSION['battle']);
?>
