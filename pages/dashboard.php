<?php
require_once '../includes/db.php';
require_once '../includes/db_functions.php';
require_once '../config/config.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// --- 統一處理所有 AJAX POST 請求 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'] ?? '';
    $result = isset($actionHandlers[$action]) 
        ? $actionHandlers[$action]($user_id)
        : ['status' => 'error', 'message' => '無效操作'];
    
    echo json_encode($result);
    exit;
}
// 取得玩家與助戰狐
$player = $actionHandlers['get_player']($user_id);
$supportFox = $player['id'] ? get_fox_stats($player) : null;
// 抓取背包
$inventory = $actionHandlers['get_inventory']($user_id);
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
// 當前狐狸篩選與排序邏輯 (使用 $foxMap 的 values)
$foxesToDisplay = array_values($foxMap);
$filter = $_SESSION['fox_filter'] ?? ['type' => 'all', 'sort' => 'power', 'order' => 'DESC'];
if ($filter['type'] !== 'all') {
    $foxesToDisplay = array_filter($foxesToDisplay, fn($f) => $f['type'] === $filter['type']);
}
usort($foxesToDisplay, function($a, $b) use ($filter) {
    return ($filter['order'] === 'DESC') ? ($b[$filter['sort']] <=> $a[$filter['sort']]) : ($a[$filter['sort']] <=> $b[$filter['sort']]);
});
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <title>靈狐領地</title>
</head>
<body>
<div class="main-container">
    <!-- 名字/登出按鈕 -->
    <div class="header">
        <span class="text-h1">⛩️</span>
        <div class="name-container" style="position: relative; flex-grow: 1;">
            <div id="display-name-area" class="text-h2 cursor-pointer" onclick="showEdit(true)">
                <?= htmlspecialchars($player['name']) ?><span class="text-green">✎</span>
            </div>                
            <div id="edit-name-area" style="display:none;" class="flex_gap5">   
                <input id="new-name-input" class="input-dark" value="<?= htmlspecialchars($player['name']) ?>" 
                    onkeypress="if(event.key==='Enter') doAction('rename', {new_name: this.value})">
                <button type="button" class="btn-base btn-main" onclick="doAction('rename', {new_name: document.getElementById('new-name-input').value})">OK</button>
            </div>
        </div>
        <button class="btn-base btn-red-info" onclick="window.location.href='logout.php'">🚪 登出</button>
    </div>
    <!-- 彈窗/跳轉按鈕 -->
    <div class="header">
        <div class="flex_gap5">
            <button class="btn-base btn-info" onclick="document.getElementById('info-modal').style.display='block'">📖 說明</button>
            <button class="btn-base btn-info" onclick="document.getElementById('bag-modal').style.display='block'">🎒 背包</button>
            <button class="btn-base btn-info" onclick="showFriendTab('list')">👥 好友</button>
        </div>
        <div class="flex_gap5">
            <button class="btn-base btn-main" onclick="window.location.href='shop.php'">🛒 商店</button>
            <button class="btn-base btn-main" onclick="window.location.href='stage.php'">⚔️ 冒險</button>
        </div>
    </div>
    <!-- 說明欄 -->
    <div id="info-modal" class="modal-overlay" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="text-h2 text-green">📖 靈狐修煉秘笈</div>
            <div class="modal-scroll">
                <!-- 1. 基礎教學 -->
                <details class="manual-box">
                    <summary class="manual-summary"><?= $game_manual['base_tip']['title']; ?></summary>
                    <?php foreach($game_manual['base_tip']['groups'] as $groupTitle => $points): ?>
                    <div class="text-green"><?= $groupTitle ?></div>
                    <div class="text-small text-white" style="margin-left: 1rem;">
                        <?php foreach($points as $text): ?>
                            <div><?= $text ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </details>
                <!-- 2. 數值表 -->
                <details class="manual-box">
                    <summary class="manual-summary"><?= $game_manual['fox_data_overview']['title']; ?></summary>
                    <?php foreach($game_manual['fox_data_overview']['sections'] as $sec): ?>
                        <div class="text-green text-normal"><?= $sec['title']; ?></div>
                        <?= isset($sec['description']) ? "<div class='text-small'>{$sec['description']}</div>" : '' ?>
                        <table class="sheet-table">
                            <tr><?php foreach($sec['headers'] as $h): ?><th><?= $h; ?></th><?php endforeach; ?></tr>
                            <?php foreach($sec['content'] as $row): ?>
                                <tr><?php foreach($row as $cell): ?><td><?= $cell; ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endforeach; ?>
                </details>
                <!-- 3. 道具列表 -->
                <details class="manual-box">
                    <summary class="manual-summary"><?= $game_manual['item_list']['title']; ?></summary>
                    <?php foreach($game_manual['item_list']['groups'] as $g => $items): ?>
                        <div class="text-green"><?= $g; ?></div>
                        <?php foreach($items as $name): ?>
                            <div class="text-bitty"><?= $name . "：" . $fox_items[$name]['desc']; ?></div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </details>
            </div>
            <button class="manual-btn-close" onclick="document.getElementById('info-modal').style.display='none'">關閉</button>
        </div>
    </div>
    <!-- 背包欄 -->
    <div id="bag-modal" class="modal-overlay" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="text-h2 text-green">🎒 我的背包</div>
            <div class="modal-scroll">
                <?php foreach($game_manual['item_list']['groups'] as $groupName => $items): 
                    // 只保留擁有該物品的 group
                    $ownedItems = array_filter($items, fn($n) => ($inventory[$n] ?? 0) > 0);
                    if($ownedItems): ?>
                        <div class="group-title"><?= $groupName ?></div>
                        <div class="item-grid">
                            <?php foreach($ownedItems as $name): ?>
                                <div class="item-card">
                                    <div class="flex-between text-bitty">
                                        <div><?= $name ?></div><div class="text-gold">x<?= $inventory[$name] ?></div>
                                    </div>
                                    <div class="text-small"><?= htmlspecialchars($fox_items[$name]['desc'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                <?php endif; endforeach; ?>
            </div>
            <button class="manual-btn-close" onclick="document.getElementById('bag-modal').style.display='none'">關閉背包</button>
        </div>
    </div>
    <!-- 好友欄 -->
    <div id="friend-modal" class="modal-overlay" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="text-h2 text-green">👥 好狐朋友</div>
            <div class="flex flex_gap5">
                <button class="btn-base btn-info" onclick="showFriendTab('list')"    id="count-friends" >好友</button>
                <button class="btn-base btn-info" onclick="showFriendTab('request')" id="count-requests">申請</button>
                <button class="btn-base btn-info" onclick="showFriendTab('search')">尋找</button>
            </div>
            <div class="modal-scroll" style="height: 25rem;">
                <!-- 當前好友 -->
                <div id="friend-list-tab" class="manual-friend-tab"></div>
                <!-- 處理申請 -->
                <div id="friend-request-tab" class="manual-friend-tab" style="display:none;"></div>
                <!-- 尋找好友 -->
                <div id="friend-search-tab" class="manual-friend-tab" style="display:none;">
                    <div class="flex_gap5">
                        <input id="search-input" class="input-dark" placeholder="輸入ID/名稱"
                                onkeypress="if(event.key==='Enter') doAction('get_friend_status', {type: 'find', q: this.value})">
                        <button class="btn-base btn-main" 
                                onclick="doAction('get_friend_status', {type: 'find', q: document.getElementById('search-input').value})">搜尋</button>
                        <button class="btn-base btn-main" onclick="doAction('get_friend_status', {type: 'random'})">🔄 換一批</button>
                    </div>
                    <div id="search-results" class="margin-top5"></div>
                </div>
            </div>
        </div>
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
        <div class="squad-box">
            <span class="text-gold">✨ 助戰：</span>
            <?php if($supportFox): ?>
                <div class="text-small">
                    <span class="text-nomal"><?= $supportFox['name'] ?? '未設置' ?></span>
                    <span>Lv.<?= $supportFox['level'] ?></span>
                    <span class="text-gold">戰力: <?= number_format($supportFox['power']) ?></span>
                    <span>❤️<?= $supportFox['hp'] ?> | ⚔️<?= $supportFox['atk'] ?> | 🛡️<?= $supportFox['def'] ?></span>
                </div>
            <?php else: ?>
                <div class="text-h2 text-gray">(空)</div>
            <?php endif; ?>
        </div>
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
    <!-- 狐狸區 -->
    <div class="fox-panel">
        <div class="flex-between">
            <div class="text-h2 text-green">靈狐一覽</div>
            <div class="flex_gap5">
                <select id="attr-filter" class="input-dark" onchange="doAction('set_fox_filter', {type: this.value})">
                    <?php foreach(['all'=>'✨全屬性','fire'=>'🔥火','water'=>'❄️水','wind'=>'🌪️風','light'=>'🌙光','dark'=>'🌌暗'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $filter['type'] == $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="sort-by" class="input-dark" onchange="doAction('set_fox_filter', {sort: this.value})">
                    <?php foreach(['level'=>'等級','power'=>'戰力','hp'=>'HP','atk'=>'ATK','def'=>'DEF'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $filter['sort'] == $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-base btn-info" onclick="doAction('set_fox_filter', {order: '<?= $filter['order'] === 'DESC' ? 'ASC' : 'DESC' ?>'})">
                    <?= $filter['order'] == 'DESC' ? '▼' : '▲' ?>
                </button>
            </div>
        </div>
        <div class="fox-list-container">
            <?php foreach ($foxesToDisplay as $f): 
                $pct = min(100, $f['exp'] / ($f['level'] + 1) );
                $exp_text = ($f['level'] < $level_max ? ($f['exp'].'/'.(($f['level']+1)*100)) : '等級已達上限');
            ?>
            <details class="fox-card <?= $f['type'] ?>" id="card-<?= $f['id']; ?>" ontoggle="manageFoxDetails(this, <?= $f['id'] ?>)">
                <summary> <!-- 基本介面 -->
                    <div class="flex-between">
                        <div><?= htmlspecialchars($f['name']) ?> (Lv.<?= $f['level'] ?>)</div>
                        <div class="text-gold">戰力: <?= number_format($f['power']) ?></div>
                        <div>❤️<?= $f['hp'] ?> | ⚔️<?= $f['atk'] ?> | 🛡️<?= $f['def'] ?></div>
                    </div>
                    <div class="bar-bg">
                        <div class="bar-fill" style="width:<?= $pct; ?>%"></div>
                        <div class="bar-text"><?= $exp_text; ?></div>
                    </div>
                    <span class="flex_gap5 margin-top5" style="width: fit-content;"onclick="event.stopPropagation()">                  
                        <button class="btn-base btn-info" onclick="doAction('set_support', {fox_id: <?= $f['id'] ?>})">設為助戰</button>
                        <select id="pos-select-<?= $f['id'] ?>" class="input-dark" style="width: auto;">
                            <option value="1">先鋒</option>
                            <option value="2">後衛上</option>
                            <option value="3">後衛下</option>
                        </select>
                        
                        <button class="btn-base btn-main" 
                                onclick="doAction('set_squad', {
                                    fox_id: <?= $f['id'] ?>, 
                                    pos: document.getElementById('pos-select-<?= $f['id'] ?>').value
                                })">上陣</button>
                    </span>
                </summary>
                <div> <!-- 數值/養成 -->
                    <table class="sheet-table">
                        <tr><th>項目</th><th>❤️HP</th><th>⚔️ATK</th><th>🛡️DEF</th></tr>
                        <tr><td>基礎</td><td><?= $f['base_hp']; ?></td><td><?= $f['base_atk']; ?></td><td><?= $f['base_def']; ?></td></tr>
                        <tr><td>等級</td><td>+<?= $f['lvl_hp']; ?></td><td>+<?= $f['lvl_atk']; ?></td><td>+<?= $f['lvl_def']; ?></td></tr>
                        <tr><td>等階</td><td>+<?= $f['tier_hp']; ?></td><td>+<?= $f['tier_atk']; ?></td><td>+<?= $f['tier_def']; ?></td></tr>
                        <tr class="text-green"><td >等階進度</td><td><?= $f['hp_tier']; ?></td><td><?= $f['atk_tier']; ?></td><td><?= $f['def_tier']; ?></td></tr>
                    </table>
                    <div class="flex_gap5 margin-top5">
                        <select id="item-select-<?= $f['id'] ?>" class="input-dark flex-2">
                            <option value="">-- 請選擇養成道具 --</option>
                            <?php foreach($game_manual['item_list']['groups'] as $groupName => $items): ?>
                                <optgroup label="<?= $groupName ?>">
                                    <?php foreach($items as $name): 
                                        if(!isset($inventory[$name]) || $inventory[$name] <= 0) continue;
                                    ?>
                                    <option value="<?= $name ?>" <?= check_max($f, $fox_items[$name]) ? 'disabled' : '' ?>>
                                        <?= $name ?> (擁有: <?= $inventory[$name] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="feed-qty-<?= $f['id'] ?>" value="1" min="1" max="99" class="input-dark flex-1">
                    </div>                
                    <button type="button" class="manual-btn-close" 
                        onclick="
                            const btn = this;
                            const item = document.getElementById('item-select-<?= $f['id'] ?>').value;
                            if(!item) { alert('請選擇道具'); return; }
                            
                            btn.disabled = true; // 防止重複點擊
                            btn.innerText = '餵養中...';
                            
                            doAction('feed_fox', {
                                fox_id: <?= $f['id'] ?>, 
                                item_name: item,
                                qty: document.getElementById('feed-qty-<?= $f['id'] ?>').value
                            }).finally(() => {
                                btn.disabled = false;
                                btn.innerText = '確認餵養';
                            });
                        ">確認餵養</button>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
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
    get_friend_status: (res, p) => {
        updateFriendTabs(p.type, res.data, res.counts);
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
// 顯示名稱編輯
function showEdit(show) {
    const disp = document.getElementById('display-name-area');
    const edit = document.getElementById('edit-name-area');
    if (show) {
        disp.style.display = 'none';
        edit.style.display = 'flex';
        edit.querySelector('input').focus(); // 自動聚焦輸入框
    } else {
        disp.style.display = 'flex';
        edit.style.display = 'none';
    }
}
// 點擊外部自動收起名稱編輯
document.addEventListener('click', (e) => {
    const disp = document.getElementById('display-name-area');
    const edit = document.getElementById('edit-name-area');
    if (!disp.contains(e.target) && !edit.contains(e.target)) showEdit(false);
});
// 切換好友欄位區塊
async function showFriendTab(tab) {
    document.getElementById('friend-modal').style.display = 'block';
    const tabs = {
        'list': document.getElementById('friend-list-tab'),
        'request': document.getElementById('friend-request-tab'),
        'search': document.getElementById('friend-search-tab')
    };
    Object.keys(tabs).forEach(key => {
        tabs[key].style.display = (key === tab) ? 'block' : 'none';
    });
    await doAction('get_friend_status', { type: 'list' });
    await doAction('get_friend_status', { type: 'request' });
    if (tab === 'search') {
        await doAction('get_friend_status', { type: 'random' });
    }
}
// 統一處理好友資料的更新與顯示
function updateFriendTabs(type, data, counts) {
    if (counts) {
        document.getElementById('count-friends').textContent = `好友（${counts.f}）`;
        document.getElementById('count-requests').textContent = `申請（${counts.r}）`;
    }
    // 1. 先定義配置
    const layouts = {
        list: [
            { title: '👤 我的公開狀態', filter: u => u.status === 'self' },
            { title: '👥 好友列表', filter: u => u.status === 'accepted' }
        ],
        request: [
            { title: '待確認的申請', filter: u => u.status === 'incoming' },
            { title: '我發出的申請', filter: u => u.status === 'pending' }
        ]
    };
    // 2. 決定容器
    const container = document.getElementById(layouts[type] ? `friend-${type}-tab` : 'search-results');
    // 3. 處理渲染
    if (layouts[type]) {
        container.innerHTML = layouts[type].map(cfg => {
            const items = data.filter(cfg.filter);
            return items.length 
                ? `<div class="text-green">${cfg.title}</div>${items.map(renderUserCard).join('')}`
                : `<div class="text-gray">${cfg.title}：無資料</div>`;
        }).join('<hr>');
    } else {
        container.innerHTML = data.length ? data.map(renderUserCard).join('') : '<div class="text-gray">無資料</div>';
    }
}
function renderUserCard(user) {
    const foxTemplates = <?= json_encode($fox_templates); ?>;
    const btnCfg = {
        'accepted': [{ txt: '斷絕關係', act: 'remove_friend', css: 'btn-red-info' }],
        'pending':  [{ txt: '取消申請', act: 'remove_friend', css: 'btn-red-info' }],
        'none':     [{ txt: '新增好友', act: 'add_friend', css: 'btn-info' }],
        'incoming': [
            { txt: '同意', act: 'accept_friend', css: 'btn-info' },
            { txt: '拒絕', act: 'remove_friend', css: 'btn-red-info' }
        ]
    };
    const formatRelativeTime = (dateStr) => {
        if (!dateStr) return "未知";
        const date = new Date(dateStr);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000); // 秒差
        if (diff < 60) return "剛剛";
        if (diff < 3600) return Math.floor(diff / 60) + " 分鐘前";
        if (diff < 86400) return Math.floor(diff / 3600) + " 小時前";
        if (diff < 604800) return Math.floor(diff / 86400) + " 天前";
        return dateStr.substring(5, 10); // 超過一周顯示日期
    }
    const foxName = user.type ? (foxTemplates[user.type]?.name || '神秘狐狸') : '無';
    const actionBtn = (user.status === 'self') ? '' : (btnCfg[user.status] || btnCfg['none']).map(b => 
        `<button class="btn-base ${b.css}" onclick="doAction('${b.act}', {id:${user.id}})">${b.txt}</button>`
    ).join('');
    return `
        <div class="item-card">
            <div class="flex-between">
                <span>${user.name} <small>(ID:${user.id})</small></span>
                <div class="flex_gap5">${actionBtn}</div>
            </div>
            <div class="flex-between text-small text-gold margin-top5">
                <span>✨ 助戰：${foxName} Lv.${user.level ?? 0}</span>
                <div>最後登入：${formatRelativeTime(user.last_login_date)}</div>
            </div>
        </div>`;
}    
// 展開/收合與狀態記憶
function manageFoxDetails(el, id) {
    if (el.open) {
        // 1. 關閉頁面上所有其他打開的 details
        document.querySelectorAll('details[open]').forEach((other) => {
            if (other !== el) {
                other.open = false;
            }
        });
        // 2. 記錄當前打開的 ID
        localStorage.setItem('open_fox', id);
    } else {
        // 如果這個元素是被關閉的，且當前 localStorage 是它，則清除紀錄
        if (localStorage.getItem('open_fox') == id) {
            localStorage.removeItem('open_fox');
        }
    }
}
// 頁面載入時初始化：恢復展開狀態
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
    const lastOpenId = localStorage.getItem('open_fox');
    if (lastOpenId) document.getElementById('card-' + lastOpenId).open = true;
};
</script>
</body>
</html>