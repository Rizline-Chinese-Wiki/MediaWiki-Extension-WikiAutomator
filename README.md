# MediaWiki-Extension-WikiAutomator

MediaWiki 自动化扩展

## 安装

1. 下载源码至 `$IP/extensions/WikiAutomator`
2. 在 `LocalSettings.php` 中添加：
     ```php
     wfLoadExtension( 'WikiAutomator' );
3. 运行数据库更新：
php maintenance/run.php update --force --quick

安装完成后访问 Special:WikiAutomator 即可使用。
