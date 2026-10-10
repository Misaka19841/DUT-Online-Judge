<?php




// 添加安全模式检查函数
function checkSafeMode() {
    try {
        $row = DB::selectFirst([
            "select value from meta",
            "where", ["name" => "safemode"],
            DB::limit(1)
        ]);
        return $row ? $row['value'] == '1' : false;
    } catch (Exception $e) {
        return false;
    }
}

// 检查安全模式
$safeModeEnabled = checkSafeMode();
if ($safeModeEnabled) {
    // 安全模式开启，显示友好的提示页面
    echoUOJPageHeader('比赛模式已开启');
    ?>
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel panel-danger top-buffer-lg">
                    <div class="panel-heading">
                        <h3 class="panel-title">访问受限</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2><i class="fa fa-lock fa-3x text-danger"></i></h2>
                        <h3>管理员开启了比赛模式</h3>
                        <p class="text-muted">博客功能暂时不可用</p>
                        <hr>
                        <div class="btn-group">
                            <a href="/" class="btn btn-primary">返回首页</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    echoUOJPageFooter();
    exit;
}







	UOJBlog::init(UOJRequest::get('id')) || UOJResponse::page404();
	UOJBlog::cur()->belongsToUserBlog() || UOJResponse::page404();
	UOJBlog::cur()->userCanView(Auth::user()) || UOJResponse::page403();
	!UOJBlog::cur()->isDraft() || UOJResponse::page404();

	$blog = UOJBlog::info();
	
	// 在显示博客的代码中加入：
	//$content = UOJBlog::cur()->queryContent();
	//echo $content['content'];
	//die();
	
	function getCommentContentToDisplay($comment) {
		if (!$comment['is_hidden']) {
			return $comment['content'];
		} else {
			return '<span class="text-muted">【'.HTML::escape($comment['reason_to_hide']).'】</span>';
		}
	}
	
	$comment_form = new UOJForm('comment');
	$comment_form->addVTextArea('comment', '内容', '',
		function($comment) {
			if (!Auth::check()) {
				return '请先登录';
			}
			if (!$comment) {
				return '评论不能为空';
			}
			if (strlen($comment) > 1000) {
				return '不能超过1000个字节';
			}
			return '';
		},
		null
	);
	$comment_form->handle = function() {
		global $blog, $comment_form;
		$comment = HTML::escape($_POST['comment']);
		
		list($comment, $referrers) = uojHandleAtSign($comment, "/blog/{$blog['id']}");
		
		DB::insert([
            "insert into blogs_comments",
            "(poster, blog_id, content, reply_id, post_time)",
            "values", DB::tuple([Auth::id(), $blog['id'], $comment, 0, DB::now()])
        ]);
		$comment_id = DB::insert_id();
		
		$rank = DB::selectCount([
            "select count(*) from blogs_comments",
            "where", [
                "blog_id" => $blog['id'],
                "reply_id" => 0,
                ["id", "<", $comment_id]
            ]
        ]);
		$page = floor($rank / 20) + 1;
		
		$uri = getLongTablePageUri($page) . '#' . "comment-{$comment_id}";
		
		foreach ($referrers as $referrer) {
			$content = '有人在博客 ' . $blog['title'] . ' 的评论里提到你：<a href="' . $uri . '">点击此处查看</a>';
			sendSystemMsg($referrer, '有人提到你', $content);
		}
		
		if ($blog['poster'] !== Auth::id()) {
			$content = '有人回复了您的博客 ' . $blog['title'] . ' ：<a href="' . $uri . '">点击此处查看</a>';
			sendSystemMsg($blog['poster'], '博客新回复通知', $content);
		}
		
		UOJBlog::cur()->updateActiveTime();
		
		$comment_form->succ_href = getLongTablePageRawUri($page);
	};
	$comment_form->ctrl_enter_submit = true;
	
	$comment_form->runAtServer();
	
	$reply_form = new UOJForm('reply');
	$reply_form->addHidden('reply_id', '0',
		function($reply_id, &$vdata) {
			global $blog;
			if (!validateUInt($reply_id) || $reply_id == 0) {
				return '您要回复的对象不存在';
			}
			$comment = UOJBlogComment::query($reply_id);
			if (!$comment || $comment->info['blog_id'] != $blog['id']) {
				return '您要回复的对象不存在';
			}
			$vdata['parent'] = $comment;
			return '';
		},
		null
	);
	$reply_form->addVTextArea('reply_comment', '内容', '',
		function($comment) {
			if (!Auth::check()) {
				return '请先登录';
			}
			if (!$comment) {
				return '评论不能为空';
			}
			if (strlen($comment) > 500) {
				return '不能超过500个字节';
			}
			return '';
		},
		null
	);
	$reply_form->handle = function(&$vdata) {
		global $blog, $reply_form;
		$comment = HTML::escape($_POST['reply_comment']);
		
		list($comment, $referrers) = uojHandleAtSign($comment, "/blog/{$blog['id']}");
		
		$reply_id = $_POST['reply_id'];
		
		DB::insert([
            "insert into blogs_comments",
            "(poster, blog_id, content, reply_id, post_time)",
            "values", DB::tuple([Auth::id(), $blog['id'], $comment, $reply_id, DB::now()])
        ]);
		$comment_id = DB::insert_id();
		
		$rank = DB::selectCount([
            "select count(*) from blogs_comments",
            "where", [
                "blog_id" => $blog['id'],
                "reply_id" => 0,
                ["id", "<", $reply_id]
            ]
        ]);
		$page = floor($rank / 20) + 1;
		
		$uri = getLongTablePageUri($page) . '#' . "comment-{$reply_id}";
		
		foreach ($referrers as $referrer) {
			$content = '有人在博客 ' . $blog['title'] . ' 的评论里提到你：<a href="' . $uri . '">点击此处查看</a>';
			sendSystemMsg($referrer, '有人提到你', $content);
		}
		
		$parent = $vdata['parent'];
		$notified = [];
		if ($parent->info['poster'] !== Auth::id()) {
			$notified[] = $parent->info['poster'];
			$content = '有人回复了您在博客 ' . $blog['title'] . ' 下的评论 ：<a href="' . $uri . '">点击此处查看</a>';
			sendSystemMsg($parent->info['poster'], '评论新回复通知', $content);
		}
		if ($blog['poster'] !== Auth::id() && !in_array($blog['poster'], $notified)) {
			$notified[] = $blog['poster'];
			$content = '有人回复了您的博客 ' . $blog['title'] . ' ：<a href="' . $uri . '">点击此处查看</a>';
			sendSystemMsg($blog['poster'], '博客新回复通知', $content);
		}
		
		UOJBlog::cur()->updateActiveTime();
		
		$reply_form->succ_href = getLongTablePageRawUri($page);
	};
	$reply_form->ctrl_enter_submit = true;
	
	$reply_form->runAtServer();
	
	$comments_pag = new Paginator([
		'col_names' => ['*'],
		'table_name' => 'blogs_comments',
		'cond' => [
            'blog_id' => $blog['id'],
            'reply_id' => 0
        ],
		'tail' => 'order by id asc',
		'page_len' => 20
    ]);
