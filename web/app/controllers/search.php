<?php
contest_security_mode_block_if_needed('blog', Auth::user());
requireLib('mathjax');
requireLib('shjs');
echoUOJPageHeader('博客搜索');

// 获取搜索参数
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$username = isset($_GET['user']) ? trim($_GET['user']) : '';
$tag = isset($_GET['tag']) ? trim($_GET['tag']) : '';

// 初始化查询条件
$conditions = ["is_draft" => false];

// 权限控制
if (!UOJUserBlog::userCanManage(Auth::user())) {
    $conditions["is_hidden"] = false;
}

// 关键词搜索条件
if ($keyword !== '') {
    $conditions[] = DB::lor([
        ["title" => ["LIKE" => "%$keyword%"]],
        ["content" => ["LIKE" => "%$keyword%"]]
    ]);
}

// 用户筛选
if ($username !== '') {
    $user = UOJUser::query($username);
    if ($user) {
        $conditions["poster"] = $user['username'];
    } else {
        echo '<div class="alert alert-danger">未找到用户：'.HTML::escape($username).'</div>';
    }
}

// 标签筛选
if ($tag !== '') {
    $conditions[] = UOJBlog::getTagSearchCondition($tag);
}

// 分页查询博客
$blogs_pag = new Paginator([
    'col_names' => ['*'],
    'table_name' => 'blogs',
    'cond' => $conditions,
    'tail' => 'order by post_time desc',
    'page_len' => 10
]);
?>

<div class="row">
    <!-- 左侧筛选栏 -->
    <div class="col-md-3">
        <div class="panel panel-primary">
            <div class="panel-heading">搜索筛选</div>
            <div class="panel-body">
                <form method="get" action="">
                    <div class="form-group">
                        <label>作者</label>
                        <input type="text" name="user" class="form-control" value="<?= HTML::escape($username) ?>">
                    </div>
                    <div class="form-group">
                        <label>标签</label>
                        <input type="text" name="tag" class="form-control" value="<?= HTML::escape($tag) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">搜索</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧结果区 -->
    <div class="col-md-9">
        <?php if ($keyword || $username || $tag): ?>
            <h2>搜索结果</h2>
            
            <?php if ($blogs_pag->isEmpty()): ?>
                <div class="alert alert-warning">
                    没有找到匹配的博客
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    共找到 <?= $blogs_pag->n_rows ?> 篇博客
                </div>
                
                <?php foreach ($blogs_pag->get() as $blog_info): ?>
                    <?php
                    // 完全按照首页的方式显示每篇博客
                    $blog = new UOJBlog($blog_info);
                    $blog->echoView(['is_preview' => true]); // 使用与首页相同的显示方式
                    ?>
                <?php endforeach; ?>
                
                <?= $blogs_pag->pagination() ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info">
                请输入搜索条件
            </div>
        <?php endif; ?>
    </div>
</div>

<?php echoUOJPageFooter() ?>
