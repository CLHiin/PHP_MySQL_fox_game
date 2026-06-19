<?php
require_once '../includes/db.php';
require_once '../includes/db_functions.php';
require_once '../config/config.php';

// 確保權限檢查使用正確的 Session 狀態
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    die("存取被拒，您的權限是: " . var_export($_SESSION['is_admin'] ?? 'NULL', true));
}
$user_id = $_SESSION['user_id'];

// 關卡目錄
$raw_stages = $actionHandlers['get_stages']($user_id);
$active_cat = $_SESSION['stage_cat'] ?? '主線';
$grouped_stages = $stage_configs;
foreach ($raw_stages as $s) {
    $type_parts = explode('-', trim($s['type']));
    $cat = $type_parts[0] ?? '其他';
    $region = $type_parts[1] ?? '神秘區域';
    $stage_num = intval($type_parts[2] ?? 0);
    $grouped_stages[$cat][$region][$stage_num] = $s;
}
foreach ($grouped_stages as $cat => &$regions) {
    foreach ($regions as $region => &$stages) {
        ksort($stages);
    }
}

// 在處理 POST 的邏輯裡加入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'calculate_stats') {
    $type = $_POST['type'];
    $level = intval($_POST['level']);
    $diff = $_POST['diff'];
    
    // 使用你現有的 PHP 函數
    $stats = generateEnemyStats($type, $level, $diff);
    // 可選：加上隨機變動
    $stats = applyRandomVariation($stats);
    
    echo json_encode(['status' => 'success', 'data' => $stats]);
    exit;
}
// echo "<h3>目前的 $grouped_stages 結構：</h3>";
// echo "<pre>";
// print_r($grouped_stages);
// echo "</pre>";

// // 暫停程式，防止後面亂七八糟的 HTML 干擾視覺
// exit("除錯中斷：檢查完畢請刪除或註解此段代碼");

