CREATE TABLE /*_*/wa_tasks (
  task_id INT AUTO_INCREMENT PRIMARY KEY,
  task_name VARBINARY(255) NOT NULL,
  task_trigger VARBINARY(50) NOT NULL,
  task_target BLOB,
  task_conditions BLOB,
  task_actions BLOB,
  task_enabled TINYINT DEFAULT 1,
  task_owner_id INT UNSIGNED NOT NULL DEFAULT 0,
  task_owner_name VARBINARY(255) NOT NULL DEFAULT '',
  task_created_at VARBINARY(14) NOT NULL DEFAULT '',
  task_use_regex TINYINT DEFAULT 0,
  task_cron_interval INT DEFAULT 0,
  task_last_run VARBINARY(14) DEFAULT '',
  task_trigger_category VARBINARY(255) DEFAULT '',
  task_category_action VARBINARY(20) DEFAULT '',
  task_scheduled_time VARBINARY(14) DEFAULT '',
  task_edit_summary VARBINARY(255) DEFAULT ''
) /*$wgDBTableOptions*/;
