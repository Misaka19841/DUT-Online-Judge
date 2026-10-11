<?php contest_security_mode_block_if_needed('blog', Auth::user()); ?>
<?php requireLib('flot') ?>
<?php echoUOJPageHeader('关于我') ?>
<?php uojIncludeView('user-info', ['user' => UOJUserBlog::user()]); ?>
<?php echoUOJPageFooter() ?>
