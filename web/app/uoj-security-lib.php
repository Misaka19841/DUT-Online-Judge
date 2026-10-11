<?php

function getPasswordToStore($password, $username) {
	return md5($username . $password);
}
function checkPassword($user, $password) {
   	return $user['password'] == md5($user['username'] . $password);
}
function getPasswordClientSalt() {
	return UOJConfig::$data['security']['user']['client_salt'];
}

function crsf_token() {
	if (!isset($_SESSION['_token'])) {
		$_SESSION['_token'] = uojRandString(60);
	}
	return $_SESSION['_token'];
}
function crsf_check() {
	if (!isset($_SESSION['_token'])) {
		return false;
	}
	
	if (isset($_POST['_token'])) {
		$_token = $_POST['_token'];
	} else if (isset($_GET['_token'])) {
		$_token = $_GET['_token'];
	} else {
		return false;
	}
	return $_token === $_SESSION['_token'];
}
function crsf_defend() {
	if (!crsf_check()) {
		UOJResponse::page403('页面已过期（可能页面真的过期了，也可能是刚才你访问的网页没有完全加载，也可能是你的浏览器版本太老）');
	}
}

function recaptcha_available() {
	return isset(UOJConfig::$data['security']['recaptcha']['site_key'])
		&& isset(UOJConfig::$data['security']['recaptcha']['secret_key']);
}

function recaptcha_sitekey() {
	return UOJConfig::$data['security']['recaptcha']['site_key'];
}

function recaptcha_check() {
	if (!isset($_POST['g-recaptcha-response'])) {
		return false;
	}
	$gresponse = $_POST['g-recaptcha-response'];
	
	$recaptcha = new \ReCaptcha\ReCaptcha(
		UOJConfig::$data['security']['recaptcha']['secret_key'],
		new \ReCaptcha\RequestMethod\Post('https://www.recaptcha.net/recaptcha/api/siteverify')
	);
	// the threshold was 0.5, but some users have reported that they cannot pass the test...
	// common cases: using VPN, or accessing UOJ from a computer lab at school
	$resp = $recaptcha->setScoreThreshold(0.3)
	                  ->verify($gresponse, UOJContext::remoteAddr());
	
	if ($resp->isSuccess()) {
		return true;
	}
	
	if (in_array(\ReCaptcha\ReCaptcha::E_CONNECTION_FAILED, $resp->getErrorCodes())) {
		UOJLog::error('cannot connect to recaptcha!!!');
	}
	return false;
}

function submission_frequency_check() {
	$submission_frequency = UOJContext::getMeta('submission_frequency');
	$recent = clone UOJTime::$time_now;
	$recent->sub(new DateInterval($submission_frequency['interval']));
	$num = DB::selectCount([
		"select count(*) from submissions",
		"where", [
			"submitter" => Auth::id(),
			["submit_time", ">=", $recent->format('Y-m-d H:i:s')]
		]
	]);

	if ($num >= max(1, $submission_frequency['limit'])) {
		return false;
	}

	return true;
}

function contest_security_mode_config() {
        $cfg = UOJContext::getMeta('safemode');
        $default = UOJContext::$meta_default['safemode'];
        if (!is_array($cfg)) {
                return [
                        'enabled' => (bool)$cfg,
                        'version' => $default['version'],
                        'last_batch_id' => $default['last_batch_id'],
                        'last_generated_at' => $default['last_generated_at']
                ];
        }
        return array_merge($default, $cfg);
}

function contest_security_mode_set_config(array $cfg) {
        $cfg = array_merge(UOJContext::$meta_default['safemode'], $cfg);
        return UOJContext::setMeta('safemode', $cfg);
}

function contest_security_mode_enabled() {
        return contest_security_mode_config()['enabled'];
}

function contest_security_mode_version() {
        return (int)contest_security_mode_config()['version'];
}

function contest_security_mode_normalize_login_code($code) {
        if (!is_string($code)) {
                return '';
        }
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
}

function contest_security_mode_display_login_code($code) {
        $code = contest_security_mode_normalize_login_code($code);
        if (strlen($code) <= 4) {
                return $code;
        }
        return substr($code, 0, 4) . '-' . substr($code, 4);
}

function contest_security_mode_validate_login_code($code) {
        return preg_match('/^[A-Z2-9]{8}$/', contest_security_mode_normalize_login_code($code)) === 1;
}

