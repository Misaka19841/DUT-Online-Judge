<?php
    UOJContest::init(UOJRequest::get('id')) || UOJResponse::page404();
    isSuperUser(Auth::user()) || UOJResponse::page403();


// ************************ 核心：JSON数据处理逻辑 ************************
// 1. 初始化返回数据
$response = [
    'submit' => []
];
try {
    // 2. 定义查询条件（超级管理员可获取该比赛所有提交记录，无需额外过滤is_hidden）
    $contest_id = intval($_GET['id']);
    $table_name = 'submissions';
    // 只查询需要的字段，提高查询效率
    $col_names = [
        'submissions.id',
        'submissions.submitter',
        'submissions.problem_id',
        'submissions.submit_time',
        'submissions.status',
        'submissions.result',
        'submissions.result_error',
        'submissions.score'
    ];

    // 3. 构建SQL查询语句（获取该比赛所有提交记录，按ID降序）
    $cond = "contest_id = {$contest_id}";
    $sql = "SELECT " . implode(', ', $col_names) . " FROM {$table_name} WHERE {$cond} ORDER BY id DESC";
    $submissions = DB::selectAll($sql);
    // 4. 无提交记录时，直接返回空JSON
    if (empty($submissions)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. 定义result_error映射表
    $error_map = [
        'Accepted' => 0,
        'Wrong Answer' => 1,
        'Runtime Error' => 2,
        'Memory Limit Exceeded' => 3,
        'Time Limit Exceeded' => 4,
        'Output Limit Exceeded' => 5,
        'Dangerous Syscalls' => 6,
        'Judgement Failed' => 7,
        'No Comment' => 8,
        'Compile Error' => 9
    ];
    //5.5
 $contest_problems = DB::selectAll([
    "select contests_problems.problem_id, best_ac_submissions.submission_id",
    "from", "contests_problems", "left join", "best_ac_submissions",
    "on", [
        "contests_problems.problem_id" => DB::raw("best_ac_submissions.problem_id"),
        "submitter" => Auth::id()
    ], "where", ["contest_id" => $contest_id],
    "order by contests_problems.weight ASC, contests_problems.problem_id ASC"  // 只改这里
]);

for ($i = 0; $i < count($contest_problems); $i++) {
    $contest_problems[$i]['problem'] = UOJContestProblem::query($contest_problems[$i]['problem_id']);
    $contest_problems[$i]['problem']->problem_number = $i;  // 保持原样，还是从0开始
    
}
//var_dump($contest_problems[1]['problem']);
	for ($i = 0; $i < count($contest_problems); $i++){
		$problem_map[$contest_problems[$i]['problem_id']]= $contest_problems[$i]['problem']->getLetter();
	}
	//var_dump($problem_map);
    // 6. 遍历处理每条提交记录，完成字段映射
    foreach ($submissions as $submission) {
        // 跳过核心字段为空的无效记录
        if (empty($submission['id']) || empty($submission['submitter']) || empty($submission['submit_time'])) {
            continue;
        }

        // 字段1：submitId → $submission['id']（转字符串）
        $submitId = (string)$submission['id'];

        // 字段2：teamId → $submission['submitter']（转字符串）
        $teamId = (string)$submission['submitter'];

        // 字段3：alphabetId → $problem['id']数字转字母（1→A、2→B...）
        $alphabetId = '';
        if (!empty($submission['problem_id'])) {
            $problem_num = intval($submission['problem_id']);
            $alphabetId = $problem_map[$problem_num]; // ASCII码计算实现数字转字母
        }
        // 字段4：subTime → $submission['submit_time']（转字符串）
        $subTime = (string)$submission['submit_time'];
        
        // 字段5：resultId → 按规则判断映射
        $resultId = -1;
        $status = explode(', ', $submission['status'])[0] ?? ''; // 复用项目原有status处理逻辑
        $result_error = $submission['result_error'] ?? '';
        $resultJson = (string)$submission['result'] ?? '';

        $resultData = json_decode($resultJson, true);
        	
        $score = (json_last_error() === JSON_ERROR_NONE) ? ($resultData['score'] ?? -1) : -1;

        if ($status === 'Judged') {
            // Judged状态：按result_error映射，未知值默认8（No Comment）
            $resultId = isset($error_map[$result_error]) ? $error_map[$result_error] : 8;
            if($resultId === 8 && $score !== -1) {
                if($score >= 100) $resultId = 0;
                else $resultId = 1;
            }
        } else {
            // 其他状态 → -1
            $resultId = -1;
        }

        // 7. 将处理后的记录加入返回数组
        $response['submit'][] = [
            'submitId' => $submitId,
            'teamId' => $teamId,
            'alphabetId' => $alphabetId,
            'subTime' => $subTime,
            'resultId' => $resultId
        ];
    }
} catch (Exception $e) {
    // 异常处理：捕获错误信息（可选，上线后可注释）
    $response['error'] = $e->getMessage();
    $response['submit'] = [];
}

// ************************ 返回JSON数据 ************************
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>

