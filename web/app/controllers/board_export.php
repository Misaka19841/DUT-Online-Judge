<?php
/**
 * ScrollBoard Final Results - 最简单测试版本
 */

UOJContest::init(UOJRequest::get('id')) || UOJResponse::page404();
isSuperUser(Auth::user()) || UOJResponse::page403();
$contest = UOJContest::info();

$contest_name = $contest['name'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $contest_name ?> - 滚榜结果</title>
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { background: #337ab7; color: white; }
        .accepted { background: #d4edda; }
        .first { background: #f0ad4e; color: white; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($contest_name) ?> - 最终排名</h1>
    <div id="result">
        <p>加载中...</p>
    </div>

    <script src="/js/jquery.min.js"></script>
    <script>
    // 原版滚榜核心逻辑
    function StringToDate(s) {
        var d = new Date();
        d.setYear(parseInt(s.substr(0,4)));
        d.setMonth(parseInt(s.substr(5,2))-1);
        d.setDate(parseInt(s.substr(8,2)));
        d.setHours(parseInt(s.substr(11,2)));
        d.setMinutes(parseInt(s.substr(14,2)));
        d.setSeconds(parseInt(s.substr(17,2)));
        return d;
    }

    function Submit(submitId, teamId, alphabetId, subTime, resultId) {
        this.submitId = submitId;
        this.teamId = teamId;
        this.alphabetId = alphabetId;
        this.subTime = subTime;
        this.resultId = resultId;
        this.isFirstSolved = false;
    }

    function Team(teamId, teamName, teamSchool, teamMember) {
        this.teamId = teamId;
        this.teamName = teamName;
        this.teamSchool = teamSchool;
        this.teamMember = teamMember;
        this.solved = 0;
        this.penalty = 0;
        this.problems = {};
        this.submits = [];
    }

    Team.prototype.init = function(startTime, freezeTime) {
        this.submits.sort(function(a,b){return a.submitId - b.submitId;});
        
        for(var i=0;i<this.submits.length;i++) {
            var sub = this.submits[i];
            var p = this.problems[sub.alphabetId];
            if(!p) p = {alphabetId:sub.alphabetId, accepted:false, submitCount:0, realCount:0, acceptedTime:0, lastTime:0, unknown:false};
            
            if(p.accepted) continue;
            if(sub.resultId == 9) continue;
            if(sub.subTime > freezeTime) p.unknown = true;
            
            p.submitCount++;
            if(!p.accepted) p.realCount++;
            p.accepted = (sub.resultId == 0);
            p.lastTime = Math.max(p.lastTime, sub.subTime - startTime);
            
            if(p.accepted) {
                p.acceptedTime = sub.subTime - startTime;
                if(p.acceptedTime < freezeTime - startTime) {
                    p.submitCount = p.realCount;
                    this.solved++;
                    this.penalty += p.acceptedTime + (p.submitCount-1)*20*60*1000;
                }
                p.isFirstSolved = sub.isFirstSolved;
            }
            this.problems[sub.alphabetId] = p;
        }
    };

    Team.prototype.finalize = function() {
        for(var id in this.problems) {
            var p = this.problems[id];
            if(p.unknown && p.accepted) {
                p.unknown = false;
                p.submitCount = p.realCount;
                this.solved++;
                this.penalty += p.acceptedTime + (p.submitCount-1)*20*60*1000;
            }
        }
    };

    // 获取数据
    var startTime = StringToDate("<?= $contest['start_time_str'] ?>");
    var endTime = new Date(startTime.getTime() + <?= $contest['last_min'] ?> * 60 * 1000);
    var freezeTime = new Date(endTime.getTime() - (endTime - startTime) / 5);
    
    var problemCount = <?= count($contest_data["problems"] ?? []) ?>;
    var problemList = [];
    for(var i=0;i<problemCount;i++) problemList.push(String.fromCharCode(65+i));

    $.when(
        $.getJSON("all_submission_info"),
        $.getJSON("all_team_info")
    ).done(function(subRes, teamRes) {
        var submits = subRes[0].submit;
        var teams = teamRes[0].teamlist;
        
        // 计算一血
        var firstSolved = {};
        for(var i in submits) {
            var s = submits[i];
            if(s.resultId == 0) {
                if(!firstSolved[s.alphabetId] || s.submitId < firstSolved[s.alphabetId]) {
                    firstSolved[s.alphabetId] = s.submitId;
                }
            }
        }
        
        // 构建队伍对象
        var teamMap = {};
        for(var i in teams) {
            var t = teams[i];
            teamMap[t.teamId] = new Team(t.teamId, t.teamName, t.teamSchool, t.teamMember);
        }
        
        // 分配提交
        for(var i in submits) {
            var s = submits[i];
            if(teamMap[s.teamId]) {
                var sub = new Submit(s.submitId, s.teamId, s.alphabetId, StringToDate(s.subTime), s.resultId);
                if(sub.resultId == 0 && firstSolved[s.alphabetId] == sub.submitId) {
                    sub.isFirstSolved = true;
                }
                teamMap[s.teamId].submits.push(sub);
            }
        }
        
        // 初始化并完成滚榜
        var teamList = [];
        for(var id in teamMap) {
            var t = teamMap[id];
            t.init(startTime, freezeTime);
            t.finalize();
            teamList.push(t);
        }
        
        // 排序
        teamList.sort(function(a,b){
            if(a.solved != b.solved) return b.solved - a.solved;
            return a.penalty - b.penalty;
        });
        
        // 生成表格
        var html = '<table>';
        html += '<tr><th>排名</th><th>队伍</th><th>学校</th><th>队员</th><th>解题数</th><th>罚时</th>';
        for(var i=0;i<problemList.length;i++) html += '<th>'+problemList[i]+'</th>';
        html += '</tr>';
        
        var rank = 1;
        for(var i=0;i<teamList.length;i++) {
            var t = teamList[i];
            if(i>0 && (t.solved != teamList[i-1].solved || t.penalty != teamList[i-1].penalty)) rank = i+1;
            
            html += '<tr>';
            html += '<td>'+rank+'</td>';
            html += '<td>'+escapeHtml(t.teamName)+'</td>';
            html += '<td>'+escapeHtml(t.teamSchool)+'</td>';
            html += '<td>'+escapeHtml(t.teamMember)+'</td>';
            html += '<td>'+t.solved+'</td>';
            html += '<td>'+Math.floor(t.penalty/1000/60)+'</td>';
            
            for(var j=0;j<problemList.length;j++) {
                var p = t.problems[problemList[j]];
                if(p && p.accepted) {
                    var cls = p.isFirstSolved ? 'first' : 'accepted';
                    html += '<td class="'+cls+'">'+p.submitCount+'/'+Math.floor(p.acceptedTime/1000/60)+'</td>';
                } else if(p && p.submitCount>0) {
                    html += '<td class="rejected">'+p.submitCount+'/'+Math.floor(p.lastTime/1000/60)+'</td>';
                } else {
                    html += '<td>-</td>';
                }
            }
            html += '</tr>';
        }
        html += '</table>';
        
        function escapeHtml(str) {
            if(!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if(m === '&') return '&amp;';
                if(m === '<') return '&lt;';
                if(m === '>') return '&gt;';
                return m;
            });
        }
        
        $('#result').html(html);
        
    }).fail(function(e) {
        $('#result').html('<p style="color:red">获取数据失败: ' + JSON.stringify(e) + '</p>');
    });
    </script>
</body>
</html>
