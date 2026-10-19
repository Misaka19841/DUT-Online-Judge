<?php
$regionalContests = RegionalContest::fetchVisible();
$regionalContestsEmptyMessage = RegionalContest::getEmptyMessage();
?>
<?php echoUOJPageHeader('区域赛') ?>
<?php uojIncludeView('regional-contests-board', [
    'regionalContests' => $regionalContests,
    'regionalContestsBoardId' => 'regional-contests-page-board',
    'regionalContestsTitle' => '区域赛',
    'regionalContestsSubtitle' => '以中国地图承载举办城市，把时间轴真正铺到页面两侧留白区域。',
    'regionalContestsEmptyMessage' => $regionalContestsEmptyMessage,
    'regionalContestsMoreHref' => null
]) ?>
<?php echoUOJPageFooter() ?>
