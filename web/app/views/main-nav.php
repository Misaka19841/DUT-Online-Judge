<?php
$hack_blocked = contest_security_mode_should_block_feature('hack', Auth::user());
if (!function_exists('deepseekCreateAppDbConnection')) {
    function deepseekCreateAppDbConnection() {
        $db_cfg = UOJConfig::$data['database'];
        $mysqli = @new mysqli($db_cfg['host'], $db_cfg['username'], $db_cfg['password'], $db_cfg['database']);
        if ($mysqli->connect_error) {
            return null;
        }

        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }
}

if (!function_exists('deepseekFetchSkills')) {
    function deepseekFetchSkills() {
        $skills = [];
        $mysqli = deepseekCreateAppDbConnection();
        if (!$mysqli) {
            return $skills;
        }

        $result = $mysqli->query("SELECT name, skill FROM skills ORDER BY name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $skills[] = $row;
            }
            $result->free();
        }

        $mysqli->close();
        return $skills;
    }
}

$deepseekSkills = deepseekFetchSkills();
$deepseekSelectedSkill = isset($_GET['skill']) ? trim($_GET['skill']) : '';
?>
<div class="navbar navbar-default" role="navigation">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target=".navbar-collapse">
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="<?= HTML::url('/') ?>">DOJ</a>
        </div>
        <div class="navbar-collapse collapse">
            <ul class="nav navbar-nav">	       
		<li><a href="<?= HTML::url('/contests') ?>"><?= UOJLocale::get('contests') ?></a></li>
                <li><a href="<?= HTML::url('/problems') ?>"><?= UOJLocale::get('problems') ?></a></li>
                <li><a href="<?= HTML::url('/submissions') ?>"><?= UOJLocale::get('submissions') ?></a></li>
                <?php if (!$hack_blocked): ?>
                <li><a href="<?= HTML::url('/hacks') ?>"><?= UOJLocale::get('hacks') ?></a></li>
                <?php endif ?>
                <li><a href="<?= HTML::url('/blogs') ?>"><?= UOJLocale::get('blogs') ?></a></li>
		<li><a href="<?= HTML::url('/faq') ?>"><?= UOJLocale::get('help') ?></a></li>
                <li><a href="/regional-contests">区域赛</a></li>
                <li><a href="/toolbox">工具箱</a></li>

	</ul>
        </div><!--/.nav-collapse -->
    </div>
</div>
