<?php
$toolboxItems = $toolboxItems ?? [];
$toolboxNotice = $toolboxNotice ?? null;
?>
<style>
.toolbox-panel {
    margin-top: 22px;
}
.toolbox-header {
    margin-bottom: 18px;
}
.toolbox-header h3 {
    margin: 0;
    font-weight: 700;
}
.toolbox-subtitle {
    margin-top: 6px;
    color: #66758A;
}
.toolbox-notice {
    margin-bottom: 16px;
    color: #6A7A8E;
}
.toolbox-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-auto-rows: 150px;
    gap: 16px;
}
.toolbox-tile {
    display: block;
    position: relative;
    padding: 18px 20px;
    border-radius: 18px;
    color: #24364C;
    text-decoration: none;
    box-shadow: 0 12px 28px rgba(30, 52, 84, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    overflow: hidden;
}
.toolbox-tile:hover,
.toolbox-tile:focus {
    color: #24364C;
    text-decoration: none;
    transform: translateY(-2px);
    box-shadow: 0 16px 36px rgba(30, 52, 84, 0.14);
}
.toolbox-tile-title {
    font-size: 22px;
    font-weight: 700;
    line-height: 1.2;
}
.toolbox-tile-subtitle {
    margin-top: 10px;
    font-size: 14px;
    line-height: 1.6;
    color: rgba(36, 54, 76, 0.82);
}
@media (max-width: 1199px) {
    .toolbox-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 991px) {
    .toolbox-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 767px) {
    .toolbox-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="toolbox-panel">
    <div class="toolbox-header">
        <h3>工具箱</h3>
        <div class="toolbox-subtitle">把赛时常用资料整理成磁贴入口，原有参考页直接作为内置工具保留。</div>
    </div>

    <?php if ($toolboxNotice !== null): ?>
        <div class="alert alert-info toolbox-notice"><?= HTML::escape($toolboxNotice) ?></div>
    <?php endif ?>

    <div class="toolbox-grid">
        <?php foreach ($toolboxItems as $item): ?>
            <?php
            $colStart = max(1, (int)$item['grid_x'] + 1);
            $rowStart = max(1, (int)$item['grid_y'] + 1);
            $colSpan = max(1, (int)$item['grid_w']);
            $rowSpan = max(1, (int)$item['grid_h']);
            ?>
            <a
                class="toolbox-tile"
                href="<?= HTML::escape($item['tool_url'] !== '' ? $item['tool_url'] : 'javascript:void(0)') ?>"
                <?= $item['tool_url'] !== '' ? 'target="_blank"' : '' ?>
                style="background: <?= HTML::escape($item['tile_color']) ?>; grid-column: <?= $colStart ?> / span <?= $colSpan ?>; grid-row: <?= $rowStart ?> / span <?= $rowSpan ?>;"
            >
                <div class="toolbox-tile-title"><?= HTML::escape($item['title']) ?></div>
                <?php if ($item['subtitle'] !== ''): ?>
                    <div class="toolbox-tile-subtitle"><?= HTML::escape($item['subtitle']) ?></div>
                <?php endif ?>
            </a>
        <?php endforeach ?>
    </div>
</div>
