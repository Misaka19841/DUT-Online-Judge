<?php
        $blogs = DB::selectAll([
        "select blogs.id, title, poster, is_new, post_time from important_blogs, blogs",
        "where", [
            "is_hidden" => 0,
            "important_blogs.blog_id" => DB::raw("blogs.id")
        ], "order by level desc, important_blogs.blog_id desc",
        DB::limit(6)
    ]);
    $images = DB::selectAll([
        "select id, explanation, image_url, click_link from image_explanations",
        "order by id desc",
        DB::limit(10)
    ]);
?>
<?php echoUOJPageHeader('DOJ') ?>
<div class="panel panel-default">
        <div class="panel-body">
                <div class="row">
                        <div class="col-sm-12 col-md-9">
                                <table class="table">
                                        <thead>
                                                <tr>
                                                        <th style="width:60%"><?= UOJLocale::get('announcements') ?></th>
                                                        <th style="width:20%"></th>
                                                        <th style="width:20%"></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                        <?php $now_cnt = 0; ?>
                                        <?php foreach ($blogs as $blog_info): ?>
                                                <?php
                                                        $blog = new UOJBlog($blog_info);
                                                        $now_cnt++;
                                                ?>
                                                <tr>
                                                        <td><?= $blog->getLink(['show_new_tag' => true]) ?></td>
                                                        <td>by <?= getUserLink($blog->info['poster']) ?></td>
                                                        <td><small><?= $blog->info['post_time'] ?></small></td>
                                                </tr>
                                        <?php endforeach ?>
                                        <?php for ($i = $now_cnt + 1; $i <= 5; $i++): ?>
                                                <tr><td colspan="233">&nbsp;</td></tr>
                                        <?php endfor ?>
                                                <tr><td class="text-right" colspan="233"><a href="/announcements"><?= UOJLocale::get('all the announcements') ?></a></td></tr>
                                        </tbody>
                                </table>
                        </div>
                        <div class="col-xs-6 col-sm-4 col-md-3">
                                <?php if (!empty($images)): ?>
                                <div id="homeCarousel" class="carousel slide" data-ride="carousel">
                                        <ol class="carousel-indicators">
                                                <?php foreach ($images as $k => $img): ?>
                                                        <li data-target="#homeCarousel" data-slide-to="<?= $k ?>"<?= $k === 0 ? ' class="active"' : '' ?>></li>
                                                <?php endforeach ?>
                                        </ol>
                                        <div class="carousel-inner">
                                                <?php foreach ($images as $k => $img): ?>
                                                        <div class="item<?= $k === 0 ? ' active' : '' ?>">
                                                                <?php $href = isset($img['click_link']) ? trim($img['click_link']) : ''; ?>
                                                                <?php if ($href !== ''): ?>
                                                                        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                                                                                <img class="img-responsive img-thumbnail" src="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($img['explanation'], ENT_QUOTES, 'UTF-8') ?>">
                                                                        </a>
                                                                <?php else: ?>
                                                                        <img class="img-responsive img-thumbnail" src="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($img['explanation'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <?php endif ?>
                                                                <div class="text-center" style="margin-top:6px;">
                                                                        <small><?= htmlspecialchars($img['explanation'], ENT_QUOTES, 'UTF-8') ?></small>
                                                                </div>
                                                        </div>
                                                <?php endforeach ?>
                                        </div>
                                        <a class="left carousel-control" href="#homeCarousel" role="button" data-slide="prev">
                                                <span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span>
                                                <span class="sr-only">Previous</span>
                                        </a>
                                        <a class="right carousel-control" href="#homeCarousel" role="button" data-slide="next">
                                                <span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span>
                                                <span class="sr-only">Next</span>
                                        </a>
                                </div>
                                <?php else: ?>
                                        <a href="contest/6">
                                                <img class="media-object img-thumbnail" src="<?= HTML::url('/pictures/s24.png', ['location' => 'cdn']) ?>" alt="UOJ logo" />
                                        </a>
                                <?php endif ?>
                        </div>
                </div>
        </div>
</div>
<div class="row">
	<div class="col-md-6">
		<h3><?= UOJLocale::get('top rated > active users') ?></h3>
		<?php UOJRanklist::printHTML(['top10' => true, 'active_users_only' => true]) ?>
		<div class="text-center">
			<a href="/ranklist"><?= UOJLocale::get('view all') ?></a>
		</div>
	</div>
	<div class="col-md-6">
		<h3><?= UOJLocale::get('top rated > all users') ?></h3>
		<?php UOJRanklist::printHTML(['top10' => true]) ?>
		<div class="text-center">
			<a href="/ranklist/all-users"><?= UOJLocale::get('view all') ?></a>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>

