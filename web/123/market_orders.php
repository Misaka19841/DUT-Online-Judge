<?php
require __DIR__ . '/market_common.php';

market_require_login();

$orders = [];
$q_sold_with_buyer = [];
$q_order_detail = [];
$q_u001_sold = [];

if (market_tables_ready()) {
    $orders = DB::selectAll("
        select O.order_id, O.item_id, I.item_name, I.seller_id, O.buyer_id, O.order_date
        from orders O
        join item I on I.item_id = O.item_id
        order by O.order_id desc
    ");

    // 连接查询1：所有已售商品及买家姓名
    $q_sold_with_buyer = DB::selectAll("
        select I.item_id, I.item_name, O.buyer_id
        from item I
        join orders O on O.item_id = I.item_id
        where I.status = 1
        order by I.item_id desc
    ");

    // 连接查询2：每个订单（商品名 + 买家名 + 日期）
    $q_order_detail = DB::selectAll("
        select O.order_id, I.item_name, O.buyer_id, O.order_date
        from orders O
        join item I on I.item_id = O.item_id
        order by O.order_id desc
    ");

    // 连接查询3：卖家是 u001 的商品是否被购买
    $q_u001_sold = DB::selectAll("
        select I.item_id, I.item_name, I.status,
               case when O.item_id is null then '否' else '是' end as purchased
        from item I
        left join orders O on O.item_id = I.item_id
        where I.seller_id = 'u001'
        order by I.item_id desc
    ");
}
?>
<?php echoUOJPageHeader('订单列表') ?>
<h2 class="page-header">订单列表</h2>
<?php market_nav('orders'); ?>
<?php market_require_schema_or_hint(); ?>

<?php if (market_tables_ready()): ?>
<table class="table table-bordered table-hover">
    <thead>
        <tr><th>订单ID</th><th>商品ID</th><th>商品名</th><th>卖家</th><th>买家</th><th>日期</th></tr>
    </thead>
    <tbody>
        <?php foreach ($orders as $row): ?>
        <tr>
            <td><?= (int)$row['order_id'] ?></td>
            <td><?= (int)$row['item_id'] ?></td>
            <td><?= HTML::escape($row['item_name']) ?></td>
            <td><?= getUserLink($row['seller_id']) ?></td>
            <td><?= getUserLink($row['buyer_id']) ?></td>
            <td><?= HTML::escape($row['order_date']) ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>

<h4>连接查询结果</h4>
<ul>
    <li>已售商品及买家：<?= count($q_sold_with_buyer) ?> 条</li>
    <li>订单明细（商品名 + 买家名 + 日期）：<?= count($q_order_detail) ?> 条</li>
    <li>卖家为 `u001` 的商品购买情况：<?= count($q_u001_sold) ?> 条</li>
</ul>
<?php endif ?>

<?php echoUOJPageFooter() ?>
