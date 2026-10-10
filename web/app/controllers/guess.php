<?php

$secret = "password";



if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $input = strtolower(trim($_POST['cmd']));

    $secretLower = strtolower($secret);

    

    $lenG = strlen($input);

    $lenS = strlen($secretLower);

    

    // 长度判断

    if ($lenG < $lenS) {

        $result = "L";

    } elseif ($lenG > $lenS) {

        $result = "G";

    } else {

        $result = "E";

    }

    

    // 对用户输入的每个字符进行匹配

    $secretArr = str_split($secretLower);

    $guessArr = str_split($input);

    $matchResult = array_fill(0, $lenG, '');

    

    // 第一遍：标记正确位置

    for ($i = 0; $i < $lenG; $i++) {

        if ($i < $lenS && $guessArr[$i] == $secretArr[$i]) {

            $matchResult[$i] = 'C';

            $secretArr[$i] = null;

            $guessArr[$i] = null;

        }

    }

    

    // 第二遍：标记存在但位置错误

    for ($i = 0; $i < $lenG; $i++) {

        if ($matchResult[$i] != '') continue;

        if ($i >= $lenS) {

            $matchResult[$i] = 'W';

            continue;

        }

        $pos = array_search($guessArr[$i], $secretArr);

        if ($pos !== false) {

            $matchResult[$i] = 'P';

            $secretArr[$pos] = null;

        } else {

            $matchResult[$i] = 'W';

        }

    }

    

    echo $result . implode('', $matchResult);

    exit;

}

?>

<!DOCTYPE html>

<html>

<head>

    <title>DOS Terminal</title>

    <style>

        body {

            background: #000;

            margin: 0;

            padding: 20px;

            font-family: 'Courier New', monospace;

        }

        .terminal {

            background: #000;

            border: 2px solid #0f0;

            border-radius: 8px;

        }

        .terminal-header {

            background: #0f0;

            padding: 5px 10px;

            color: #000;

            font-weight: bold;

            font-size: 12px;

        }

        .terminal-body {

            height: 500px;

            overflow-y: auto;

            padding: 15px;

            color: #0f0;

            font-size: 14px;

        }

        .input-line {

            display: flex;

            align-items: center;

            padding: 10px 15px 15px 15px;

            border-top: 1px solid #0f0;

        }

        .prompt {

            color: #0f0;

            margin-right: 10px;

        }

        #cmd {

            background: transparent;

            border: none;

            color: #0f0;

            font-family: 'Courier New', monospace;

            font-size: 14px;

            flex: 1;

            outline: none;

        }

        .output-line {

            margin: 2px 0;

        }

        .result-line {

            color: #ff0;

            margin-left: 20px;

            margin-bottom: 8px;

        }

        ::-webkit-scrollbar {

            width: 8px;

            background: #000;

        }

        ::-webkit-scrollbar-thumb {

            background: #0f0;

        }

    </style>

</head>

<body>

<div class="terminal">

    <div class="terminal-header">

        <span>DOS Terminal</span>

    </div>

    <div class="terminal-body" id="output">

        <div class="output-line">Terminal Ready</div>

        <div class="output-line">---</div>

    </div>

    <div class="input-line">

        <span class="prompt">User&gt;</span>

        <input type="text" id="cmd" autocomplete="off">

    </div>

</div>



<script>

var output = document.getElementById('output');

var cmd = document.getElementById('cmd');



function addLine(text, isResult) {

    var div = document.createElement('div');

    div.className = isResult ? 'result-line' : 'output-line';

    div.innerHTML = text;

    output.appendChild(div);

    output.scrollTop = output.scrollHeight;

}



function send() {

    var val = cmd.value.trim();

    if (val === '') return;

    

    addLine('User&gt; ' + val, false);

    cmd.value = '';

    

    var xhr = new XMLHttpRequest();

    xhr.open('POST', '', true);

    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onload = function() {

        addLine(xhr.responseText, true);

        cmd.focus();

    };

    xhr.send('cmd=' + encodeURIComponent(val));

}



cmd.addEventListener('keypress', function(e) {

    if (e.key === 'Enter') {

        e.preventDefault();

        send();

    }

});



cmd.focus();

</script>

</body>

</html>