?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .modal-content { background: #1a1a2e; border: 2px solid #4a4a6a; border-radius: 12px; color: white; max-width: 850px; width: 95%; padding: 30px; }
        .modal-scroll { max-height: 80%;}
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 10px; }
        .squad-row { display: flex; gap: 5px; background: #252545; padding: 8px; margin-bottom: 5px; border-radius: 5px; }
        .input-dark2 { background: #0f0f1a; border: 1px solid #333; color: white; padding: 8px; border-radius: 4px; width: 100%; }
        .enemy-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.85rem; }
        .enemy-table th { color: var(--accent-green); text-align: center; padding: 8px; border-bottom: 1px solid #444; }
        .enemy-table td { padding: 5px; }
        .enemy-table td:first-child { width: 80px; font-weight: bold; color: var(--accent-gold); background: #1a1a2e; text-align: center; }
        .enemy-table input, .enemy-table select { width: 100%; background: #0f0f1a; border: 1px solid #333; color: white; padding: 4px; border-radius: 3px; }
        .enemy-table :is(input, select) { width: 100%; min-width: 55px; box-sizing: border-box; padding: 4px; }
        .btn-sort { cursor: pointer; background: #333; border: none; color: white; padding: 2px 8px; border-radius: 4px; }
        .btn-gen { cursor: pointer; background: #27ae60; border: none; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }
    </style>
    <title>關卡管理系統</title>
</head>
<body>
<div class="main-container">
    <!-- 標題區 -->
    <div class="header text-h1">
        關卡管理系統
        <button class="btn-base btn-red-info" onclick="window.location.href='dashboard.php'">🚪 返回首頁</button>
    </div>
    <div class="worship-bar text-gold" id="msg-display">
        通知訊息：<?php if (!empty($_SESSION['msg'])): echo $_SESSION['msg']; unset($_SESSION['msg']);?><?php endif; ?>
    </div>
    <!-- from提交 -->
    <div id="editor-modal" class="modal-overlay" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="text-h2 text-green">📖 關卡配置</div>
            <div class="modal-scroll">
                <form id="save-stage-form" onsubmit="submitForm(event)">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="form-grid">
                        <select name="cat" id="edit-cat" class="input-dark2" onchange="updateRegions()">
                            <option value="主線">主線</option>
                            <option value="副本">副本</option>
                        </select>
                        <select name="region" id="edit-region" class="input-dark2"></select>
                    </div>
                    <div class="form-grid" style="grid-template-columns: repeat(4, 1fr); align-items: center; gap: 8px; font-size: 0.9rem;">
                        <div>關卡名稱：<input type="text"   name="name"      id="edit-name"      class="input-dark2"></div>
                        <div>體力消耗：<input type="number" name="stamina"   id="edit-stamina"   class="input-dark2" min="1" step="1"></div>
                        <div>建議等級：<input type="number" name="rec_level" id="edit-rec_level" class="input-dark2" min="1" step="1"></div>
                        <div>關卡序列：<input type="number" name="stage_num" id="edit-stage_num" class="input-dark2" min="1" step="1"></div>
                    </div>

                    <div id="enemy-config-form"></div>
                    <button type="submit" class="btn-main w-full">💾 保存關卡</button>
                </form>
            </div>
            <button class="manual-btn-close" onclick="document.getElementById('editor-modal').style.display='none'">關閉</button>
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
                <?php foreach ($stages_list as $num => $s): ?>
                <details class="manual-box stage-card <?= $is_locked ? 'stage-locked' : '' ?>">
                    <summary class="stage-summary flex_gap5">
                        <button class="btn-info" onclick="event.preventDefault(); editStage(<?= htmlspecialchars(json_encode($s)) ?>)">✎ 修改</button>
                        <div>
                            <strong><?= $num.'. '.$s['name'] ?></strong>
                            <span class="text-small">LV.<?= $s['rec_level'] ?></span>
                            <span class="text-red">體力:<?= $s['stamina_cost'] ?></span>
                        </div>
                        <div>陣容: 
                            <?php 
                            foreach($s['enemies'] as $e): ?>
                                <span class="tag-<?= $e['type'] ?>"><?= $icons[$e['type']] ?? '❓' ?> Lv.<?= $e['level'] ?></span>
                            <?php endforeach; ?>
                        </div>
                    </summary>
                    <div class="stage-detail-content"> <!-- 詳細-->
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
                        <div class="text-bitty">
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
                </details>
                <?php endforeach; ?>
                <div class="mt-10">
                    <button class="btn-main" onclick="editStage({type: '<?= $active_cat.'-'.$region.'-0' ?>' })">+ 新增關卡</button>
                </div>

            </div>
        </details>
        <?php endforeach; ?>
    </div>
</div>
<script>
// 統一的訊息顯示函式
function showMsg(res) {
    if (res.alert) alert(res.alert); // alert 警告
    const msgEl = document.getElementById('msg-display');
    msgEl.textContent = '通知訊息：' + res.message;
}
// 事件處理
const handlers = {
    // 預設操作：重整頁面
    default: (res) => {
        sessionStorage.setItem('pending_msg', JSON.stringify(res));
        window.location.href = window.location.pathname;
    }
};
async function doAction(action, data = null) {
    const formData = new FormData();
    formData.append('action', action);

    // 自動處理 FormData 或普通物件
    if (data instanceof FormData) {
        for (let [key, value] of data.entries()) {
            formData.append(key, value);
        }
    } else if (data && typeof data === 'object') {
        for (let key in data) {
            formData.append(key, data[key]);
        }
    }

    try {
        const response = await fetch('dashboard.php', { method: 'POST', body: formData });
        const text = await response.text();
        let res;
        try {
            res = JSON.parse(text);
        } catch (e) {
            console.error("原始回應內容 (PHP 錯誤):", text);
            throw new Error("伺服器回應格式錯誤");
        }

        showMsg(res);
        if (res.status === 'success') {
            // 優先執行對應的 handler，否則執行預設動作
            (handlers[action] || handlers.default)(res, formData);
        }
    } catch (e) { console.error("系統異常:", e); }
}
// --- 表單送出處理 ---
async function submitForm(e) {
    e.preventDefault();
    const formEl = document.getElementById('save-stage-form');
    // 將所有表單內容打包送出
    await doAction('save_stage', new FormData(formEl));
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
    const lastOpenId = localStorage.getItem('open_fox');
    if (lastOpenId) document.getElementById('card-' + lastOpenId).open = true;
};

// 配置定義
const stageConfigs = <?= json_encode($stage_configs) ?>;
const foxItems = <?= json_encode($fox_items) ?>;
const enemyNames = <?= json_encode($enemy_names) ?>;
// DOM 行交換邏輯
function moveRow(btn, direction) {
    const currentRow = btn.closest('tr');
    const siblingRow = direction === -1 ? currentRow.previousElementSibling : currentRow.nextElementSibling;

    if (siblingRow) {
        // 先抓取交換前的名稱，防止被清空
        const nameSelect1 = currentRow.querySelector('select[name^="e_name"]');
        const nameSelect2 = siblingRow.querySelector('select[name^="e_name"]');
        const oldName1 = nameSelect1.value;
        const oldName2 = nameSelect2.value;

        // 交換所有 input/select 的 value
        const currentInputs = currentRow.querySelectorAll('input, select');
        const siblingInputs = siblingRow.querySelectorAll('input, select');

        currentInputs.forEach((el, index) => {
            const siblingEl = siblingInputs[index];
            if (siblingEl) {
                const temp = el.value;
                el.value = siblingEl.value;
                siblingEl.value = temp;
            }
        });

        // 強制更新兩行的選項清單，並把剛剛記住的名字塞回去
        const typeSelect1 = currentRow.querySelector('select[name^="e_type"]');
        const typeSelect2 = siblingRow.querySelector('select[name^="e_type"]');
        
        updateEnemyNameOptions(typeSelect1, oldName2); // 注意：這裡交換了
        updateEnemyNameOptions(typeSelect2, oldName1);
        
        // 確保選單值正確選中
        currentRow.querySelector('select[name^="e_name"]').value = oldName2;
        siblingRow.querySelector('select[name^="e_name"]').value = oldName1;
    }
}
// 2. updateEnemyNameOptions 保持你改好的版本
function updateEnemyNameOptions(typeSelect, keepName = '') {
    const row = typeSelect.closest('tr');
    const nameSelect = row.querySelector('select[name^="e_name"]');
    const type = typeSelect.value;
    
    let html = '<option value="">(請選擇名稱)</option>';
    if (enemyNames[type]) {
        enemyNames[type].forEach(name => {
            html += `<option value="${name}">${name}</option>`;
        });
    }
    nameSelect.innerHTML = html;
    
    // 如果有指定要保留的名字，就把它設回去
    if (keepName) nameSelect.value = keepName;
}
// 處理獎勵類別 UI 切換
const switchRewardType = (el) => {
    const row = el.closest('tr');
    const isItem = el.value === 'item';
    row.querySelector('.merit-label').style.display = isItem ? 'none' : 'block';
    row.querySelector('.item-select').style.display = isItem ? 'block' : 'none';
};
// 產生獎勵 HTML (精簡版)
function addRewardRow(type = 'merit', name = '', amount = 1, prob = 100) {
    const tbody = document.querySelector('#reward-table tbody');
    const tr = document.createElement('tr');
    
    // 建立選項群組
    const groups = Object.entries(<?= json_encode($fox_items) ?>).reduce((acc, [n, d]) => {
        (acc[d.type] = acc[d.type] || []).push(n); return acc;
    }, {});

    const options = Object.entries(groups).map(([t, items]) => {
        const cfg = <?= json_encode($itemConfig) ?>[t] || { label: '其他', icon: '📦' };
        return `<optgroup label="${cfg.icon}${cfg.label}">${items.map(n => `<option value="${n}" ${name===n?'selected':''}>${n}</option>`).join('')}</optgroup>`;
    }).join('');

    tr.innerHTML = `
        <td><select name="r_type[]" onchange="switchRewardType(this)">
            <option value="merit" ${type==='merit'?'selected':''}>功德</option>
            <option value="item" ${type==='item'?'selected':''}>道具</option>
        </select></td>
        <td>
            <span class="merit-label" style="display:${type==='merit'?'block':'none'}">功德</span>
            <select name="r_name[]" class="item-select" style="display:${type==='item'?'block':'none'}">${options}</select>
        </td>
        <td><input type="number" name="r_amount[]" value="${amount}" min="1"></td>
        <td><input type="number" name="r_prob[]"   value="${prob}"   min="0"></td>
        <td><button type="button" class="btn-sort" onclick="this.closest('tr').remove()">❌</button></td>
    `;
    tbody.appendChild(tr);
}
// 更新區域
function updateRegions(selectedRegion = '') {
    const cat = document.getElementById('edit-cat').value;
    const regionSelect = document.getElementById('edit-region');
    const regions = Object.keys(stageConfigs[cat]);
    regionSelect.innerHTML = regions.map(r => `<option value="${r}" ${r == selectedRegion ? 'selected' : ''}>${r}</option>`).join('');
}
// 編輯關卡 (彈窗)
async function editStage(customParams = null) {
    const defaults = {
        id: 0,
        name: '',
        stamina_cost: 1,
        rec_level: 1,
        type: '主線-雪山村落-1',
        enemies: [],
        rewards: { merit: [], item: [] }
    };
    // 將傳入的 customParams 合併覆蓋到 defaults 上
    const data = { ...defaults, ...customParams };

    const [cat, reg, stageNum] = (data.type || '主線-未知地區-1').split('-');
    
    document.getElementById('edit-id').value = data.id;
    document.getElementById('edit-cat').value = cat;
    document.getElementById('edit-stage_num').value = stageNum;
    document.getElementById('edit-name').value = data.name;
    document.getElementById('edit-stamina').value = data.stamina_cost;
    document.getElementById('edit-rec_level').value = data.rec_level;
    updateRegions(reg);
    
    const pos = ['先鋒', '後衛上', '後衛下'];
    document.getElementById('enemy-config-form').innerHTML = `
        <table class="enemy-table"><thead><tr><th>位置</th><th>屬性</th><th>名稱</th><th>等級</th><th>HP</th><th>ATK</th><th>DEF</th><th>操作</th></tr></thead>
        <tbody>${pos.map((p, i) => {
            const e = data.enemies[i] || {type:'none', name:'', level:1, hp:0, atk:0, def:0};
            const names = enemyNames[e.type] || [];
            return `<tr>
                <td>${p}</td>
                <td><select name="e_type[${i}]" onchange="updateEnemyNameOptions(this)">${['fire','water','wind','light','dark','none'].map(t=>`<option value="${t}" ${e.type==t?'selected':''}>${t}</option>`).join('')}</select></td>
                <td><select name="e_name[${i}]"><option value="">(請選擇名稱)</option>${names.map(n => `<option value="${n}" ${e.name==n?'selected':''}>${n}</option>`).join('')}</select></td>
                <td><input type="number" name="e_level[${i}]" value="${e.level}" min="1"></td>
                <td><input type="number" name="e_hp[${i}]"    value="${e.hp}"    min="0"></td>
                <td><input type="number" name="e_atk[${i}]"   value="${e.atk}"   min="0"></td>
                <td><input type="number" name="e_def[${i}]"   value="${e.def}"   min="0"></td>
                <td>
                    <select class="btn-gen" onchange="autoGenerateStats(this)">
                        <option value="">生成</option><option value="easy">簡易</option><option value="normal">標準</option><option value="hard">困難</option><option value="super">地獄</option>
                    </select>
                    <button type="button" class="btn-sort" onclick="moveRow(this,-1)">▲</button>
                    <button type="button" class="btn-sort" onclick="moveRow(this,1)">▼</button>
                </td>
            </tr>`;
        }).join('')}</tbody></table>
        <div class="text-nomal text-red mt-10">🎁 掉落物設定</div>
        <table class="enemy-table" id="reward-table"><thead><tr><th>類型</th><th>名稱</th><th>數量</th><th>機率(%)</th><th>操作</th></tr></thead><tbody></tbody></table>
        <button type="button" class="btn-info" onclick="addRewardRow()">+ 新增獎勵</button>`;

    [...(data.rewards.merit||[]).map(m=>({type:'merit', name:'功德', ...m, prob:m.probability*100})),
     ...(data.rewards.item||[]).map(i=>({type:'item', name:i.item_name, ...i, prob:i.probability*100}))
    ].forEach(r => addRewardRow(r.type, r.name, r.amount, r.prob));

    document.getElementById('editor-modal').style.display = 'block';
}
// 屬性變更時，連動更新名稱選單
function updateEnemyNameOptions(typeSelect, keepName = '') {
    const row = typeSelect.closest('tr');
    const nameSelect = row.querySelector('select[name^="e_name"]');
    const type = typeSelect.value;
    let html = '<option value="">(請選擇名稱)</option>';
    if (enemyNames[type]) {
        enemyNames[type].forEach(name => {
            html += `<option value="${name}">${name}</option>`;
        });
    }
    nameSelect.innerHTML = html;
    if (keepName) nameSelect.value = keepName;
}
// 前端簡易計算邏輯 (需對應 PHP 的係數)
async function autoGenerateStats(selectEl) {
    const row = selectEl.closest('tr');
    const type = row.querySelector('select[name^="e_type"]').value;
    const level = parseInt(row.querySelector('input[name^="e_level"]').value) || 1;
    const diff = selectEl.value;
    
    if (!diff) return;

    // 準備發送到 PHP 的資料
    const formData = new FormData();
    formData.append('action', 'calculate_stats');
    formData.append('type', type);
    formData.append('level', level);
    formData.append('diff', diff);

    try {
        const response = await fetch('manage_stage.php', { method: 'POST', body: formData });
        const res = await response.json();
        
        if (res.status === 'success') {
            // 將 PHP 計算好的數值填回對應的 input
            row.querySelector('input[name^="e_hp"]').value = res.data.hp;
            row.querySelector('input[name^="e_atk"]').value = res.data.atk;
            row.querySelector('input[name^="e_def"]').value = res.data.def;
            // 如果你有戰力顯示欄位，也可以順便更新
            // row.querySelector('input[name^="e_power"]').value = res.data.power;
        }
    } catch (e) {
        console.error("計算失敗:", e);
    }
    
    selectEl.value = ""; // 重置選單
}
</script>

</body>
</html>