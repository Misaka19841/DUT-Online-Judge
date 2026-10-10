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

redirectTo(HTML::blog_url(UOJBlog::info('poster'), UOJContext::requestURI(), ['escape' => false]));
