-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2026-06-19 16:58:58
-- 伺服器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `fox_game`
--

-- --------------------------------------------------------

--
-- 資料表結構 `follows`
--

CREATE TABLE `follows` (
  `follower_id` int(11) NOT NULL COMMENT '追隨者 ID',
  `following_id` int(11) NOT NULL COMMENT '被追隨者 ID',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '創建時間',
  `status` enum('pending','accepted') DEFAULT 'pending' COMMENT '申請/已成為'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `follows`
--


-- --------------------------------------------------------

--
-- 資料表結構 `foxes`
--

CREATE TABLE `foxes` (
  `id` int(11) NOT NULL COMMENT '狐狸 ID',
  `player_id` int(11) NOT NULL COMMENT '玩家 ID',
  `type` enum('fire','water','wind','light','dark') NOT NULL COMMENT '狐狸屬性',
  `level` int(11) NOT NULL DEFAULT 0 COMMENT '當前等級',
  `exp` int(11) NOT NULL DEFAULT 0 COMMENT '當前經驗值',
  `hp_tier` int(11) NOT NULL DEFAULT 0 COMMENT 'HP強化等級',
  `atk_tier` int(11) NOT NULL DEFAULT 0 COMMENT 'ATK強化等級',
  `def_tier` int(11) NOT NULL DEFAULT 0 COMMENT 'DEF強化等級'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='紀錄玩家所擁有的狐狸夥伴夥伴及其能力值、強化階級的資料表';

--
-- 傾印資料表的資料 `foxes`
--

-- --------------------------------------------------------

--
-- 資料表結構 `players`
--

CREATE TABLE `players` (
  `id` int(11) NOT NULL COMMENT '玩家ID',
  `name` varchar(50) NOT NULL COMMENT '玩家暱稱',
  `merit` int(11) DEFAULT 0 COMMENT '玩家功德',
  `stamina` int(11) NOT NULL DEFAULT 0 COMMENT '玩家體力',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '管理員(1是/0否)',
  `support_fox` int(11) DEFAULT NULL COMMENT '助戰狐 ID',
  `last_sign_date` date DEFAULT NULL COMMENT '最後簽到日期',
  `last_login` date DEFAULT current_timestamp() COMMENT '最後登入日期',
  `username` varchar(50) NOT NULL COMMENT '登入帳號',
  `password_hash` varchar(255) NOT NULL COMMENT '雜湊加密密碼'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='儲存玩家帳號與基礎數值的核心資料表';

--
-- 傾印資料表的資料 `players`
--

-- --------------------------------------------------------

--
-- 資料表結構 `player_inventory`
--

CREATE TABLE `player_inventory` (
  `player_id` int(11) NOT NULL COMMENT '玩家 ID',
  `item_name` varchar(50) NOT NULL COMMENT '道具名稱',
  `quantity` int(11) DEFAULT 0 COMMENT '數量'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='紀錄玩家背包裡目前各有多少道具的交叉關聯表';

--
-- 傾印資料表的資料 `player_inventory`
--

-- --------------------------------------------------------

--
-- 資料表結構 `player_stages`
--

CREATE TABLE `player_stages` (
  `player_id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,
  `cleared_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `player_stages`
--

-- --------------------------------------------------------

--
-- 資料表結構 `squads`
--

CREATE TABLE `squads` (
  `player_id` int(11) NOT NULL COMMENT '玩家ID',
  `squad_num` enum('1','2','3') NOT NULL DEFAULT '1' COMMENT '隊伍編號1~3',
  `pos_1` int(11) DEFAULT NULL COMMENT '先鋒',
  `pos_2` int(11) DEFAULT NULL COMMENT '後衛上',
  `pos_3` int(11) DEFAULT NULL COMMENT '後衛下'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `squads`
--

-- --------------------------------------------------------

--
-- 資料表結構 `stages`
--

CREATE TABLE `stages` (
  `id` int(11) NOT NULL COMMENT '關卡ID',
  `type` varchar(20) NOT NULL COMMENT '關卡類型 (如: 主線-A-1, 副本-B-1)',
  `name` varchar(50) NOT NULL COMMENT '關卡名稱',
  `rec_level` int(11) DEFAULT 1 COMMENT '建議等級',
  `stamina_cost` int(11) NOT NULL DEFAULT 1 COMMENT '體力消耗'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `stages`
--

INSERT INTO `stages` (`id`, `type`, `name`, `rec_level`, `stamina_cost`) VALUES
(1, '主線-雪山村落-1', '雪山小徑', 1, 1),
(2, '主線-雪山村落-2', '雪山深處', 3, 1),
(3, '主線-熔岩火山-1', '火山入口', 5, 1),
(4, '副本-經驗-1', '初級訓練', 1, 5),
(5, '副本-精魄-1', '初級靈礦', 5, 8),
(15, '主線-雪山村落-3', '雪山村落', 5, 1),
(16, '副本-血量-1', '初級訓練', 1, 5),
(17, '副本-攻擊-1', '初級力量訓練所', 1, 5),
(18, '副本-防禦-1', '初級防禦訓練所', 1, 5),
(19, '主線-熔岩火山-2', '熔岩之路', 10, 1),
(20, '副本-經驗-2', '中級訓練', 10, 5),
(21, '副本-經驗-3', '高級訓練', 20, 5),
(22, '副本-經驗-4', '超級訓練', 50, 5),
(23, '副本-經驗-5', '噩夢難度', 100, 5),
(24, '副本-血量-2', '中級訓練', 10, 5),
(25, '副本-血量-3', '高級訓練', 20, 5),
(26, '副本-血量-4', '超級訓練', 50, 5),
(27, '副本-血量-5', '噩夢難度', 100, 5),
(28, '副本-攻擊-2', '中級訓練', 10, 5),
(29, '副本-攻擊-3', '高級訓練', 20, 5),
(30, '副本-攻擊-4', '超級訓練', 50, 5),
(31, '副本-攻擊-5', '噩夢難度', 5, 1),
(32, '副本-防禦-2', '中級訓練', 10, 5),
(33, '副本-防禦-3', '高級訓練', 20, 5),
(34, '副本-防禦-4', '超級訓練', 50, 5),
(35, '副本-防禦-5', '噩夢難度', 100, 5),
(36, '副本-精魄-2', '中級靈礦', 15, 5),
(37, '副本-精魄-3', '高級靈礦', 35, 5),
(38, '副本-精魄-4', '超級礦脈', 70, 5),
(39, '副本-精魄-5', '萬靈礦脈', 100, 5),
(40, '主線-熔岩火山-3', '熔岩祭壇', 15, 1),
(41, '主線-疾風草原-1', '綠意山丘', 10, 1),
(42, '主線-疾風草原-2', '清新草原', 15, 1),
(43, '主線-疾風草原-3', '星星之火', 20, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `stage_enemies`
--

CREATE TABLE `stage_enemies` (
  `id` int(11) NOT NULL COMMENT '敵人ID',
  `stage_id` int(11) NOT NULL COMMENT '關卡ID',
  `name` varchar(50) NOT NULL DEFAULT '''未知敵人''' COMMENT '名稱',
  `type` enum('fire','water','wind','light','dark','none') NOT NULL COMMENT '敵人屬性',
  `level` int(11) NOT NULL COMMENT '建議等級',
  `hp` int(11) NOT NULL DEFAULT 100 COMMENT '敵人血量',
  `atk` int(11) NOT NULL DEFAULT 10 COMMENT '敵人攻擊力',
  `def` int(11) NOT NULL DEFAULT 1 COMMENT '敵人防禦',
  `pos` enum('1','2','3') NOT NULL DEFAULT '1' COMMENT '隊伍位置 1-3',
  `special_effect` text DEFAULT NULL COMMENT 'JSON格式儲存特殊效果'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `stage_enemies`
--

-- --------------------------------------------------------

--
-- 資料表結構 `stage_rewards`
--

CREATE TABLE `stage_rewards` (
  `stage_id` int(11) NOT NULL COMMENT '關卡ID',
  `type` enum('merit','item') NOT NULL COMMENT '獎勵類型',
  `item_name` varchar(50) DEFAULT NULL COMMENT '道具名稱',
  `amount` int(11) NOT NULL DEFAULT 0 COMMENT '數量',
  `probability` decimal(5,4) NOT NULL DEFAULT 1.0000 COMMENT '掉落機率 0.0000-1.0000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `stage_rewards`
--

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `follows`
--
ALTER TABLE `follows`
  ADD PRIMARY KEY (`follower_id`,`following_id`),
  ADD KEY `following_id` (`following_id`);

--
-- 資料表索引 `foxes`
--
ALTER TABLE `foxes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `player_id` (`player_id`);

--
-- 資料表索引 `players`
--
ALTER TABLE `players`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_username` (`username`),
  ADD KEY `fk_support_fox` (`support_fox`);

--
-- 資料表索引 `player_inventory`
--
ALTER TABLE `player_inventory`
  ADD PRIMARY KEY (`player_id`,`item_name`);

--
-- 資料表索引 `player_stages`
--
ALTER TABLE `player_stages`
  ADD PRIMARY KEY (`player_id`,`stage_id`),
  ADD KEY `stage_id` (`stage_id`);

--
-- 資料表索引 `squads`
--
ALTER TABLE `squads`
  ADD PRIMARY KEY (`player_id`,`squad_num`),
  ADD KEY `fk_squad_pos1` (`pos_1`),
  ADD KEY `fk_squad_pos2` (`pos_2`),
  ADD KEY `fk_squad_pos3` (`pos_3`);

--
-- 資料表索引 `stages`
--
ALTER TABLE `stages`
  ADD PRIMARY KEY (`id`);

--
-- 資料表索引 `stage_enemies`
--
ALTER TABLE `stage_enemies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stage_id` (`stage_id`);

--
-- 資料表索引 `stage_rewards`
--
ALTER TABLE `stage_rewards`
  ADD KEY `stage_id` (`stage_id`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `foxes`
--
ALTER TABLE `foxes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '狐狸 ID', AUTO_INCREMENT=21;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `players`
--
ALTER TABLE `players`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '玩家ID', AUTO_INCREMENT=14;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `stages`
--
ALTER TABLE `stages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '關卡ID', AUTO_INCREMENT=44;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `stage_enemies`
--
ALTER TABLE `stage_enemies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '敵人ID', AUTO_INCREMENT=327;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `follows`
--
ALTER TABLE `follows`
  ADD CONSTRAINT `follows_ibfk_1` FOREIGN KEY (`follower_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `follows_ibfk_2` FOREIGN KEY (`following_id`) REFERENCES `players` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `foxes`
--
ALTER TABLE `foxes`
  ADD CONSTRAINT `foxes_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `players`
--
ALTER TABLE `players`
  ADD CONSTRAINT `fk_support_fox` FOREIGN KEY (`support_fox`) REFERENCES `foxes` (`id`) ON DELETE SET NULL;

--
-- 資料表的限制式 `player_inventory`
--
ALTER TABLE `player_inventory`
  ADD CONSTRAINT `player_inventory_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `player_stages`
--
ALTER TABLE `player_stages`
  ADD CONSTRAINT `fk_player_stages_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `player_stages_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `stages` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `squads`
--
ALTER TABLE `squads`
  ADD CONSTRAINT `fk_squad_pos1` FOREIGN KEY (`pos_1`) REFERENCES `foxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_squad_pos2` FOREIGN KEY (`pos_2`) REFERENCES `foxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_squad_pos3` FOREIGN KEY (`pos_3`) REFERENCES `foxes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `squads_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `stage_enemies`
--
ALTER TABLE `stage_enemies`
  ADD CONSTRAINT `stage_enemies_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `stages` (`id`) ON DELETE CASCADE;

--
-- 資料表的限制式 `stage_rewards`
--
ALTER TABLE `stage_rewards`
  ADD CONSTRAINT `stage_rewards_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `stages` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
