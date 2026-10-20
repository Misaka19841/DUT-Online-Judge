<?php

function user_submissions_api_echo_json($payload, int $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$username = UOJRequest::get('username');
if (!validateUsername($username)) {
    user_submissions_api_echo_json([
        'error' => 'invalid username'
    ], 404);
}

$user = UOJUser::query($username);
if (!$user) {
    user_submissions_api_echo_json([
        'error' => 'user not found',
        'username' => $username
    ], 404);
}

$viewer = Auth::user();
$rows = DB::selectAll([
    "select",
    DB::fields([
        'submissions.id',
        'submissions.problem_id',
        'submissions.contest_id',
        'submissions.submitter',
        'submissions.submit_time',
        'submissions.judge_time',
        'submissions.status',
        'submissions.status_details',
        'submissions.result_error',
        'submissions.used_time',
        'submissions.used_memory',
        'submissions.language',
        'submissions.tot_size',
        'submissions.hide_score_to_others',
        'submissions.score',
        'submissions.hidden_score'
    ]),
    "from submissions",
    "where", [
        ['submissions.submitter', '=', $username],
        UOJSubmission::sqlForUserCanView($viewer)
    ],
    "order by submissions.id desc"
]);

$problem_ids = [];
foreach ($rows as $row) {
    if (isset($row['problem_id'])) {
        $problem_ids[(int)$row['problem_id']] = true;
    }
}

$problem_map = [];
$problem_tags_map = [];

if ($problem_ids) {
    $problem_id_list = array_keys($problem_ids);

    $problems = DB::selectAll([
        "select",
        DB::fields([
            'problems.id',
            'problems.title'
        ]),
        "from problems",
        "where", [
            ['problems.id', 'in', DB::rawtuple($problem_id_list)],
            UOJProblem::sqlForUserCanView($viewer)
        ]
    ]);
    foreach ($problems as $problem) {
        $problem_map[(int)$problem['id']] = $problem['title'];
    }

    $problem_tags = DB::selectAll([
        "select",
        DB::fields([
            'problem_id',
            'tag'
        ]),
        "from problems_tags",
        "where", [
            ['problem_id', 'in', DB::rawtuple($problem_id_list)]
        ],
        "order by id"
    ]);
    foreach ($problem_tags as $row) {
        $problem_id = (int)$row['problem_id'];
        if (!isset($problem_tags_map[$problem_id])) {
            $problem_tags_map[$problem_id] = [];
        }
        $problem_tags_map[$problem_id][] = $row['tag'];
    }
}

$data = [];
foreach ($rows as $row) {
    $public_status = explode(', ', $row['status'])[0];
    $actual_score = $row['hide_score_to_others'] ? $row['hidden_score'] : $row['score'];
    $can_see_score = !$row['hide_score_to_others'] || ($viewer && $viewer['username'] === $username);
    $problem_id = isset($row['problem_id']) ? (int)$row['problem_id'] : null;

    $data[] = [
        'id' => (int)$row['id'],
        'uri' => '/submission/' . $row['id'],
        'submitter' => $row['submitter'],
        'problem_id' => $problem_id,
        'problem_title' => $problem_id !== null && isset($problem_map[$problem_id]) ? $problem_map[$problem_id] : null,
        'problem_tags' => $problem_id !== null && isset($problem_tags_map[$problem_id]) ? $problem_tags_map[$problem_id] : [],
        'contest_id' => isset($row['contest_id']) ? (int)$row['contest_id'] : null,
        'status' => $row['status'],
        'public_status' => $public_status,
        'status_details' => $row['status_details'],
        'result_error' => $row['result_error'],
        'score' => $can_see_score && $actual_score !== null ? (float)$actual_score : null,
        'score_hidden' => (bool)$row['hide_score_to_others'] && !$can_see_score,
        'used_time' => isset($row['used_time']) ? (int)$row['used_time'] : null,
        'used_memory' => isset($row['used_memory']) ? (int)$row['used_memory'] : null,
        'language' => $row['language'],
        'tot_size' => isset($row['tot_size']) ? (int)$row['tot_size'] : null,
        'submit_time' => $row['submit_time'],
        'judge_time' => $row['judge_time']
    ];
}

user_submissions_api_echo_json([
    'username' => $user['username'],
    'count' => count($data),
    'submissions' => $data
]);
