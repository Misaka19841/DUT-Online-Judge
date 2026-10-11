<?php
	if (Auth::check()) {
		redirectTo('/');
	}
	
	function handleLoginPost() {
		if (!crsf_check()) {
			return 'expired';
		}
		if (!isset($_POST['username'])) {
			return "failed";
		}
		if (!isset($_POST['password'])) {
			return "failed";
		}
		$username = $_POST['username'];
		$password = $_POST['password'];
		
		if (!validateUsername($username)) {
			return "failed";
		}
		if (!validatePassword($password)) {
			return "failed";
		}
		
		$user = UOJUser::query($username);
		if (!$user || !checkPassword($user, $password)) {
			return "failed";
		}
		
		$account_status = UOJUser::getAccountStatus($user);
		if ($account_status != 'ok') {
			return 'account:'.$account_status;
		}

                if (contest_security_mode_user_requires_verified_login($user)) {
                        $login_code = $_POST['login_code'] ?? '';
                        if (!contest_security_mode_consume_login_code($login_code, $user['username'])) {
                                return 'code';
                        }
                }
		
		Auth::login($user['username']);
                if (contest_security_mode_enabled()) {
                        contest_security_mode_mark_session_verified();
                }
		return "ok";
	}
	
	if (isset($_POST['login'])) {
		echo handleLoginPost();
		die();
	}
?>
<?php
	$REQUIRE_LIB['md5'] = '';
        $safe_mode_enabled = contest_security_mode_enabled();
?>
<?php echoUOJPageHeader(UOJLocale::get('login')) ?>
<h2 class="page-header"><?= UOJLocale::get('login') ?></h2>
<form id="form-login" class="form-horizontal" method="post">
  <div id="div-username" class="form-group">
    <label for="input-username" class="col-sm-2 control-label"><?= UOJLocale::get('username') ?></label>
    <div class="col-sm-3">
      <input type="text" class="form-control" id="input-username" name="username" placeholder="<?= UOJLocale::get('enter your username') ?>" maxlength="20" />
      <span class="help-block" id="help-username"></span>
    </div>
  </div>
  <div id="div-password" class="form-group">
    <label for="input-password" class="col-sm-2 control-label"><?= UOJLocale::get('password') ?></label>
    <div class="col-sm-3">
      <input type="password" class="form-control" id="input-password" name="password" placeholder="<?= UOJLocale::get('enter your password') ?>" maxlength="20" />
      <span class="help-block" id="help-password"></span>
    </div>
  </div>
  <?php if ($safe_mode_enabled): ?>
  <div id="div-login-code" class="form-group">
    <label for="input-login-code" class="col-sm-2 control-label">验证码</label>
    <div class="col-sm-3">
      <input type="text" class="form-control" id="input-login-code" name="login_code" placeholder="输入一次性验证码" maxlength="16" />
      <span class="help-block" id="help-login-code">比赛隔离模式已开启，非管理员登录需要输入一次性验证码，管理员可留空。</span>
    </div>
  </div>
  <?php endif ?>
  <div class="form-group">
    <div class="col-sm-offset-2 col-sm-3">
      <button type="submit" id="button-submit" class="btn btn-default"><?= UOJLocale::get('submit') ?></button>
    </div>
  </div>
</form>

<script type="text/javascript">
function validateLoginPost() {
	var ok = true;
	ok &= getFormErrorAndShowHelp('username', validateUsername);
	ok &= getFormErrorAndShowHelp('password', validatePassword);
        <?php if ($safe_mode_enabled): ?>
        var code = $('#input-login-code').val().toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (code !== '' && !/^[A-Z2-9]{8}$/.test(code)) {
                $('#div-login-code').addClass('has-error');
                $('#help-login-code').text('请输入有效的一次性验证码。');
                ok = false;
        } else {
                $('#div-login-code').removeClass('has-error');
                $('#help-login-code').text('比赛隔离模式已开启，非管理员登录需要输入一次性验证码，管理员可留空。');
        }
        <?php endif ?>
	return ok;
}

function submitLoginPost() {
	if (!validateLoginPost()) {
		return false;
	}
	
	$.post('/login', {
		_token : "<?= crsf_token() ?>",
		login : '',
		username : $('#input-username').val(),
                password : md5($('#input-password').val(), "<?= getPasswordClientSalt() ?>"),
                login_code : $('#input-login-code').val()
	}, function(msg) {
		if (msg == 'ok') {
			var prevUrl = document.referrer;
			if (prevUrl == '' || /.*\/login.*/.test(prevUrl) || /.*\/register.*/.test(prevUrl) || /.*\/reset-password.*/.test(prevUrl)) {
				prevUrl = '/';
			};
			window.location.href = prevUrl;
		} else if (msg == 'account:banned') {
			$('#div-username').addClass('has-error');
			$('#help-username').html('用户已被禁用。');
		} else if (msg == 'account:expired') {
			$('#div-username').addClass('has-error');
			$('#help-username').html('该临时用户的有效期已过，无法登录。');
		} else if (msg == 'expired') {
			$('#div-username').addClass('has-error');
			$('#help-username').html('页面已过期。');
                } else if (msg == 'code') {
                        $('#div-login-code').addClass('has-error');
                        $('#help-login-code').text('验证码错误、已失效，或已被使用。');
		} else {
			$('#div-username').addClass('has-error');
			$('#help-username').html('用户名或密码错误。');
			$('#div-password').addClass('has-error');
			$('#help-password').html('用户名或密码错误。<a href="/forgot-password">忘记密码？</a>');
		}
	});
	return true;
}

$(document).ready(function() {
	$('#form-login').submit(function(e) {
		e.preventDefault();
		submitLoginPost();
	});
});

</script>
<?php echoUOJPageFooter() ?>