?>
<?php
	$REQUIRE_LIB['mathjax'] = '';
	$REQUIRE_LIB['shjs'] = '';
?>
<?php echoUOJPageHeader(HTML::stripTags($blog['title']) . ' - 博客') ?>
<style type="text/css">
@media (min-width: 1680px) {
        #blog-toc {
                position: fixed;
                top: 160px;
                left: 0;
                width: 52px;
                max-height: calc(100vh - 180px);
                overflow: hidden;
                border: 1px solid #ddd;
                border-left-width: 4px;
                border-radius: 0 8px 8px 0;
                background: rgba(255, 255, 255, 0.97);
                box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
                transition: width 0.2s ease;
                z-index: 20;
        }
        #blog-toc:hover {
                width: 260px;
        }
        #blog-toc .blog-toc-tab {
                padding: 12px 14px;
                font-weight: bold;
                color: #666;
                border-bottom: 1px solid #eee;
                white-space: nowrap;
        }
        #blog-toc .blog-toc-list {
                display: none;
                max-height: calc(100vh - 230px);
                overflow-y: auto;
                padding: 8px 0;
        }
        #blog-toc:hover .blog-toc-list {
                display: block;
        }
        #blog-toc .blog-toc-item {
                display: block;
                padding: 4px 12px;
                color: #555;
                line-height: 1.45;
                text-decoration: none;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
        }
        #blog-toc .blog-toc-item:hover,
        #blog-toc .blog-toc-item:focus {
                background: #f5f5f5;
                color: #222;
                text-decoration: none;
        }
        #blog-toc .blog-toc-level-2 {
                padding-left: 24px;
        }
        #blog-toc .blog-toc-level-3 {
                padding-left: 36px;
        }
        .uoj-article h1[id],
        .uoj-article h2[id],
        .uoj-article h3[id] {
                scroll-margin-top: 70px;
        }
}
</style>
<div id="blog-toc" style="display:none">
        <div class="blog-toc-tab">目录</div>
        <div class="blog-toc-list"></div>
