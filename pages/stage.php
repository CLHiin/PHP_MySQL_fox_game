<?php
require_once '../includes/db.php';
require_once '../includes/db_functions.php';
require_once '../config/config.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 取得玩家資訊
$player = $actionHandlers['get_player']($user_id);
// 抓取狐狸
$allFoxesRaw = $actionHandlers['get_foxes']($user_id);
$foxMap = []; // 轉成 array 以便快速查找: $foxMap[fox_id] = stats
foreach ($allFoxesRaw as $f) $foxMap[$f['id']] = get_fox_stats($f);
// 獲取隊伍資料
$current_squad_num = $_SESSION['squad_id'] ?? 1;
$squad_row = $actionHandlers['get_squad_fox']($user_id, $current_squad_num);
$currentSquad = [null, null, null];
if ($squad_row) {
    foreach ([1, 2, 3] as $i) {
        $currentSquad[$i-1] = $foxMap[$squad_row["pos_$i"]] ?? null;
    }
}
// 關卡目錄
$raw_stages = $actionHandlers['get_stages']($user_id);
$active_cat = $_SESSION['stage_cat'] ?? '主線';
$grouped_stages = $stage_configs;
$STATUS = [
    'CLEARED'   => 'cleared',
    'AVAILABLE' => 'open',
    'LOCKED'    => 'locked'
];
foreach ($raw_stages as $s) {
    $type_parts = explode('-', trim($s['type']));
    $cat = $type_parts[0] ?? '其他';
    $region = $type_parts[1] ?? '神秘區域';
    $stage_num = intval($type_parts[2] ?? 0);
    $grouped_stages[$cat][$region][$stage_num] = $s;
}
// 狀態判定
foreach ($grouped_stages as $cat => &$regions) {
    foreach ($regions as $region => &$stages_list) {
        ksort($stages_list);
        // 2. 狀態判斷
        $prev_cleared = true;
        foreach ($stages_list as $index => &$s) {
            if ($s['is_cleared']) {
                $s['status'] = $STATUS['CLEARED'];
            } elseif ($prev_cleared) {
                $s['status'] = $STATUS['AVAILABLE'];
                $prev_cleared = false; // 下一關鎖定
            } else {
                $s['status'] = $STATUS['LOCKED'];
            }
        }
    }
}
unset($regions, $stages_list, $s); // 清理引用以防汙染

// echo "<h3>目前的 $raw_stages 結構：</h3>";
// echo "<pre>";
// print_r($raw_stages);
// echo "</pre>";

