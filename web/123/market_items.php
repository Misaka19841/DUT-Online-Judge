<?php
require __DIR__ . '/market_common.php';

market_require_login();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_item_id'])) {
    crsf_defend();
    if (!market_tables_ready()) {
        $err = '数据表未初始化。';
    } else {
        $item_id_str = $_POST['buy_item_id'];
        if (!validateUInt($item_id_str)) {
            $err = '非法商品编号。';
        } else {
            $item_id = (int)$item_id_str;
            $buyer = Auth::id();
            $ok = true;
            try {
                DB::transaction(function() use ($item_id, $buyer, &$ok, &$err) {
                    $item = DB::selectFirst([
                        "select item_id, status, seller_id from item",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$item) {
                        $ok = false;
                        $err = '商品不存在。';
                        return;
                    }
                    if ((int)$item['status'] !== 0) {
                        $ok = false;
                        $err = '该商品已售出，不能重复购买。';
                        return;
                    }
                    if ($item['seller_id'] === $buyer) {
                        $ok = false;
                        $err = '不能购买自己发布的商品。';
                        return;
                    }
                    DB::insert([
                        "insert into orders",
                        "(item_id, buyer_id, order_date)",
                        "values", DB::tuple([$item_id, $buyer, DB::now()])
                    ]);
                    DB::update([
                        "update item",
                        "set", ["status" => 1],
                        "where", ["item_id" => $item_id]
                    ]);
                });
            } catch (Exception $e) {
                $ok = false;
                $err = '购买失败：' . HTML::escape($e->getMessage());
            }
            if ($ok) {
                $msg = '购买成功。';
            }
        }
    }
}

$all_items = [];
$q_unsold = [];
$q_price_gt_30 = [];
$q_life = [];
$q_u001 = [];

if (market_tables_ready()) {
    $all_items = DB::selectAll([
        "select item_id, item_name, category, price, seller_id, status, created_at from item",
        "order by item_id desc"
    ]);
    $q_unsold = DB::selectAll([
        "select * from item",
        "where", ["status" => 0],
        "order by item_id desc"
    ]);
    $q_price_gt_30 = DB::selectAll([
        "select * from item",
        "where", [["price", ">", 30]],
        "order by item_id desc"
    ]);
    $q_life = DB::selectAll([
        "select * from item",
        "where", ["category" => '生活用品'],
        "order by item_id desc"
    ]);
    $q_u001 = DB::selectAll([
        "select * from item",
        "where", ["seller_id" => 'u001'],
        "order by item_id desc"
    ]);
}
?>
<?php echoUOJPageHeader('商品列表') ?>
<h2 class="page-header">商品列表</h2>
<?php market_nav('items'); ?>
<?php market_require_schema_or_hint(); ?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif ?>

<?php if (market_tables_ready()): ?>
<table class="table table-bordered table-hover">
    <thead>
        <tr>
            <th>ID</th><th>商品名</th><th>类别</th><th>价格</th><th>卖家</th><th>状态</th><th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($all_items as $row): ?>
        <tr>
            <td><?= (int)$row['item_id'] ?></td>
            <td><?= HTML::escape($row['item_name']) ?></td>
            <td><?= HTML::escape($row['category']) ?></td>
            <td><?= number_format((float)$row['price'], 2) ?></td>
            <td><?= getUserLink($row['seller_id']) ?></td>
            <td><?= (int)$row['status'] === 0 ? '未售出' : '已售出' ?></td>
            <td>
                <?php if ((int)$row['status'] === 0): ?>
                    <form method="post" class="form-inline">
                        <?= HTML::hiddenToken() ?>
                        <input type="hidden" name="buy_item_id" value="<?= (int)$row['item_id'] ?>" />
                        <button class="btn btn-xs btn-primary">购买</button>
                    </form>
                <?php else: ?>
                    <span class="text-muted">不可购买</span>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>

<h4>基本查询结果</h4>
<ul>
    <li>未售出商品：<?= count($q_unsold) ?> 条</li>
    <li>价格大于 30 的商品：<?= count($q_price_gt_30) ?> 条</li>
    <li>“生活用品”类商品：<?= count($q_life) ?> 条</li>
    <li>`u001` 发布的商品：<?= count($q_u001) ?> 条</li>
</ul>
<?php endif ?>

<?php echoUOJPageFooter() ?>