</div>
<?php UOJBlog::cur()->echoView(['show_title_only' => isset($_GET['page']) && $_GET['page'] != 1]) ?>
<script type="text/javascript">
$(document).ready(function() {
        var $toc = $('#blog-toc');
        if (!$toc.length || window.innerWidth < 1680) {
                return;
        }

        var article = document.querySelector('.panel .uoj-article');
        if (!article) {
                return;
        }

        var headings = article.querySelectorAll('h1, h2, h3');
        if (!headings.length) {
                return;
        }

        var $list = $toc.find('.blog-toc-list');
        var usedIds = {};
        for (var index = 0; index < headings.length; index++) {
                var heading = headings[index];
                var text = (heading.textContent || '').replace(/\s+/g, ' ').trim();
                if (!text) {
                        continue;
                }

                if (!heading.id) {
                        heading.id = 'blog-toc-heading-' + (index + 1);
                } else if (usedIds[heading.id]) {
                        heading.id = heading.id + '-toc-' + (index + 1);
                }
                usedIds[heading.id] = true;

                var level = parseInt(heading.tagName.substring(1), 10);
                var $link = $('<a></a>')
                        .addClass('blog-toc-item blog-toc-level-' + level)
                        .attr('href', '#' + heading.id)
                        .text(text);
                $list.append($link);
        }

        if ($list.children().length) {
                $toc.show();
        }
});
</script>
<h2>评论 <span class="glyphicon glyphicon-comment"></span></h2>
<div class="list-group">
<?php if ($comments_pag->isEmpty()): ?>
	<div class="list-group-item text-muted">暂无评论</div>
<?php else: ?>
	<?php foreach ($comments_pag->get() as $comment):
		$poster = UOJUser::query($comment['poster']);
		$esc_email = HTML::escape($poster['email']);
		$asrc = HTML::avatar_addr($poster, 80);
		
		$replies = DB::selectAll([
            "select id, poster, content, post_time, is_hidden, reason_to_hide from blogs_comments",
            "where", ["reply_id" => $comment['id']],
            "order by id"
        ]);
		foreach ($replies as $idx => $reply) {
			$replies[$idx]['poster_rating'] = UOJUser::query($reply['poster'])['rating'];
			$replies[$idx]['content'] = getCommentContentToDisplay($reply);
		}
		$replies_json = json_encode($replies);
	?>
	<div id="comment-<?= $comment['id'] ?>" class="list-group-item">
		<div class="media">
			<div class="media-left comtposterbox">
				<a href="<?= HTML::url('/user/profile/'.$poster['username']) ?>" class="hidden-xs">
					<img class="media-object img-rounded" src="<?= $asrc ?>" alt="avatar" />
				</a>
			</div>
			<div id="comment-body-<?= $comment['id'] ?>" class="media-body comtbox">
				<div class="row">
					<div class="col-sm-6"><?= getUserLink($poster['username']) ?></div>
					<div class="col-sm-6 text-right"><?= ClickZans::getBlock('BC', $comment['id'], $comment['zan']) ?></div>
				</div>
				<div class="comtbox1"><?= getCommentContentToDisplay($comment) ?></div>
				<ul class="text-right list-inline bot-buffer-no"><li><small class="text-muted"><?= $comment['post_time'] ?></small></li><li><a id="reply-to-<?= $comment['id'] ?>" href="#">回复</a></li></ul>
				<?php if ($replies): ?>
				<div id="replies-<?= $comment['id'] ?>" class="comtbox5"></div>
				<?php endif ?>
				<script type="text/javascript">showCommentReplies('<?= $comment['id'] ?>', <?= $replies_json ?>);</script>
			</div>
		</div>
	</div>
	<?php endforeach ?>
<?php endif ?>
</div>
<?= $comments_pag->pagination() ?>

<h3>发表评论</h3>
<p>可以用@mike来提到mike这个用户，mike会被高亮显示。如果你真的想打“@”这个字符，请用“@@”。</p>
<?php $comment_form->printHTML() ?>

<div id="div-form-reply" style="display:none">
	<?php $reply_form->printHTML() ?>
</div>

<?php echoUOJPageFooter() ?>