// 暫停程式，防止後面亂七八糟的 HTML 干擾視覺
// exit("除錯中斷：檢查完畢請刪除或註解此段代碼");
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <title>關卡選擇</title>
</head>
<body>
<div class="main-container">
    <!-- 標題區 -->
    <div class="header">
        <div class="text-h1">選擇關卡</div>
        <?php if ($_SESSION['is_admin'] == 1): ?>
        <button class="btn-base btn-red-info" onclick="window.location.href='manage_stage.php'"> 管理面板</button>
        <?php endif; ?>
        <button class="btn-base btn-red-info" onclick="window.location.href='dashboard.php'">🚪 返回首頁</button>
    </div>
    <!-- 訊息區 -->
    <section class="worship-bar">
        <div>
            <span>💰 功德：<?= number_format($player['merit']); ?></span><br>
            <span>💪 體力：<?= number_format($player['stamina']); ?></span>
        </div>
        <span id="msg-display" class="text-gold">
            <?php if (!empty($_SESSION['msg'])): echo $_SESSION['msg']; unset($_SESSION['msg']);?><?php endif; ?>
        </span>
        <button type="button" class="btn-base btn-main" onclick="doAction('worship')">⛩️ 參拜神社</button>
    </section>
    <!-- 隊伍區 -->
    <div class="squad-panel">  
        <div class="flex-between">
            <div class="text-h2 text-green">隊伍調整</div>
            <div class="flex-between flex_gap5">
                <select id="squad-select" class="input-dark" onchange="doAction('switch_squad', {squad_num: this.value})">
                    <option value="1" <?= $current_squad_num == 1 ? 'selected' : '' ?>>隊伍 1</option>
                    <option value="2" <?= $current_squad_num == 2 ? 'selected' : '' ?>>隊伍 2</option>
                    <option value="3" <?= $current_squad_num == 3 ? 'selected' : '' ?>>隊伍 3</option>
                </select>
            </div>
        </div>
        <div class="squad-grid">
            <?php foreach( [ 1 => '先鋒', 2 => '後衛上', 3 => '後衛下'] as $pos => $label): $fox = $currentSquad[$pos-1] ?? null;?>
                <div class="squad-box text-small">
                    <div class="text-nomal text-green flex-between"><?= $label ?>
                        <button type="button" class="btn-base btn-red-info text-small"
                            onclick="doAction('remove_squad', {pos: <?= $pos ?>})">❌</button>    
                    </div>
                    <?php if($fox): ?>
                        <div class="text-nomal"><?= $fox['name'] ?> Lv.<?= $fox['level'] ?></div>
                        <div class="text-gold">戰力: <?= number_format($fox['power']) ?></div>
                        <div>❤️<?= $fox['hp'] ?> | ⚔️<?= $fox['atk'] ?> | 🛡️<?= $fox['def'] ?></div>
                    <?php else: ?>
                        <div class="text-h2 text-gray">(空)</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- 關卡區 -->
    <div class="stage-list-container">
        <div class="flex-between">
            <h2 class="text-h2">關卡一覽(<?= $active_cat;?>)</h2>
            <div class="flex flex_gap5">
                <button class="btn-base <?= $active_cat == '主線' ? 'btn-main' : 'btn-info' ?>" 
                        onclick="doAction('switch_filter', {cat: '主線'})">主線</button>
                <button class="btn-base <?= $active_cat == '副本' ? 'btn-main' : 'btn-info' ?>" 
                        onclick="doAction('switch_filter', {cat: '副本'})">副本</button>
            </div>
        </div>
        <?php foreach ($grouped_stages[$active_cat] as $region => $stages_list):?>
        <details class="manual-box stage-card">
            <summary class="manual-summary flex-between">
                <span><?= $region ?></span>
                <span class="text-small text-gray">點擊展開類別</span>
            </summary>
            <div class="text-bitty">
            <?php foreach ($stages_list as $num => $s):
                $is_locked  = ($s['status'] === $STATUS['LOCKED']);
                $is_cleared = ($s['status'] === $STATUS['CLEARED']);
                $can_battle = !$is_locked && !($active_cat === '主線' && $is_cleared);
            ?>
                <details class="manual-box stage-card <?= $is_locked ? 'stage-locked' : '' ?>">
                    <summary class="stage-summary flex_gap5">
                        <div>
                            <strong><?= $is_locked ? '🔒' : ($is_cleared ? '✅' : '⚔️') ?> <?= $num.'. '.$s['name'] ?></strong>
                            <span class="text-small">難度: <?= $s['rec_level'] ?></span>
                        </div>
                        <span class="<?= $is_cleared ? 'text-green' : ($is_locked ? 'text-gray' : 'text-red') ?>">
                            <?= $is_cleared ? '已通關' : ($is_locked ? '🔒未解鎖' : '🆕可挑戰') ?>
                        </span>
                        <div>陣容: 
                            <?php foreach($s['enemies'] as $e): ?>
                                <span class="tag-<?= $e['type'] ?>"><?= $icons[$e['type']] ?? '❓' ?> Lv.<?= $e['level'] ?></span>
                            <?php endforeach; ?>
                        </div>
                    </summary>
                    <?php if ($can_battle): ?>

                    <div class="flex-between"> <!-- 體力消耗/開始-->
                        <div class="text-nomal text-red">⚡ 體力消耗: <?= $s['stamina_cost'] ?></div>
                        <button class="btn-base btn-info" onclick="openPrep(<?= $s['id'] ?>)">開始挑戰</button>
                    </div>
                    <div class="stage-detail-content"> <!-- 詳細 -->
                        <div class="text-nomal text-red">💀 敵方隊伍資訊</div>
                        <div class="squad-grid2 text-small text-white">
                            <?php
                                $fixed_enemies = [0 => [], 1 => [], 2 => []];
                                foreach ($s['enemies'] as $e) {
                                    $index = intval($e['pos']) - 1; 
                                    if (isset($fixed_enemies[$index])) $fixed_enemies[$index] = $e;
                                }
                                // 2. 使用這個固定陣列來跑迴圈
                                foreach ($fixed_enemies as $pos => $e): ?>
                                <div class="squad-block <?= $pos == 0 ? 'squad-front' : '' ?>">
                                    <?php if($e): ?>
                                        <div><?= ($icons[$e['type']] ?? '❓').$e['name']. ' Lv.' .$e['level'] ?></div>
                                        <div class="text-gold">戰力：<?= number_format($e['power'] ?? 0) ?></div>
                                        <div><?= '❤️'.($e['hp']??0).' | ⚔️'.($e['atk']??0).' | 🛡️'.($e['def']??0) ?></div>
                                    <?php else: ?><div>無</div><div>對</div><div>象</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-nomal text-red">🎁 掉落物</div>
                        <div class="text-bitty"> <!-- 掉落物詳細 -->
                            <?php 
                            // 直接合併成一個平坦的陣列，功德補上 name 欄位
                            $all = array_merge(
                                array_map(fn($m) => ['type'=>'merit', 'name'=>'功德', ...$m], $s['rewards']['merit']),
                                array_map(fn($i) => ['type'=>'item', 'name'=>$i['item_name'], ...$i], $s['rewards']['item'])
                            );
                            foreach ($all as $r): 
                                $icon = ($r['type'] === 'merit') ? '💰' : ($itemConfig[$fox_items[$r['name']]['type']]['icon'] ?? '📦');
                            ?>
                            <div><?= $icon ?> <?= $r['name'] ?> x<?= $r['amount'] ?> (<?= $r['probability'] * 100 ?>%)</div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="stage-detail-content text-small text-gray">
                            <?= $is_locked ? '關卡未解鎖，請先通過前置關卡。' : '主線通關無法再次挑戰。' ?>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <!-- 戰鬥區 -->
    <div id="battle-modal" class="modal-overlay" onclick="this.style.display='none'">
        <div class="modal-content" style="max-width: 55rem;" onclick="event.stopPropagation()">
            <div class="text-h2 text-green">⚔️ 戰鬥</div>
            <div class="modal-scroll" id="Preview" style="max-height: 35rem;"></div>
            <button class="manual-btn-close" onclick="document.getElementById('battle-modal').style.display='none'">關閉</button>
        </div>
    </div>
