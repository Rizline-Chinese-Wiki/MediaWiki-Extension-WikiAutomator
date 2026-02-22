ALTER TABLE /*_*/wa_tasks ADD COLUMN task_match_mode VARBINARY(20) DEFAULT 'auto';
UPDATE /*_*/wa_tasks SET task_match_mode = CASE WHEN task_use_regex = 1 THEN 'regex' ELSE 'auto' END;
