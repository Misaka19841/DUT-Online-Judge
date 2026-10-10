<?php

class UOJRanklist {
    // 添加获取用户徽章的方法（包含description）
    private static function getUserBadge($username) {
        static $cache = [];
        
        if (isset($cache[$username])) {
            return $cache[$username];
        }
        
        $db_cfg = UOJConfig::$data['database'];
        
        try {
            $pdo = new PDO(
                "mysql:host={$db_cfg['host']};dbname={$db_cfg['database']};charset=utf8",
                $db_cfg['username'],
                $db_cfg['password']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("SELECT image_url, description FROM user_images WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['image_url'])) {
                $cache[$username] = [
                    'image_url' => $result['image_url'],
                    'description' => $result['description'] ?? ''
                ];
            } else {
                $cache[$username] = null;
            }
            return $cache[$username];
        } catch (PDOException $e) {
            error_log("Database error in getUserBadge: " . $e->getMessage());
            return null;
        }
    }

    public static function updateActiveUserList() {
        $latest_contest = DB::selectFirst([
            "select * from contests",
            "order by start_time desc",
            DB::limit(1)
        ]);
        if ($latest_contest === null) {
            return DB::update([
                "update user_info", "set", [
                    "extra" => DB::json_set('extra', '$.active_in_contest', false)
                ]
            ]);
        } else {
            $latest_contest = new UOJContest($latest_contest);
            $ddl = clone $latest_contest->info['end_time'];
            $duration_M = UOJContext::getMeta('active_duration_M');
            $ddl->modify("-{$duration_M} months");
            return DB::update([
                "update user_info", "set", [
                    "extra" => DB::json_set('extra', '$.active_in_contest',
                        DB::raw(DB::exists([
                            "select 1 from contests C join contests_registrants CR",
                            "on", [
                                ["C.start_time", ">=", UOJTime::time2str($ddl)],
                                "C.id = CR.contest_id",
                                "CR.has_participated" => true
                            ], "where", [
                                "CR.username = user_info.username",
                            ]
                        ]))
                    )
                ]
            ]);
        }
    }

    public static function printHTML($cfg = []) {
        $cfg += [
            'top10' => false,
            'active_users_only' => false
        ];

        if ($cfg['active_users_only']) {
            $conds = ['active_in_contest' => true];
        } else {
            $conds = '1';
        }

        $last_user = null;
        $print_row = function($user, $now_cnt) use(&$last_user, &$conds) {
            if ($last_user === null) {
                $rank = DB::selectCount([
                    "select count(*) from user_info",
                    "where", [
                        ["rating", ">", $user['rating']],
                        DB::conds($conds)
                    ]
                ]);
                $rank++;
            } else if ($user['rating'] == $last_user['rating']) {
                $rank = $last_user['rank'];
            } else {
                $rank = $now_cnt;
            }
            
            $user['rank'] = $rank;

            $userpro = HTML::url('/user/profile/'.$user['username']);
            $userlink = getUserLink($user['username'], $user['rating']);
            $asrc = HTML::avatar_addr($user, 50);
            $esc_motto = HTML::escape($user['motto'], ['single_line' => true]);
            
            // 获取用户徽章信息
            $badge_info = self::getUserBadge($user['username']);
            
            // 头像和徽章组合的HTML
            $avatar_html = <<<EOD
            <div style="position: relative; display: inline-block;">
                <img class="media-object img-rounded" src="{$asrc}" style="width: 50px; height: 50px;" />
            EOD;
            
            if ($badge_info) {
                $badge_url = HTML::escape($badge_info['image_url']);
                $description = HTML::escape($badge_info['description']);
                $avatar_html .= <<<EOD
                <img src="{$badge_url}" 
                     style="position: absolute; bottom: -8px; right: -8px; width: 30px; height: 30px; border-radius: 50%; border: 2px solid white; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.2); cursor: pointer;" 
                     title="{$description}"
                     data-toggle="tooltip"
                     data-placement="top" />
                EOD;
            }
            
            $avatar_html .= '</div>';
            
            echo <<<EOD
            <div class="list-group-item">
                <div class="media">
                    <div class="media-left">
                        <a href="{$userpro}">{$avatar_html}</a>
                    </div>
                    <div class="media-body">
                        <div class="row">
                            <h4 class="col-sm-8 media-heading">#{$user['rank']}: {$userlink}</h4>
                            <div class="col-sm-4 text-right"><strong>Rating: {$user['rating']}</strong></div>
                        </div>
                        <div class="uoj-text">{$esc_motto}</div>
                    </div>
                </div>
            </div>
            EOD;

            $last_user = $user;
        };


        $pag_config = [
            'get_row_index' => '',
            'table_name' => 'user_info',
            'col_names' => ['username', 'rating', 'email', 'motto', 'extra'],
            'cond' => $conds,
            'tail' => 'order by rating desc, username asc'
        ];
        
        if ($cfg['top10']) {
            $pag_config['tail'] .= ' limit 10';
            $pag_config['echo_full'] = '';
        } else {
            $pag_config['page_len'] = 100;
        }
        
        $pag = new Paginator($pag_config);

        echo '<div class="list-group">';
        foreach ($pag->get() as $idx => $row) {
            $print_row($row, $idx);
        }
        if ($pag->isEmpty()) {
            echo <<<EOD
            <div class="list-group-item">
            无
            </div>
            EOD;
        }
        echo '</div>';
        echo $pag->pagination();
    }

    /**
     * Old style of ranklist
     */
    public static function printTableHTML($cfg = []) {
        $cfg += [
            'top10' => false,
            'active_users_only' => false
        ];

        if ($cfg['active_users_only']) {
            $conds = ['active_in_contest' => true];
        } else {
            $conds = '1';
        }

        $header_row = '';
        $header_row .= '<tr>';
        $header_row .= '<th style="width: 5em;">#</th>';
        $header_row .= '<th style="width: 14em;">'.UOJLocale::get('username').'</th>';
        $header_row .= '<th style="width: 50em;">'.UOJLocale::get('motto').'</th>';
        $header_row .= '<th style="width: 5em;">'.UOJLocale::get('rating').'</th>';
        $header_row .= '</tr>';

        $last_user = null;
        $print_row = function($user, $now_cnt) use(&$last_user, &$conds) {
            if ($last_user === null) {
                $rank = DB::selectCount([
                    "select count(*) from user_info",
                    "where", [
                        ["rating", ">", $user['rating']],
                        DB::conds($conds)
                    ]
                ]);
                $rank++;
            } else if ($user['rating'] == $last_user['rating']) {
                $rank = $last_user['rank'];
            } else {
                $rank = $now_cnt;
            }
            
            $user['rank'] = $rank;
            
            // 获取用户徽章信息
            $badge_info = self::getUserBadge($user['username']);
            
            // 用户名带徽章的HTML
            $username_html = getUserLink($user['username']);
            if ($badge_info) {
                $badge_url = HTML::escape($badge_info['image_url']);
                $description = HTML::escape($badge_info['description']);
                $username_html .= ' <img src="' . $badge_url . '" style="width: 20px; height: 20px; border-radius: 50%; display: inline-block; vertical-align: middle; margin-left: 5px; cursor: pointer;" title="' . $description . '" data-toggle="tooltip" data-placement="top" />';
            }
            
            echo '<table>';
            echo '<td>' . $user['rank'] . '</td>';
            echo '<td>' . $username_html . '</td>';
            echo '<td>' . HTML::escape($user['motto']) . '</td>';
            echo '<td>' . $user['rating'] . '</td>';
            echo '</tr>';
            
            $last_user = $user;
        };

        $col_names = ['username', 'rating', 'motto'];

        $tail = 'order by rating desc, username asc';

        $table_config = [
            'get_row_index' => ''
        ];
        
        if ($cfg['top10']) {
            $tail .= ' limit 10';
            $table_config['echo_full'] = '';
        } else {
            $table_config['page_len'] = 100;
        }
        
        echoLongTable($col_names, 'user_info', $conds, $tail, $header_row, $print_row, $table_config);
    }
}