<script>
// 統一的訊息顯示函式
function showMsg(res) {
    if (res.alert) alert(res.alert); // alert 警告
    const msgEl = document.getElementById('msg-display');
    msgEl.textContent = res.message;
    setTimeout(() => { msgEl.textContent = ''; }, 3000); // 自動清除訊息 (選用)
}
// 事件處理
const handlers = {
    get_helpers: (res, parms)=>{
        helpersMap = {};
        const listContainer = document.getElementById('helper-box');
        listContainer.innerHTML = ''; // 清空舊資料
        res.data.forEach(helper => {
            const fox = helper.fox;
            const div = document.createElement('div');
            helpersMap[helper.player_id] = helper;
            // 直接套用你現有的 fox-card 與 屬性 class (如 fire, water...)
            div.className = `fox-card ${fox.type} margin-top5`; 
            div.innerHTML = `
                <div class="item-card">
                    <div>
                        ${helper.player_name}<span class="text-green">(${helper.is_friend?'':'非'}好友)</span>
                    </div>
                    <div class="flex-between">
                        <span>${fox.name}<span class="text-small">Lv.${fox.level}</span></span>
                        <span class="text-gold">戰力: ${fox.power.toLocaleString()}</span>
                    </div>
                    <div>❤️${fox.hp} | ⚔️${fox.atk} | 🛡️${fox.def}</div>
                    <div class="flex_gap5 margin-top5">
                        <button class="btn-base btn-info" onclick="selectHelper(${helper.player_id}, 1)">位置1</button>
                        <button class="btn-base btn-info" onclick="selectHelper(${helper.player_id}, 2)">位置2</button>
                        <button class="btn-base btn-info" onclick="selectHelper(${helper.player_id}, 3)">位置3</button>
                    </div>
                </div>
            `;
            listContainer.appendChild(div);
        });
    },
    start_battle: (res) => {
        sessionStorage.setItem('pending_msg', JSON.stringify(res));
        window.location.href = 'battle.php';
    },
    // 預設操作：重整頁面
    default: (res) => {
        sessionStorage.setItem('pending_msg', JSON.stringify(res));
        window.location.href = window.location.pathname;
    }
};
async function doAction(action, params = {}) {
    const formData = new FormData();
    formData.append('action', action);
    for (let k in params) formData.append(k, params[k]);
    try {
        const response = await fetch('dashboard.php', { method: 'POST', body: formData });
        // --- 核心偵錯點 ---
        const text = await response.text(); // 先抓取純文字回應
        let res;
        try { res = JSON.parse(text); // 嘗試解析
        } catch (e) {
            console.error("原始回應內容 (可能是 PHP 錯誤訊息):", text);
            throw new Error("伺服器回應格式錯誤");
        }
        showMsg(res);
        if (res.status === 'success') {
            (handlers[action] || handlers.default)(res, params);
        }
    } catch (e) { console.error("系統異常:", e); }
}
window.onload = () => {
    const rawRes = sessionStorage.getItem('pending_msg');
    if (rawRes) {
        try {
            showMsg(JSON.parse(rawRes));
            sessionStorage.removeItem('pending_msg');
        } catch (e) {
            console.error("解析錯誤", e);
        }
    }
};

