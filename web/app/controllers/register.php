<?php
	function normalizeRegisterEmail($email) {
		return is_string($email) ? strtolower(trim($email)) : $email;
	}

	function validateRegisterEmailCode($code) {
		return is_string($code) && preg_match('/^[0-9]{6}$/', $code);
	}

	function &getRegisterEmailVerificationSession() {
		if (!isset($_SESSION['register_email_verification']) || !is_array($_SESSION['register_email_verification'])) {
			$_SESSION['register_email_verification'] = [];
		}
		return $_SESSION['register_email_verification'];
	}

	function sendRegisterVerificationMail($email, $code) {
		$html = <<<EOD
	<base target="_blank" />

	<p>您好，</p>
	<p>您正在注册 DOJ 账号，本次注册验证码为：</p>
	<p style="font-size: 24px; font-weight: bold; letter-spacing: 4px;">{$code}</p>
	<p>验证码 10 分钟内有效。如非本人操作，请忽略此邮件。</p>
	<p>DUT Online Judge</p>

	<style type="text/css">
	body{font-size:14px;font-family:arial,verdana,sans-serif;line-height:1.666;padding:0;margin:0;overflow:auto;white-space:normal;word-wrap:break-word;min-height:100px}
	pre {white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;word-wrap:break-word}
	</style>
	EOD;

		$mailer = UOJMail::noreply();
		$mailer->addAddress($email);
		$mailer->Subject = 'DOJ 注册验证码';
		$mailer->msgHTML($html);
		$mailer->send();
	}

	function checkRegisterEmailVerification($email, $code, &$error) {
		if (!validateRegisterEmailCode($code)) {
			$error = '验证码应为 6 位数字。';
			return false;
		}

		$verification = &getRegisterEmailVerificationSession();
		if (!$verification) {
			$error = '请先获取邮箱验证码。';
			return false;
		}

		$now = time();
		if (($verification['expire_time'] ?? 0) < $now) {
			$verification = [];
			$error = '验证码已过期，请重新获取。';
			return false;
		}
		if (($verification['email'] ?? '') !== $email) {
			$error = '邮箱已变更，请重新获取验证码。';
			return false;
		}
		if (!hash_equals($verification['code_hash'] ?? '', hash('sha256', $code))) {
			$error = '验证码错误。';
			return false;
		}

		return true;
	}

	function handleSendRegisterCodePost() {
		if (!crsf_check()) {
			return ['ok' => false, 'msg' => '页面已过期'];
		}
		if (!isset($_POST['email'])) {
			return ['ok' => false, 'msg' => '无效表单'];
		}

		$email = normalizeRegisterEmail(UOJRequest::post('email'));
		if (!validateQQEmail($email)) {
			return ['ok' => false, 'msg' => '注册仅支持 QQ 邮箱（xxx@qq.com）'];
		}

		$verification = &getRegisterEmailVerificationSession();
		$now = time();
		$next_send_time = $verification['next_send_time'] ?? 0;
		if ($next_send_time > $now) {
			return [
				'ok' => false,
				'msg' => '发送过于频繁，请稍后再试。',
				'retry_after' => $next_send_time - $now
			];
		}

                try {
                        $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                        sendRegisterVerificationMail($email, $code);
                } catch (Exception $e) {
			UOJLog::error('register mail send failed: ' . $e->getMessage());
			return ['ok' => false, 'msg' => '验证码发送失败，请稍后重试。'];
		}

		$verification = [
			'email' => $email,
			'code_hash' => hash('sha256', $code),
			'expire_time' => $now + 600,
			'next_send_time' => $now + 60
		];

		return [
			'ok' => true,
			'msg' => '验证码已发送，请查收邮件。',
			'retry_after' => 60
		];
	}

	function handleRegisterPost() {
		if (!crsf_check()) {
			return '页面已过期';
		}
		if (recaptcha_available() && !recaptcha_check()) {
			return '咦，DOJ 怀疑你是机器人哦……如果真的是人类的话，换个网络重试一下或者找管理员瞧瞧？';
		}

		if (!isset($_POST['username']) || !isset($_POST['password']) || !isset($_POST['email']) || !isset($_POST['email_code'])) {
			return "无效表单";
		}

		$email = normalizeRegisterEmail(UOJRequest::post('email'));
		if (!validateQQEmail($email)) {
			return '失败：注册仅支持 QQ 邮箱（xxx@qq.com）';
		}
		if (!checkRegisterEmailVerification($email, UOJRequest::post('email_code'), $error)) {
			return '失败：' . $error;
		}

		try {
			$user = UOJUser::register([
				'username' => UOJRequest::post('username'),
				'password' => UOJRequest::post('password'),
				'email' => $email
			]);
		} catch (UOJInvalidArgumentException $e) {
			return "失败：".$e->getMessage();
		}

		$verification = &getRegisterEmailVerificationSession();
		$verification = [];

		return "欢迎你！" . $user['username'] . "，你已成功注册。";
	}

	if (isset($_POST['register'])) {
		echo handleRegisterPost();
		die();
	} elseif (isset($_POST['send_email_code'])) {
		header('Content-Type: application/json');
		echo json_encode(handleSendRegisterCodePost());
		die();
	} elseif (isset($_POST['check_username'])) {
		$username = $_POST['username'];
		if (validateUsername($username) && !UOJUser::query($username)) {
			echo '{"ok" : true}';
		} else {
			echo '{"ok" : false}';
		}
		die();
	}
