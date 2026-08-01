CREATE TABLE IF NOT EXISTS `jiaobei_records` (
  `record_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED         NULL DEFAULT NULL COMMENT '登入會員，訪客為 NULL',
  `question`    VARCHAR(200)     NOT NULL DEFAULT '' COMMENT '祈求內容',
  `result`      ENUM('sheng','yin','xiao') NOT NULL COMMENT '聖筊/陰筊/笑筊',
  `left_face`   TINYINT(1) NOT NULL COMMENT '左筊正面朝上=1',
  `right_face`  TINYINT(1) NOT NULL COMMENT '右筊正面朝上=1',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`record_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='線上擲筊紀錄';
