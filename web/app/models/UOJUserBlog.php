<?php

class UOJUserBlog {
    public static ?array $user = null;

    public static function init() {
		$raw_blog_username = $_GET['blog_username'];
		$decoded_username = blog_name_decode($raw_blog_username);

		$user = null;
		if (validateUsername($decoded_username)) {
			$user = UOJUser::query($decoded_username);
		}
		// Backward compatibility: some old links used lowercase blog names.
		if (!$user) {
			$legacy_username = strtolower($decoded_username);
			if (validateUsername($legacy_username)) {
				$user = UOJUser::query($legacy_username);
			}
		}
		if (!$user) {
			UOJResponse::page404();
		}
		self::$user = $user;
		if (self::$user['usergroup'] === 'B') {
			UOJResponse::page404('该用户已被封禁');
		}
		if ($raw_blog_username !== blog_name_encode(self::$user['username'])) {
			permanentlyRedirectTo(HTML::blog_url(self::$user['username'], '/'));
		}
    }

	public static function check() {
		return self::$user !== null;
	}
	public static function id() {
		if (self::$user === null) {
			return null;
		}
		return self::$user['username'];
	}
	public static function user() {
		return self::$user;
    }

    public static function userCanManage(?array $user, ?string $whose_blog = null) {
        if ($whose_blog === null) {
            $whose_blog = self::id();
        }
		return $user && (isSuperUser($user) || $user['username'] === $whose_blog);
    }
}