?>
<?php
	$REQUIRE_LIB['md5'] = '';
	$REQUIRE_LIB['dialog'] = '';
	if (recaptcha_available()) {
		$REQUIRE_LIB['recaptcha'] = '';
	}
?>
<?php echoUOJPageHeader(UOJLocale::get('register')) ?>
<h2 class="page-header"><?= UOJLocale::get('register') ?></h2>
<form id="form-register" class="form-horizontal" method="post">
	<div id="div-email" class="form-group">
		<label for="input-email" class="col-sm-2 control-label"><?= UOJLocale::get('') ?>QQ邮箱</label>
		<div class="col-sm-3">
			<input type="email" class="form-control" id="input-email" name="email" placeholder="<?= UOJLocale::get('enter your email') ?>" maxlength="50" />
			<span class="help-block" id="help-email"></span>
		</div>
	</div>
	<div id="div-email_code" class="form-group">
		<label for="input-email_code" class="col-sm-2 control-label">邮箱验证码</label>
		<div class="col-sm-3">
			<div class="input-group">
				<input type="text" class="form-control" id="input-email_code" name="email_code" placeholder="输入 6 位验证码" maxlength="6" />
				<span class="input-group-btn">
					<button type="button" class="btn btn-default" id="button-send-email-code">发送验证码</button>
				</span>
			</div>
			<span class="help-block" id="help-email_code"></span>
		</div>
	</div>
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
			<input type="password" class="form-control top-buffer-sm" id="input-confirm_password" placeholder="<?= UOJLocale::get('re-enter your password') ?>" maxlength="20" />
			<span class="help-block" id="help-password"></span>
		</div>
	</div>
	<div class="form-group">
		<div class="col-sm-offset-2 col-sm-3">
			<?php
				$submit_btn_attrs = [
					'type' => 'submit',
					'id' => 'button-submit',
					'class' => 'btn btn-default'
				];
				if (recaptcha_available()) {
					$submit_btn_attrs['class'] .= ' g-recaptcha';
					$submit_btn_attrs += [
						'data-sitekey' => recaptcha_sitekey(),
						'data-action' => 'submit',
						'data-callback' => 'submitRegisterPost'
					];
				}
				echo HTML::tag('button', $submit_btn_attrs, UOJLocale::get('submit'));
			?>
		</div>
	</div>
</form>

