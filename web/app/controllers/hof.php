<?php echoUOJPageHeader("Hall of Fame") ?>
<article class="uoj-article">
    <header>
        <h2 class="page-header">大连理工大学ACM名人堂</h2>
    </header>
    <section>
    <header>
    <h5>大连理工大学一校两地三区个人区域赛奖牌统计<span style="color: red;"></span></h5>
    <h5>排名关键字顺序：金牌数（比多），银牌数（比多），铜牌数（比多），铁牌数（比少）</h5>
    </header>
    
    <?php
    try {
        // 1. 获取所有可用的年份
        $years = array_column(DB::selectAll([
            "select distinct year from personal_achievements",
            "order by year desc"
        ]) ?: [], 'year');
        
        // 2. 获取当前选中的年份（默认最新年份）
        $selectedYear = isset($_GET['year']) ? intval($_GET['year']) : ($years ? $years[0] : 2025);
        
        // 3. 根据年份查询数据
        $rows = DB::selectAll([
            "select member_name, gold_medals, silver_medals, bronze_medals, honorable_mentions",
            "from personal_achievements",
            "where", ["year" => $selectedYear],
            "order by gold_medals desc, silver_medals desc, bronze_medals desc, honorable_mentions asc"
        ]) ?: [];
        
        // 4. 输出年份选择按钮（纯HTML+CSS，点击即通过GET刷新页面）
        echo '<div style="margin-bottom: 20px; text-align: center;">';
        echo '<div class="btn-group" role="group">';
        foreach ($years as $year) {
            $activeClass = ($year == $selectedYear) ? 'btn-primary' : 'btn-default';
            echo "<a href=\"?year={$year}\" class=\"btn {$activeClass}\" style=\"margin-right: 5px;\">{$year}年</a>";
        }
        echo '</div>';
        echo '</div>';
        
        // 5. 如果没有数据，提示用户
        if (empty($rows)) {
            echo "<p style='text-align: center; color: gray;'>暂无 {$selectedYear} 年的奖牌记录。</p>";
        } else {
            // 表格样式
            echo '<style>
                .gold-row { background-color: #FFD700 !important; font-weight: bold; }
                .silver-row { background-color: #C0C0C0 !important; font-weight: bold; }
                .bronze-row { background-color: #CD7F32 !important; font-weight: bold; color: white; }
            </style>';
            
            echo '<table class="table table-bordered table-striped">';
            echo '<tr><th>排名</th><th>姓名</th><th>金牌</th><th>银牌</th><th>铜牌</th><th>铁牌</th></tr>';
            
            $rank = 1;
            $prev_gold = null;
            $prev_silver = null;
            $prev_bronze = null;
            $prev_iron = null;
            $display_rank = 1;
            
            foreach($rows as $index => $row) {
                if ($index > 0 && 
                    $row['gold_medals'] == $prev_gold &&
                    $row['silver_medals'] == $prev_silver &&
                    $row['bronze_medals'] == $prev_bronze &&
                    $row['honorable_mentions'] == $prev_iron) {
                    $current_display_rank = $display_rank;
                } else {
                    $display_rank = $rank;
                    $current_display_rank = $display_rank;
                }
                
                $row_class = '';
                if ($row['gold_medals'] > 0) {
                    $row_class = 'class="gold-row"';
                } elseif ($row['silver_medals'] > 0) {
                    $row_class = 'class="silver-row"';
                } elseif ($row['bronze_medals'] > 0) {
                    $row_class = 'class="bronze-row"';
                }
                
                // 生成可点击链接
                $name = $row['member_name'];
                $url = "https://cpcfinder.com/student?name=" . urlencode($name) . "&school=" . urlencode("大连理工大学");
                $name_link = "<a href='{$url}' target='_blank' style='color: inherit; text-decoration: underline;'>{$name}</a>";
                
                echo "<tr {$row_class}>";
                echo "<td>{$current_display_rank}</td>";
                echo "<td>{$name_link}</td>";
                echo "<td>{$row['gold_medals']}</td>";
                echo "<td>{$row['silver_medals']}</td>";
                echo "<td>{$row['bronze_medals']}</td>";
                echo "<td>{$row['honorable_mentions']}</td>";
                echo "<tr>";
                
                $prev_gold = $row['gold_medals'];
                $prev_silver = $row['silver_medals'];
                $prev_bronze = $row['bronze_medals'];
                $prev_iron = $row['honorable_mentions'];
                $rank++;
            }
            echo '</table>';
        }
        
    } catch(Exception $e) {
        echo "<p style='color: red;'>❌ 数据库错误：" . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    </section>
</article>
<?php echoUOJPageFooter() ?>