// 確保這是正確的 JSON 輸出
const allStagesData = <?= json_encode($grouped_stages[$active_cat]) ?>;

// 建立一個扁平化的 stageMap，方便透過 stage_id 快速查找
const stageMap = {};
Object.values(allStagesData).forEach(region => {
    Object.values(region).forEach(stage => {
        stageMap[stage.id] = stage;
    });
});


const mySquadData = <?= json_encode($currentSquad) ?>;
let helpersMap = {};
let currentHelper = null;
// 打開準備面板
function openPrep(stage_id) {
    const stage = stageMap[stage_id]; // 直接從剛剛注入的物件讀取
    if (!stage) return;
    doAction('get_helpers');

    // 敵方位置
    const enemyData = [null, null, null];
    stage.enemies.forEach(e => {
        let posIndex = parseInt(e.pos) - 1;
        if (posIndex >= 0 && posIndex < 3) enemyData[posIndex] = e;
    });
    document.getElementById('battle-modal').style.display = 'block';
    document.getElementById('Preview').innerHTML = `
        <div class="battle-prep-layout">
            <div>
                <div class="text-green">我方隊伍</div>
                ${renderSquadHtml(mySquadData, true)}
            </div>
            <div class="flex-center">
                <div class="text-gold text-h2">VS</div>
                <button class="btn-base btn-info" onclick="startStageBattle(${stage_id})">開始戰鬥</button>
            </div>
            <div>
                <div class="text-red">敵方隊伍</div>
                ${renderSquadHtml(enemyData)}
            </div>
        </div>
        <div class="flex-between">
            <div class="text-h3 text-green">👥 選擇助戰</div>
        </div>
        <div id="helper-box" class="item-grid" style="grid-template-columns: repeat(3, 1fr)"></div>
    `;
}
// 選擇好友
function selectHelper(index, pos) {
    const helper = helpersMap[index];
    currentHelper = helper ? {
        player_id: helper.player_id,
        fox: helper.fox,
        pos: pos
    }: null;

    // 重新渲染我方隊伍區域
    const myTeamContainer = document.querySelector('.battle-prep-layout > div:first-child');
    myTeamContainer.innerHTML = `
        <div class="text-green">我方隊伍</div>
        ${renderSquadHtml(mySquadData, true, currentHelper)}
    `;
}
// 繪製戰鬥預覽區
function renderSquadHtml(squadArray, isMyTeam = false, helper = null) {
    let html = `<div class="squad-grid2${isMyTeam ? '-A' : ''}">`;
    squadArray.forEach((char, index) => {
        const pos = index + 1;
        const isHelperPos = (isMyTeam && helper && helper.pos == pos);
        html += `
            <div class="squad-block text-small text-white ${pos === 1 ? 'squad-front' : ''}">
                ${isHelperPos ? `
                    <div class="text-green flex-between">${helper.fox.name} Lv.${helper.fox.level}
                        <button class="btn-base btn-red-info text-small" onclick="selectHelper(null, 0)"
                                style="padding: 0.1rem 0.3rem;">❌</button>
                    </div>
                    <div class="text-gold">戰力: ${helper.fox.power.toLocaleString()}</div>
                    <div>❤️${helper.fox.hp} | ⚔️${helper.fox.atk} | 🛡️${helper.fox.def}</div>
                ` : (char ? `
                    <div>${char.name} Lv.${char.level}</div>
                    <div class="text-gold">戰力：${char.power?.toLocaleString() ?? 0}</div>
                    <div>❤️${char.hp} | ⚔️${char.atk} | 🛡️${char.def}</div>
                ` : '<div>無</div><div>對</div><div>象</div>')}
            </div>
        `;
    });
    html += `</div>`;
    return html;
}
// 發送開始
function startStageBattle(stage_id) {
    // 準備要送出的參數
    let params = { stage_id: stage_id };
    // 如果有選助戰，把資訊塞進去
    if (currentHelper) {
        params.helper_id = currentHelper.player_id;
        params.helper_pos = currentHelper.pos;
    }
    // 呼叫原本的 doAction
    doAction('start_battle', params);
}
</script>

</body>
</html>