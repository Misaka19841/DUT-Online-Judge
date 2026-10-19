<?php
$regionalContests = RegionalContest::fetchVisible();
$regionalContestsEmptyMessage = RegionalContest::getEmptyMessage();
?>
<?php echoUOJPageHeader('区域赛') ?>
<div class="panel panel-default">
    <div class="panel-body">
        <?php uojIncludeView('regional-contests-board', [
            'regionalContests' => $regionalContests,
            'regionalContestsBoardId' => 'regional-contests-page-board',
            'regionalContestsTitle' => '区域赛',
            'regionalContestsSubtitle' => '以中国地图承载举办城市，以两侧时间轴串联比赛时间与队伍信息。',
            'regionalContestsEmptyMessage' => $regionalContestsEmptyMessage,
            'regionalContestsMoreHref' => null
        ]) ?>
    </div>
</div>
<?php echoUOJPageFooter() ?>