<script type="text/javascript">
function checkUsernameNotInUse() {
	var ok = false;
	$.ajax({
		url : '/register',
		type : 'POST',
		dataType : 'json',
		async : false,
		
		data : {
			check_username : '',
			username : $('#input-username').val()
		},
		success : function(data) {
			ok = data.ok;
		},
		error :	function(XMLHttpRequest, textStatus, errorThrown) {
			alert(XMLHttpRequest.responseText);
			ok = false;
		}
	});
	return ok;
}
var registerEmailCodeCooldownTimer = null;
function validateRegisterEmail(str) {
	var err = validateEmail(str);
	if (err) {
		return err;
	}
	if (!/^[^@\s]+@qq\.com$/i.test($.trim(str))) {
		return '注册仅支持 QQ 邮箱（xxx@qq.com）。';
	}
	return '';
}
function validateRegisterEmailCodeInput(str) {
	if (!/^[0-9]{6}$/.test(str)) {
		return '验证码应为 6 位数字。';
	}
	return '';
}
function setRegisterEmailCodeButtonCooldown(seconds) {
	if (registerEmailCodeCooldownTimer) {
		clearInterval(registerEmailCodeCooldownTimer);
		registerEmailCodeCooldownTimer = null;
	}
	var $button = $('#button-send-email-code');
	if (seconds <= 0) {
		$button.prop('disabled', false).text('发送验证码');
		return;
	}
	$button.prop('disabled', true).text(seconds + ' 秒后重发');
	registerEmailCodeCooldownTimer = setInterval(function() {
		seconds--;
		if (seconds <= 0) {
			clearInterval(registerEmailCodeCooldownTimer);
			registerEmailCodeCooldownTimer = null;
			$button.prop('disabled', false).text('发送验证码');
		} else {
			$button.text(seconds + ' 秒后重发');
		}
	}, 1000);
}
function showRegisterEmailCodeMessage(msg, isError) {
	$('#div-email_code').toggleClass('has-error', !!isError);
	$('#help-email_code').css('color', isError ? '' : '#3c763d').text(msg);
}
function validateRegisterPost() {
	var ok = true;
	ok &= getFormErrorAndShowHelp('email', validateRegisterEmail);
	ok &= getFormErrorAndShowHelp('email_code', validateRegisterEmailCodeInput);
	ok &= getFormErrorAndShowHelp('username', function(str) {
		var err = validateUsername(str);
		if (err)
			return err;
		if (!checkUsernameNotInUse())
			return '该用户名已被人使用了。';
		return '';
	})
	ok &= getFormErrorAndShowHelp('password', validateSettingPassword);
	return ok;
}

function submitRegisterPost(gtoken) {
	if (!validateRegisterPost()) {
		return;
	}
	
	$.post('/register', {
		_token : "<?= crsf_token() ?>",
		"g-recaptcha-response": gtoken,
		register : '',
		username : $('#input-username').val(),
		email    : $('#input-email').val(),
		email_code : $('#input-email_code').val(),
		password : md5($('#input-password').val(), "<?= getPasswordClientSalt() ?>")
	}, function(msg) {
		if (/^欢迎你！/.test(msg)) {
			BootstrapDialog.show({
				title	 : '注册成功',
				message : msg,
				type		: BootstrapDialog.TYPE_SUCCESS,
				buttons: [{
					label: '好的',
					action: function(dialog) {
						dialog.close();
					}
				}],
				onhidden : function(dialog) {
					var prevUrl = document.referrer;
					if (!prevUrl) {
						prevUrl = '/';
					};
					window.location.href = prevUrl;
				}
			});
		} else {
			BootstrapDialog.show({
				title	 : '注册失败',
				message : msg,
				type		: BootstrapDialog.TYPE_DANGER,
				buttons: [{
					label: '好的',
					action: function(dialog) {
						dialog.close();
					}
				}],
			});
		}
	});
}
$(document).ready(function() {
	$('#button-send-email-code').click(function() {
		if (!showErrorHelp('email', validateRegisterEmail($('#input-email').val()))) {
			return;
		}
		var $button = $(this);
		$button.prop('disabled', true).text('发送中...');
		$.ajax({
			url : '/register',
			type : 'POST',
			dataType : 'json',
			data : {
				_token : "<?= crsf_token() ?>",
				send_email_code : '',
				email : $('#input-email').val()
			},
			success : function(data) {
				showRegisterEmailCodeMessage(data.msg, !data.ok);
				if (data.ok || data.retry_after) {
					setRegisterEmailCodeButtonCooldown(data.retry_after || 60);
				} else {
					$button.prop('disabled', false).text('发送验证码');
				}
			},
			error : function(XMLHttpRequest) {
				showRegisterEmailCodeMessage(XMLHttpRequest.responseText || '验证码发送失败，请稍后重试。', true);
				$button.prop('disabled', false).text('发送验证码');
			}
		});
	});
});
<?php if (!recaptcha_available()): ?>
$(document).ready(function() {
	$('#form-register').submit(function(e) {
		submitRegisterPost(null);
		return false;
	});
});
<?php endif ?>
</script>
<?php echoUOJPageFooter() ?>
