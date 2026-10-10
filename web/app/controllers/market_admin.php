<?php
require __DIR__ . '/market_common.php';

market_require_login();
market_is_admin() || UOJResponse::page403();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crsf_defend();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_item') {
            if (!market_tables_ready()) {
                $err = '请先由数据库管理员建好 item / orders / pending_orders 表。';
            } else {
                $name = trim($_POST['item_name'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $price_raw = $_POST['price'] ?? '';
                $price = 0;
                if ($name === '' || $category === '') {
                    $err = '商品名与类别不能为空。';
                } elseif (!market_parse_price($price_raw, $price)) {
                    $err = '价格格式非法（最多两位小数）。';
                } else {
                    DB::insert([
                        "insert into item",
                        "(item_name, category, price, seller_id, status, created_at)",
                        "values", DB::tuple([$name, $category, $price, Auth::id(), 0, DB::now()])
                    ]);
                    $msg = '新增商品成功。';
                }
            }
        } elseif ($action === 'update_price') {
            if (!market_tables_ready()) {
                $err = '请先由数据库管理员建好 item / orders / pending_orders 表。';
            } else {
                $item_id_str = $_POST['item_id'] ?? '';
                $price_raw = $_POST['new_price'] ?? '';
                $new_price = 0;
                if (!validateUInt($item_id_str)) {
                    $err = '商品 ID 非法。';
                } elseif (!market_parse_price($price_raw, $new_price)) {
                    $err = '新价格格式非法。';
                } else {
                    DB::update([
                        "update item",
                        "set", ["price" => $new_price],
                        "where", ["item_id" => (int)$item_id_str]
                    ]);
                    if (DB::affected_rows() > 0) {
                        $msg = '改价成功。';
                    } else {
                        $err = '未找到对应商品，或价格未变化。';
                    }
                }
            }
        } elseif ($action === 'delete_unsold') {
            if (!market_tables_ready()) {
                $err = '请先由数据库管理员建好 item / orders / pending_orders 表。';
            } else {
                $item_id_str = $_POST['item_id'] ?? '';
                if (!validateUInt($item_id_str)) {
                    $err = '商品 ID 非法。';
                } else {
                    DB::delete([
                        "delete from item",
                        "where", [
                            "item_id" => (int)$item_id_str,
                            "status" => 0
                        ]
                    ]);
                    if (DB::affected_rows() > 0) {
                        $msg = '删除未售商品成功。';
                    } else {
                        $err = '删除失败：商品不存在或已售出。';
                    }
                }
            }
        }
    } catch (Exception $e) {
        $err = '操作失败：' . HTML::escape($e->getMessage());
    }
}
?>
<?php echoUOJPageHeader('二手交易后台') ?>
<h2 class="page-header">二手交易后台（仅 S）</h2>
<?php market_nav('admin'); ?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif ?>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">插入新商品</div>
            <div class="panel-body">
                <form method="post" class="form-horizontal">
                    <?= HTML::hiddenToken() ?>
                    <input type="hidden" name="action" value="add_item" />
                    <div class="form-group">
                        <label class="col-sm-3 control-label">商品名</label>
                        <div class="col-sm-9"><input class="form-control" name="item_name" required /></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">类别</label>
                        <div class="col-sm-9"><input class="form-control" name="category" required /></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">价格</label>
                        <div class="col-sm-9"><input class="form-control" name="price" required /></div>
                    </div>
                    <div class="text-right"><button class="btn btn-success">新增</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">修改商品价格</div>
            <div class="panel-body">
                <form method="post" class="form-inline">
                    <?= HTML::hiddenToken() ?>
                    <input type="hidden" name="action" value="update_price" />
                    <input class="form-control" name="item_id" placeholder="商品ID" required />
                    <input class="form-control" name="new_price" placeholder="新价格" required />
                    <button class="btn btn-warning">修改</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">删除未售商品</div>
            <div class="panel-body">
                <form method="post" class="form-inline">
                    <?= HTML::hiddenToken() ?>
                    <input type="hidden" name="action" value="delete_unsold" />
                    <input class="form-control" name="item_id" placeholder="商品ID" required />
                    <button class="btn btn-danger">删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php echoUOJPageFooter() ?>

