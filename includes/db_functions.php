<?php
// includes/db_functions.php
require_once '../config/config.php';

$actionHandlers = [
    'get_player' => function($user_id) use ($pdo){ // 獲取玩家
        $stmt = $pdo->prepare("
            SELECT p.name, p.merit, p.stamina, p.support_fox, f.* FROM players p 
            LEFT JOIN foxes f ON p.support_fox = f.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    },
    'get_inventory' => function($user_id) use ($pdo){ // 獲取背包物件
        $stmt = $pdo->prepare("SELECT item_name, quantity FROM player_inventory WHERE player_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    },
    'get_foxes' => function($user_id) use ($pdo){ // 獲取狐狸
        $stmt = $pdo->prepare("SELECT * FROM foxes WHERE player_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    },
    'get_squad_fox' => function($user_id, $current_squad_num) use ($pdo){ // 獲取隊伍
        $stmt = $pdo->prepare("SELECT pos_1, pos_2, pos_3 FROM squads WHERE player_id = ? AND squad_num = ?");
        $stmt->execute([$user_id, $current_squad_num]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    },
    'get_stages' => function($user_id) use ($pdo){ // 獲取關卡資訊
        $stmt = $pdo->prepare("
            SELECT s.*, 
                (SELECT 1 FROM player_stages ps WHERE ps.stage_id = s.id AND ps.player_id = ?) as is_cleared,
                (SELECT GROUP_CONCAT(CONCAT(type, ':', level, ':', pos, ':', hp, ':', atk, ':', def, ':', name) 
                    ORDER BY pos SEPARATOR ','
                ) FROM stage_enemies se WHERE se.stage_id = s.id) as enemy_info,
                (SELECT GROUP_CONCAT(CONCAT(type, ':', IFNULL(item_name, ''), ':', amount, ':', probability) SEPARATOR '|') 
                FROM stage_rewards sr 
                WHERE sr.stage_id = s.id) as reward_info
            FROM stages s
            ORDER BY s.type, s.id
        ");
        
        $stmt->execute([$user_id]);
        $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($stages as &$s) {
            // 1. 解析敵人資訊
            $enemy_data = [];
            if (!empty($s['enemy_info'])) {
                foreach (explode(',', $s['enemy_info']) as $e_str) {
                    // 現在切出來的陣列有 7 個項目
                    list($type, $level, $pos, $hp, $atk, $def, $name) = explode(':', $e_str);
                    $enemy_data[] = [
                        'type'  => $type,
                        'level' => $level,
                        'pos'   => $pos,
                        'hp'    => $hp,
                        'atk'   => $atk,
                        'def'   => $def,
                        'name'  => $name,
                        'power' => get_power($hp, $atk, $def),
                    ];
                }
            }
            $s['enemies'] = $enemy_data;

            // 2. 解析詳細獎勵資訊
            $rewards = ['item' => [], 'merit' => []]; // 分類存放
            if (!empty($s['reward_info'])) {
                foreach(explode('|', $s['reward_info']) as $r_str) {
                    // 現在結構多了 type: name: amount: prob
                    list($type, $name, $amount, $prob) = explode(':', $r_str);
                    $reward_item = [
                        'amount'      => (int)$amount,
                        'probability' => (float)$prob
                    ];
                    
                    if ($type === 'merit') {
                        $rewards['merit'][] = $reward_item;
                    } else {
                        $reward_item['item_name'] = $name;
                        $rewards['item'][] = $reward_item;
                    }
                }
            }
            $s['rewards'] = $rewards;

            // 3. 清理暫存欄位
            unset($s['enemy_info'], $s['reward_info']);
        }
        return $stages;
    },
    'get_helpers' => function($user_id) use ($pdo) { // 獲取助戰
        // 修正：在 f.* 後補上逗號，並指定欄位別名以避免與 p.id 衝突
        $sql = "
            SELECT p.id as player_id, p.name as player_name, 
                f.id as fox_id, f.player_id, f.type, f.level, f.exp, 
                f.hp_tier, f.atk_tier, f.def_tier,
                (CASE WHEN fw.status = 'accepted' THEN 1 ELSE 0 END) as is_friend,
                (CASE WHEN p.last_login >= DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as is_active
            FROM players p
            LEFT JOIN foxes f ON p.support_fox = f.id
            LEFT JOIN follows fw ON (fw.follower_id = ? AND fw.following_id = p.id) 
                                OR (fw.following_id = ? AND fw.follower_id = p.id)
            WHERE p.id != ? AND f.id IS NOT NULL
            ORDER BY is_friend DESC, is_active DESC, RAND()
            LIMIT 15
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id, $user_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $helpers = [];
        foreach ($results as $row) {
            // 將撈出的資料整理成 get_fox_stats 需要的格式
            // 確保這裡的 key 對應你 get_fox_stats 裡面的需求
            $fox_data = [
                'id'       => $row['fox_id'],
                'type'     => $row['type'],
                'level'    => $row['level'],
                'exp'      => $row['exp'],
                'hp_tier'  => $row['hp_tier'],
                'atk_tier' => $row['atk_tier'],
                'def_tier' => $row['def_tier']
            ];
            
            $stats = get_fox_stats($fox_data);
            
            $helpers[] = [
                'player_id'   => $row['player_id'],
                'player_name' => $row['player_name'],
                'is_friend'   => $row['is_friend'],
                'is_active'   => $row['is_active'],
                'fox'         => $stats
            ];
        }

        return ['status' => 'success', 'data' => $helpers];
    },
    'rename' => function($user_id) use ($pdo){ // 改名
        $name = trim($_POST['new_name'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 10) return ['status' => 'error', 'message' => '名稱需 2-10 字'];
        $pdo->prepare("UPDATE players SET name = ? WHERE id = ?")->execute([$name, $user_id]);
        return ['status' => 'success', 'message' => '修改成功！'];
    },
    'worship' => function($user_id) use ($pdo) { // 參拜
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("UPDATE players SET merit = merit + ?, stamina = stamina + ?, last_sign_date = ? WHERE id = ? AND (last_sign_date IS NULL OR last_sign_date != ?)");
        $stmt->execute([50, 20, $today, $user_id, $today]);
        if ($stmt->rowCount() > 0) {
            $new_merit = $pdo->query("SELECT merit FROM players WHERE id = $user_id")->fetchColumn();
            return ['status' => 'success', 'message' => '✨ 功德+50, 體力+20', 'new_merit' => $new_merit];
        }
        return ['status' => 'error', 'message' => '🙏 今日已參拜'];
    },
    'add_friend' => function($user_id) use ($pdo) { // 請求成為好友
        $target_id = (int)$_POST['id'];
        if ($target_id === $user_id) return ['status' => 'error', 'alert' => '不能加自己'];
        $pdo->prepare("INSERT INTO follows (follower_id, following_id, status) VALUES (?, ?, 'pending')")->execute([$user_id, $target_id]);
        return ['status' => 'success', 'message' => '已送出申請'];
    },
    'accept_friend' => function($user_id) use ($pdo){ // 允許成為好友
        $pdo->prepare("UPDATE follows SET status = 'accepted' WHERE follower_id = ? AND following_id = ?")->execute([(int)$_POST['id'], $user_id]);
        return ['status' => 'success', 'message' => '已成為好友！'];
    },
    'remove_friend' => function($user_id) use ($pdo){ // 刪除or拒絕成為好友
        $target_id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM follows WHERE (follower_id = ? AND following_id = ?) OR (follower_id = ? AND following_id = ?)")
            ->execute([$user_id, $target_id, $target_id, $user_id]);
        return ['status' => 'success', 'message' => '已解除關係'];
    },
    'get_friend_status' => function($user_id) use ($pdo) { // 獲取好友狀態
        $type = $_POST['type'] ?? 'list';
        $q    = $_POST['q'] ?? '';
        $baseFields = "p.id, p.name, p.last_login, f.type, f.level";
        $configs = [
            // 注意：這裡移除了 pc 表的 JOIN，改用 p.support_fox
            'list'    => ["SELECT $baseFields, 'accepted' as status FROM players p JOIN follows fw ON (p.id = fw.following_id OR p.id = fw.follower_id) LEFT JOIN foxes f ON p.support_fox = f.id WHERE (fw.follower_id = ? OR fw.following_id = ?) AND fw.status = 'accepted' AND p.id != ? GROUP BY p.id", [$user_id, $user_id, $user_id]],
            'request' => ["SELECT $baseFields, (CASE WHEN fw.follower_id = ? THEN 'pending' ELSE 'incoming' END) as status FROM follows fw JOIN players p ON (p.id = fw.follower_id OR p.id = fw.following_id) LEFT JOIN foxes f ON p.support_fox = f.id WHERE (fw.following_id = ? OR fw.follower_id = ?) AND fw.status = 'pending' AND p.id != ?", [$user_id, $user_id, $user_id, $user_id]],
            'find'    => ["SELECT $baseFields, (SELECT CASE WHEN status IS NULL THEN 'none' WHEN status = 'accepted' THEN 'accepted' WHEN status = 'pending' AND follower_id = ? THEN 'pending' WHEN status = 'pending' AND follower_id != ? THEN 'incoming' ELSE 'none' END FROM follows WHERE (follower_id = ? AND following_id = p.id) OR (follower_id = p.id AND following_id = ?)) as status FROM players p LEFT JOIN foxes f ON p.support_fox = f.id WHERE (p.name LIKE ? OR p.id = ?) AND p.id != ? LIMIT 10", [$user_id, $user_id, $user_id, $user_id, "%$q%", $q, $user_id]],
            'random'  => ["SELECT $baseFields, (SELECT CASE WHEN status IS NULL THEN 'none' WHEN status = 'accepted' THEN 'accepted' WHEN status = 'pending' AND follower_id = ? THEN 'pending' WHEN status = 'pending' AND follower_id != ? THEN 'incoming' ELSE 'none' END FROM follows WHERE (follower_id = ? AND following_id = p.id) OR (follower_id = p.id AND following_id = ?)) as status FROM players p LEFT JOIN foxes f ON p.support_fox = f.id WHERE p.id != ? ORDER BY RAND() LIMIT 10", [$user_id, $user_id, $user_id, $user_id, $user_id]],
            'self'    => ["SELECT $baseFields, 'self' as status FROM players p LEFT JOIN foxes f ON p.support_fox = f.id WHERE p.id = ?", [$user_id]],
        ];
        if (isset($configs[$type])) {
            $stmt = $pdo->prepare($configs[$type][0]);
            $stmt->execute($configs[$type][1]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($type === 'list') {
                $s_stmt = $pdo->prepare($configs['self'][0]);
                $s_stmt->execute($configs['self'][1]);
                $self = $s_stmt->fetch(PDO::FETCH_ASSOC);
                array_unshift($data, $self);
            }
            $counts = $pdo->prepare("SELECT (SELECT COUNT(*) FROM follows WHERE (follower_id = ? OR following_id = ?) AND status = 'accepted') as f, (SELECT COUNT(*) FROM follows WHERE following_id = ? AND status = 'pending') as r");
            $counts->execute([$user_id, $user_id, $user_id]);
            return ['status' => 'success', 'data' => $data, 'counts' => $counts->fetch(PDO::FETCH_ASSOC)];
        }
    },
    'remove_squad' => function($user_id) use ($pdo){ // 移出隊伍
        $pos = (int)$_POST['pos'];
        if ($pos >= 1 && $pos <= 3) {
            $pdo->prepare("UPDATE squads SET pos_$pos = NULL WHERE player_id = ? AND squad_num = ?")
                ->execute([$user_id, $_SESSION['squad_id'] ?? 1]);
            return ['status' => 'success', 'message' => '🦊 狐狸已回休息區'];
        }
    },
    'switch_squad' => function($user_id) use ($pdo){  // 切換隊伍
        $new_squad = (int)$_POST['squad_num'];
        // 驗證是否在合法範圍
        if ($new_squad < 1 || $new_squad > 3) return ['status' => 'error', 'message' => '無效隊伍'];
        $_SESSION['squad_id'] = $new_squad; // 更新 Session
        $_SESSION['msg'] = '切換至隊伍 ' . $new_squad;
        return ['status' => 'success', 'message' => '切換至隊伍 ' . $new_squad];
    },
    'set_fox_filter' => function($user_id) use ($pdo) { // 設定篩選
        $current = $_SESSION['fox_filter'] ?? ['type' => 'all', 'sort' => 'level', 'order' => 'DESC'];
        $_SESSION['fox_filter'] = [
            'type'  => $_POST['type']  ?? $current['type'],
            'sort'  => $_POST['sort']  ?? $current['sort'],
            'order' => $_POST['order'] ?? $current['order']
        ];
        return ['status' => 'success', 'message' => '篩選條件已更新'];
    },
    'set_support' => function($user_id) use ($pdo) { // 更新支援狀態
        $fox_id = (int)$_POST['fox_id'];
        $stmt = $pdo->prepare("SELECT id FROM foxes WHERE id = ? AND player_id = ?");
        $stmt->execute([$fox_id, $user_id]);
        if (!$stmt->fetch()) return ['status' => 'error', 'alert' => '這不是你的靈狐'];
        $pdo->prepare("UPDATE players SET support_fox = ? WHERE id = ?")->execute([$fox_id, $user_id]);
        return ['status' => 'success', 'message' => '✨ 助戰靈狐已更換'];
    },
    'set_squad' => function($user_id) use ($pdo) { // 隊伍上陣
        $fox_id = (int)$_POST['fox_id'];
        $target_col = "pos_" . (int)$_POST['pos'];
        $squad_num = $_SESSION['squad_id'] ?? 1;
        // 驗證所有權
        $stmt = $pdo->prepare("SELECT 1 FROM foxes WHERE id = ? AND player_id = ?");
        $stmt->execute([$fox_id, $user_id]);
        if (!$stmt->fetch()) return ['status' => 'error', 'message' => '🦊 無效的靈狐'];
        // 確保隊伍存在
        $pdo->prepare("INSERT IGNORE INTO squads (player_id, squad_num) VALUES (?, ?)")->execute([$user_id, $squad_num]);
        // 3. 核心邏輯：
        // a. 把狐狸從舊位置移走 (如果它在隊伍裡，舊位置變為 NULL)
        // b. 將目標位置直接放入新狐狸
        $pdo->prepare("UPDATE squads SET pos_1 = IF(pos_1=?, NULL, pos_1), pos_2 = IF(pos_2=?, NULL, pos_2), pos_3 = IF(pos_3=?, NULL, pos_3) WHERE player_id = ? AND squad_num = ?")
            ->execute([$fox_id, $fox_id, $fox_id, $user_id, $squad_num]);
        $pdo->prepare("UPDATE squads SET $target_col = ? WHERE player_id = ? AND squad_num = ?")
            ->execute([$fox_id, $user_id, $squad_num]);

        return ['status' => 'success', 'message' => '🦊 陣容已更新'];
    },
    'feed_fox' => function($user_id) use ($pdo, $fox_items) { // 餵養道具
        $fox_id = (int)$_POST['fox_id'];
        $item_name = $_POST['item_name'] ?? '';
        $qty = (int)($_POST['qty'] ?? 1);
        // 1. 驗證擁有權與道具存在
        if ($qty <= 0) return ['status' => 'error', 'message' => '數量錯誤'];
        // 2. 檢查背包內道具數量
        $stmt = $pdo->prepare("SELECT quantity FROM player_inventory WHERE player_id = ? AND item_name = ? FOR UPDATE");
        $stmt->execute([$user_id, $item_name]);
        $current_qty = $stmt->fetchColumn();

        if (!$current_qty || $current_qty < $qty) {
            return ['status' => 'error', 'message' => '道具不足'];
        }
        // 3. 執行養成邏輯 (呼叫config餵養函數)
        $actual_fed = apply_item_effect($fox_id, $item_name, $qty);
        if ($actual_fed > 0) {
            $pdo->prepare("UPDATE player_inventory SET quantity = quantity - ? WHERE player_id = ? AND item_name = ?")
                ->execute([$actual_fed, $user_id, $item_name]);
            
            // 獲取最新狀態供前端更新介面
            $new_stats = $pdo->prepare("SELECT level, exp, hp_tier, atk_tier, def_tier FROM foxes WHERE id = ?");
            $new_stats->execute([$fox_id]);
            $fox_data = $new_stats->fetch(PDO::FETCH_ASSOC);

            return [
                'status' => 'success', 
                'message' => "成功餵養 $actual_fed 個 $item_name",
                'fox_data' => $fox_data
            ];
        }
        else {
            return ['status' => 'error', 'message' => '餵養失敗（可能已達上限）'];
        }
    },
    'switch_filter' => function($user_id) use ($pdo){
        $_SESSION['stage_cat'] = $_POST['cat'] ?? '主線';
        $_SESSION['stage_sub'] = $_POST['sub'] ?? 'all';
        return ['status' => 'success', 'message' => '切換篩選成功'];
    },
    'save_stage' => function($user_id) use ($pdo) {
        try {
            $id = intval($_POST['id'] ?? 0);
            $cat = $_POST['cat'] ?? '主線';
            $region = $_POST['region'] ?? '神秘區域';
            $stage_num = intval($_POST['stage_num'] ?? 0);
            $type_str = "$cat-$region-$stage_num";

            // --- 檢查邏輯 ---
            // 檢查是否存在同樣的 type，但排除當前編輯中的關卡本身
            $checkStmt = $pdo->prepare("SELECT id FROM stages WHERE type = ? AND id != ?");
            $checkStmt->execute([$type_str, $id]);
            if ($checkStmt->fetch()) {
                return ['status' => 'error', 'message' => "失敗：類型 [{$type_str}] 已存在，請更換關卡序列或區域。"];
            }
            // -----------------------
            $pdo->beginTransaction();
            $name = $_POST['name'] ?? '未命名';
            $stamina = max(1, intval($_POST['stamina'] ?? 1)); // 強制最小為 1
            $rec_level = max(1, intval($_POST['rec_level'] ?? 1));
            // 更新或新增關卡基本資料
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE stages SET type=?, name=?, stamina_cost=?, rec_level=? WHERE id=?");
                $stmt->execute([$type_str, $name, $stamina, $rec_level, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO stages (type, name, stamina_cost, rec_level) VALUES (?, ?, ?, ?)");
                $stmt->execute([$type_str, $name, $stamina, $rec_level]);
                $id = $pdo->lastInsertId();
            }

            // 2. 清除舊的敵人與獎勵 (簡單的重新覆寫策略)
            $pdo->prepare("DELETE FROM stage_enemies WHERE stage_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM stage_rewards WHERE stage_id=?")->execute([$id]);

            // 3. 寫入敵人
            if (!empty($_POST['e_type'])) {
                $stmt = $pdo->prepare("INSERT INTO stage_enemies 
                    (stage_id, type, name, level, hp, atk, def, pos) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                // 使用 foreach 遍歷帶索引的陣列
                foreach ($_POST['e_type'] as $pos_index => $type) {
                    $name = trim($_POST['e_name'][$pos_index] ?? '');
                    if ($name === '') continue; // 名稱為空則跳過
                    $level = intval($_POST['e_level'][$pos_index] ?? 1);
                    $hp    = intval($_POST['e_hp'][$pos_index] ?? 100);
                    $atk   = intval($_POST['e_atk'][$pos_index] ?? 10);
                    $def   = intval($_POST['e_def'][$pos_index] ?? 1);
                    // $pos_index 從 0, 1, 2 開始，對應資料庫的 1, 2, 3
                    $pos = $pos_index + 1; 
                    $stmt->execute([$id, $type, $name, $level, $hp, $atk, $def, $pos]);
                }
            }

            // 4. 寫入獎勵
            if (!empty($_POST['r_type'])) {
                $stmt = $pdo->prepare("INSERT INTO stage_rewards (stage_id, type, item_name, amount, probability) VALUES (?,?,?,?,?)");
                foreach ($_POST['r_type'] as $i => $type) {
                    $stmt->execute([
                        $id, $type, ($_POST['r_name'][$i] ?? null), intval($_POST['r_amount'][$i]), (floatval($_POST['r_prob'][$i]) / 100)
                    ]);
                }
            }

            $pdo->commit(); // 提交事務
            return ['status' => 'success', 'message' => '關卡資料已同步更新至資料庫。'];

        } catch (Exception $e) {
            $pdo->rollBack(); // 發生錯誤則復原
            return ['status' => 'error', 'message' => '儲存失敗: ' . $e->getMessage()];
        }
    },
    'start_battle' => function($user_id) use ($pdo, $icons) {
        // 取得 POST 數據
        $stage_id = (int)($_POST['stage_id'] ?? 0);
        $h_id = isset($_POST['helper_id']) ? (int)$_POST['helper_id'] : null;
        $h_pos = isset($_POST['helper_pos']) ? (int)$_POST['helper_pos'] : null;

        // A. 驗證關卡與體力成本
        $stmt = $pdo->prepare("SELECT stamina_cost FROM stages WHERE id = ?");
        $stmt->execute([$stage_id]);
        $stage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stage) return ['status' => 'error', 'message' => '關卡不存在'];

        // 使用 FOR UPDATE 鎖定玩家資料，防止併發扣除體力問題
        $stmt = $pdo->prepare("SELECT stamina FROM players WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $current_stamina = $stmt->fetchColumn();
        if ($current_stamina < $stage['stamina_cost']) {
            return ['status' => 'error', 'message' => '體力不足！'];
        }

        // B. 嚴格驗證助戰者
        $helper_info = null;
        if ($h_id !== null) {
            if ($h_id === $user_id) return ['status' => 'error', 'message' => '不可選擇自己作為助戰'];
            
            $stmt = $pdo->prepare("SELECT p.id, f.* FROM players p 
                                JOIN foxes f ON p.support_fox = f.id 
                                WHERE p.id = ?");
            $stmt->execute([$h_id]);
            $helper_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$helper_info) return ['status' => 'error', 'message' => '助戰者資訊無效'];
            if ($h_pos < 1 || $h_pos > 3) return ['status' => 'error', 'message' => '無效的戰鬥位置'];
        }

        // C. 撈出玩家當前陣容
        $squad_num = $_SESSION['squad_id'] ?? 1;
        $stmt = $pdo->prepare("SELECT pos_1, pos_2, pos_3 FROM squads WHERE player_id = ? AND squad_num = ?");
        $stmt->execute([$user_id, $squad_num]);
        $squad = $stmt->fetch(PDO::FETCH_ASSOC);

        $my_team = [];
        foreach(['pos_1', 'pos_2', 'pos_3'] as $col) {
            if (!empty($squad[$col])) {
                $f_stmt = $pdo->prepare("SELECT * FROM foxes WHERE id = ? AND player_id = ?");
                $f_stmt->execute([$squad[$col], $user_id]);
                $fox = $f_stmt->fetch(PDO::FETCH_ASSOC);
                if ($fox) {
                    $my_team[$col] = get_fox_stats($fox);
                }
            }
        }
        if (empty($my_team)) return ['status' => 'error', 'message' => '隊伍中沒有靈狐，無法戰鬥'];

        $stmt = $pdo->prepare("SELECT * FROM stage_enemies WHERE stage_id = ? ORDER BY pos ASC");
        $stmt->execute([$stage_id]);
        $enemies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($enemies)) return ['status' => 'error', 'message' => '關卡設定錯誤，無敵人資料'];
        // 格式化敵人資料，同樣轉成 pos_1, pos_2, pos_3 格式方便戰鬥計算
        $enemy_team = [];
        foreach($enemies as $index => $enemy) {
            $pos_key = "pos_" . ($index + 1);
            $enemy_team[$pos_key] = get_enemy_stats($enemy);
        }


        // D. 撈取獎勵資料
        $stmt = $pdo->prepare("SELECT type, item_name, amount, probability FROM stage_rewards WHERE stage_id = ?");
        $stmt->execute([$stage_id]);
        $all_rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 將獎勵整理成與你的戰鬥頁面相容的陣列結構
        $rewards = ['merit' => [], 'item' => []];
        foreach ($all_rewards as $r) $rewards[$r['type']][] = $r;

        // E. 執行事務處理 (扣體力 + 儲存快照)
        $pdo->beginTransaction();
        try {
            // 1. 扣除體力
            $pdo->prepare("UPDATE players SET stamina = stamina - ? WHERE id = ?")
                ->execute([$stage['stamina_cost'], $user_id]);

            // 2. 儲存戰鬥 Session 快照
            $_SESSION['battle'] = [
                'stage_id' => $stage_id,
                'rewards'    => $rewards,
                'enemy_team' => $enemy_team,
                'my_team'  => $my_team,
                'helper'   => $helper_info ? [
                    'player_id' => $h_id,
                    'pos'       => $h_pos,
                    'stats'     => get_fox_stats($helper_info)
                ] : null,
                'start_time' => time()
            ];

            $pdo->commit();
            return ['status' => 'success', 'message' => '進入戰鬥...'];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => '系統錯誤：無法開始戰鬥'];
        }
    },
];

// 餵養函數
function apply_item_effect($fox_id, $item_name, $qty = 1) {
    global $fox_items, $pdo, $level_max;
    if (!isset($fox_items[$item_name])) return false;
    $item = $fox_items[$item_name];
    $type = $item['type'];
    $pdo->beginTransaction();

    try {
        // 1. 鎖定並取得最新資料 (FOR UPDATE 防止併發衝突)
        $stmt = $pdo->prepare("SELECT exp, level, hp_tier, atk_tier, def_tier FROM foxes WHERE id = ? FOR UPDATE");
        $stmt->execute([$fox_id]);
        $fox = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fox) {
            $pdo->rollBack();
            return false;
        }

        // 2. 處理經驗道具
        if ($type === 'exp') {
            $val = (int)$item['value'];
            $total_exp = (int)$fox['exp'] + ($val * $qty);
            $lvl = (int)$fox['level'];
            
            // 抗壓迴圈：處理連續升級
            // 邏輯：0->1需100, 1->2需200, 2->3需300... (Lv * 100)
            while ($lvl < $level_max) {
                $exp_needed = ($lvl + 1) * 100;
                if ($total_exp >= $exp_needed) {
                    $total_exp -= $exp_needed;
                    $lvl++;
                }
                else break;
            }
            // 若達到滿級，經驗歸零
            if ($lvl >= $level_max) { 
                $lvl = $level_max; 
                $total_exp = 0; 
            }
            $update = $pdo->prepare("UPDATE foxes SET level = ?, exp = ? WHERE id = ?")->execute([$lvl, $total_exp, $fox_id]);
            $pdo->commit();
            return $qty; 
        }
        // 3. 處理資質道具 (智慧填滿邏輯)
        else {
            $actual_fed = 0;
            $max = $item['max'] ?? 999;
            for ($i = 0; $i < $qty; $i++) {
                // 找出當前最小值欄位
                $stats = [
                    'hp_tier' => $fox['hp_tier'],
                    'atk_tier' => $fox['atk_tier'],
                    'def_tier' => $fox['def_tier']
                ];
                asort($stats); // 從小到大排序
                $col = ($type === 'all_tier') ? key($stats) : $type;
                
                // 檢查是否達上限
                if ($fox[$col] >= $max) break; // 到達上限，停止餵食
                
                // 更新記憶體中的值，供下一次迴圈判斷
                $fox[$col]++;
                $actual_fed++;
                
                // 執行單顆加點
                $update = $pdo->prepare("UPDATE foxes SET $col = $col + 1 WHERE id = ?");
                $update->execute([$fox_id]);
            }

            if ($actual_fed === 0) {
                $pdo->rollBack();
                return false; // 一顆都餵不進去
            }
            
            $pdo->commit();
            return $actual_fed; // 回傳實際餵食數量
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

// 發放獎勵
function distributeRewards($user_id, $stage_id, $rewards, $pdo) {
    // 統一回傳結構
    $results = ['merit' => [], 'item' => []];
    
    try {
        $pdo->beginTransaction();

        // 1. 發放功德
        foreach ($rewards['merit'] as $m) {
            $won = (mt_rand() / mt_getrandmax()) <= (float)($m['probability'] ?? 1.0);
            if ($won) {
                $pdo->prepare("UPDATE players SET merit = merit + ? WHERE id = ?")
                    ->execute([(int)$m['amount'], $user_id]);
            }
            // 記錄狀態：包含原始資料與是否中獎
            $results['merit'][] = ['data' => $m, 'won' => $won];
        }

        // 2. 發放道具
        foreach ($rewards['item'] as $i) {
            $won = (mt_rand() / mt_getrandmax()) <= (float)($i['probability'] ?? 1.0);
            if ($won) {
                $stmt = $pdo->prepare("SELECT quantity FROM player_inventory WHERE player_id = ? AND item_name = ?");
                $stmt->execute([$user_id, $i['item_name']]);
                
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE player_inventory SET quantity = quantity + ? WHERE player_id = ? AND item_name = ?")
                        ->execute([(int)$i['amount'], $user_id, $i['item_name']]);
                } else {
                    $pdo->prepare("INSERT INTO player_inventory (player_id, item_name, quantity) VALUES (?, ?, ?)")
                        ->execute([$user_id, $i['item_name'], (int)$i['amount']]);
                }
            }
            // 記錄狀態
            $results['item'][] = ['data' => $i, 'won' => $won];
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO player_stages (player_id, stage_id, cleared_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $stage_id]);

        $pdo->commit();
        return $results; // 回傳包含中獎結果的陣列
    } catch (Exception $e) {
        $pdo->rollBack();
        die("發獎錯誤: " . $e->getMessage());
        return false;
    }
}

?>