function contest_security_mode_ensure_login_code_table() {
        DB::query(<<<'SQL'
CREATE TABLE IF NOT EXISTS contest_login_codes (
        id int unsigned NOT NULL AUTO_INCREMENT,
        batch_id int unsigned NOT NULL,
        code varchar(32) NOT NULL,
        created_at datetime NOT NULL,
        used_at datetime DEFAULT NULL,
        used_by varchar(20) DEFAULT NULL,
        used_ip varchar(64) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_code (code),
        KEY idx_batch_id (batch_id),
        KEY idx_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
        );
}

function contest_security_mode_generate_login_codes($count = 300) {
        $count = max(1, min(5000, (int)$count));
        contest_security_mode_ensure_login_code_table();

        $cfg = contest_security_mode_config();
        $batch_id = (int)$cfg['last_batch_id'] + 1;
        $version = (int)$cfg['version'] + 1;
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $codes = [];
        $seen = [];
        while (count($codes) < $count) {
                $code = uojRandString(8, $charset);
                if (isset($seen[$code])) {
                        continue;
                }
                $seen[$code] = true;
                $codes[] = $code;
        }

        DB::delete("delete from contest_login_codes");
        $tuples = [];
        foreach ($codes as $code) {
                $tuples[] = [$batch_id, $code, UOJTime::$time_now_str, null, null, null];
        }
        DB::insert([
                "insert into contest_login_codes",
                "(batch_id, code, created_at, used_at, used_by, used_ip)",
                "values", DB::tuples($tuples)
        ]);

        contest_security_mode_set_config([
                'enabled' => true,
                'version' => $version,
                'last_batch_id' => $batch_id,
                'last_generated_at' => UOJTime::$time_now_str
        ]);

        return [
                'batch_id' => $batch_id,
                'version' => $version,
                'codes' => $codes
        ];
}

function contest_security_mode_disable() {
        $cfg = contest_security_mode_config();
        contest_security_mode_set_config([
                'enabled' => false,
                'version' => (int)$cfg['version'],
                'last_batch_id' => (int)$cfg['last_batch_id'],
                'last_generated_at' => $cfg['last_generated_at']
        ]);
}

function contest_security_mode_mark_session_verified() {
        $_SESSION['safemode_verified_version'] = contest_security_mode_version();
}

function contest_security_mode_clear_session_verified() {
        unset($_SESSION['safemode_verified_version']);
}

function contest_security_mode_session_verified() {
        return isset($_SESSION['safemode_verified_version'])
                && (int)$_SESSION['safemode_verified_version'] === contest_security_mode_version();
}

function contest_security_mode_user_requires_verified_login($user = null) {
        return contest_security_mode_enabled() && $user && !isSuperUser($user);
}

function contest_security_mode_user_can_stay_logged_in($user = null) {
        if (!contest_security_mode_user_requires_verified_login($user)) {
                return true;
        }
        return contest_security_mode_session_verified();
}

function contest_security_mode_consume_login_code($code, $username = null) {
        $code = contest_security_mode_normalize_login_code($code);
        if (!contest_security_mode_validate_login_code($code)) {
                return false;
        }
        contest_security_mode_ensure_login_code_table();
        DB::update([
                "update contest_login_codes",
                "set", [
                        "used_at" => UOJTime::$time_now_str,
                        "used_by" => $username,
                        "used_ip" => UOJContext::remoteAddr()
                ],
                "where", [
                        "code" => $code,
                        "used_at" => null
                ]
        ]);
        return DB::affected_rows() > 0;
}

function contest_security_mode_get_code_stats() {
        contest_security_mode_ensure_login_code_table();
        return [
                'unused' => (int)DB::selectCount([
                        "select count(*) from contest_login_codes",
                        "where", ["used_at" => null]
                ]),
                'used' => (int)DB::selectCount([
                        "select count(*) from contest_login_codes",
                        "where", [["used_at", "is not", null]]
                ])
        ];
}

function contest_security_mode_get_codes($only_unused = true) {
        contest_security_mode_ensure_login_code_table();
        $where = $only_unused ? ["used_at" => null] : '1';
        return DB::selectAll([
                "select * from contest_login_codes",
                "where", $where,
                "order by id asc"
        ]);
}

function contest_security_mode_should_block_feature($feature, $user = null) {
        if (!contest_security_mode_enabled()) {
                return false;
        }
        return !$user || !isSuperUser($user);
}

function contest_security_mode_feature_label($feature) {
        $map = [
                'private_message' => '私信功能',
                'blog' => '博客功能',
                'deepseek' => 'AI 问答',
                'submission' => '提交记录',
                'hack' => 'Hack 功能',
                'market' => '二手交易功能',
                'date' => '搭子匹配功能',
                'profile_edit' => '个人资料修改功能'
        ];
        return $map[$feature] ?? '该功能';
}

function contest_security_mode_block_message($feature) {
        $label = contest_security_mode_feature_label($feature);
        return '<div class="container"><div class="row"><div class="col-md-8 col-md-offset-2">'
                . '<div class="panel panel-danger top-buffer-lg"><div class="panel-heading"><h3 class="panel-title">访问受限</h3></div>'
                . '<div class="panel-body text-center"><h2><i class="fa fa-lock fa-3x text-danger"></i></h2>'
                . '<h3>管理员开启了比赛隔离模式</h3>'
                . '<p class="text-muted">' . HTML::escape($label) . ' 暂时不可用</p>'
                . '<hr><a href="/" class="btn btn-primary">返回首页</a></div></div></div></div></div>';
}

function contest_security_mode_block_if_needed($feature, $user = null) {
        if (!contest_security_mode_should_block_feature($feature, $user)) {
                return;
        }
        UOJResponse::message(contest_security_mode_block_message($feature), '比赛隔离模式已开启');
}
