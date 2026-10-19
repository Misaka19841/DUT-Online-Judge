<?php
$toolboxItems = ToolboxItem::fetchVisible();
$toolboxNotice = ToolboxItem::getNotice();
?>
<?php echoUOJPageHeader('工具箱') ?>
<?php uojIncludeView('toolbox', [
    'toolboxItems' => $toolboxItems,
    'toolboxNotice' => $toolboxNotice
]) ?>
<?php echoUOJPageFooter() ?>
