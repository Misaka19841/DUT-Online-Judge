<?php

    UOJContest::init(UOJRequest::get('id')) || UOJResponse::page404();
    isSuperUser(Auth::user()) || UOJResponse::page403();

// ************************ 核心：构建指定格式JSON数据 ************************
// 1. 初始化返回数据结构
$response = [
    'teamlist' => []
];

try {
    // 2. 查询该比赛的所有报名用户（从contests_registrants表）
    $contest_id = intval($_GET['id']);
    $sql = "SELECT username FROM contests_registrants WHERE contest_id = {$contest_id} ORDER BY username ASC";
    $registrants = DB::selectAll($sql);

    // 3. 无报名用户时，直接返回空结构JSON
    if (empty($registrants)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. 遍历报名用户，构建teamlist
    foreach ($registrants as $registrant) {
        $teamId = $registrant['username'];
        // 跳过无效用户名记录
        if (empty($teamId)) {
            continue;
        }

        // 构建单个团队的信息
        $teamInfo = [
            'teamId' => $teamId,
            'teamName' => $teamId,
            'teamSchool' => '大连理工大学',
            'teamMember' => $teamId,
            'official' => true
        ];

        // 加入teamlist（key为teamId）
        $response['teamlist'][$teamId] = $teamInfo;
    }
} catch (Exception $e) {
    // 异常处理：捕获错误信息（可选，上线后可注释）
    $response['error'] = $e->getMessage();
    $response['teamlist'] = [];
}

// ************************ 返回JSON数据 ************************
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
exit;
?>
