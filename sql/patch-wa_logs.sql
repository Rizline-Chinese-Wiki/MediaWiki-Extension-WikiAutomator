CREATE TABLE IF NOT EXISTS /*_*/wa_logs (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  log_task_id INT UNSIGNED NOT NULL,
  log_task_name VARBINARY(255) NOT NULL,
  log_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  log_user_name VARBINARY(255) NOT NULL DEFAULT '',
  log_timestamp VARBINARY(14) NOT NULL,
  log_action VARBINARY(50) NOT NULL DEFAULT 'execute',
  log_pages_affected INT UNSIGNED NOT NULL DEFAULT 0,
  log_total_matches INT UNSIGNED NOT NULL DEFAULT 0,
  log_status VARBINARY(20) NOT NULL DEFAULT 'success',
  log_details MEDIUMBLOB,
  KEY idx_task_id (log_task_id),
  KEY idx_timestamp (log_timestamp),
  KEY idx_user_id (log_user_id)
) /*$wgDBTableOptions*/;
