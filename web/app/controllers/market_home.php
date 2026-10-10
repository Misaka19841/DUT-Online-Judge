<?php
require __DIR__ . '/market_common.php';

market_require_login();

$stats = null;
$category_stats = [];
$top_user = null;

if (market_tables_ready()) {
    $stats = DB::selectFirst([
        "select count(*) as item_total, avg(price) as avg_price from item"
    ]);
    $category_stats = DB::selectAll([
        "select category, count(*) as cnt from item group by category order by cnt desc, category asc"
    ]);
    $top_user = DB::selectFirst([
        "select seller_id, count(*) as cnt",
        "from item",
        "group by seller_id",
        "order by cnt desc, seller_id asc",
        DB::limit(1)
    ]);
}
?>
<?php echoUOJPageHeader('校园二手交易平台') ?>
<h2 class="page-header">校园二手交易平台</h2>
<?php market_nav('home'); ?>
<?php market_require_schema_or_hint(); ?>

<?php if ($stats !== null): ?>
<div class="row">
    <div class="col-sm-4">
        <div class="panel panel-default">
            <div class="panel-heading">商品总数</div>
            <div class="panel-body"><?= (int)$stats['item_total'] ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="panel panel-default">
            <div class="panel-heading">商品平均价格</div>
            <div class="panel-body"><?= $stats['avg_price'] === null ? '0.00' : number_format((float)$stats['avg_price'], 2) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="panel panel-default">
            <div class="panel-heading">发布商品最多用户</div>
            <div class="panel-body">
                <?php if ($top_user): ?>
                    <?= getUserLink($top_user['seller_id']) ?>（<?= (int)$top_user['cnt'] ?>）
                <?php else: ?>
                    暂无数据
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<h4>每类商品数量</h4>
<table class="table table-bordered table-hover">
    <thead>
        <tr><th>类别</th><th>数量</th></tr>
    </thead>
    <tbody>
        <?php foreach ($category_stats as $row): ?>
            <tr>
                <td><?= HTML::escape($row['category']) ?></td>
                <td><?= (int)$row['cnt'] ?></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>

<?php endif ?>

<?php echoUOJPageFooter() ?>

