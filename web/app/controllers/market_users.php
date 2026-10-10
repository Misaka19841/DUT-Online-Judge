<?php
require __DIR__ . '/market_common.php';

market_require_login();

$users = [];
if (market_tables_ready()) {
    $users = DB::selectAll("
        select U.username, U.rating, ifnull(S.sell_cnt, 0) as sell_cnt, ifnull(B.buy_cnt, 0) as buy_cnt
        from user_info U
        left join (select seller_id, count(*) as sell_cnt from item group by seller_id) S on S.seller_id = U.username
        left join (select buyer_id, count(*) as buy_cnt from orders group by buyer_id) B on B.buyer_id = U.username
        where S.sell_cnt is not null or B.buy_cnt is not null
        order by U.username asc
    ");
}
?>
<?php echoUOJPageHeader('用户列表') ?>
<h2 class="page-header">用户列表</h2>
<?php market_nav('users'); ?>
<?php market_require_schema_or_hint(); ?>

<?php if (market_tables_ready()): ?>
<table class="table table-bordered table-hover">
    <thead>
        <tr><th>用户名</th><th>Rating</th><th>发布商品数</th><th>购买数</th></tr>
    </thead>
    <tbody>
        <?php foreach ($users as $row): ?>
        <tr>
            <td><?= getUserLink($row['username']) ?></td>
            <td><?= (int)$row['rating'] ?></td>
            <td><?= (int)$row['sell_cnt'] ?></td>
            <td><?= (int)$row['buy_cnt'] ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

<?php echoUOJPageFooter() ?>
