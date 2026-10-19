<?php
$regionalContests = $regionalContests ?? [];
$regionalContestsBoardId = $regionalContestsBoardId ?? 'regional-contests-board';
$regionalContestsTitle = $regionalContestsTitle ?? '区域赛';
$regionalContestsSubtitle = $regionalContestsSubtitle ?? '';
$regionalContestsEmptyMessage = $regionalContestsEmptyMessage ?? '暂无区域赛数据。';
$regionalContestsMoreHref = $regionalContestsMoreHref ?? null;
$regionalContestsMoreText = $regionalContestsMoreText ?? '查看完整时间线';
$regionalContestsBoardHeight = $regionalContestsBoardHeight ?? RegionalContest::recommendBoardHeight($regionalContests);
$regionalContestsPayload = RegionalContest::buildClientPayload($regionalContests, $regionalContestsBoardHeight);
$regionalContestsJson = json_encode($regionalContestsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$regionalContestsMapUrl = HTML::url('/data/china-map.geojson');
?>
<style>
.regional-contests-panel {
    margin-top: 22px;
}
.regional-contests-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}
.regional-contests-header h3 {
    margin: 0;
    font-weight: 700;
}
.regional-contests-subtitle {
    color: #666;
    margin-top: 6px;
}
.regional-contests-scroll {
    overflow-x: auto;
    padding-bottom: 6px;
}
.regional-contests-board {
    position: relative;
    min-width: 980px;
    height: <?= (int)$regionalContestsBoardHeight ?>px;
    border-radius: 18px;
    overflow: hidden;
    background:
        radial-gradient(circle at top, rgba(217, 83, 79, 0.09), transparent 42%),
        linear-gradient(180deg, #fafbfd 0%, #f4f7fb 100%);
    border: 1px solid #e6ebf2;
}
.regional-contests-board-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}
.regional-contests-card {
    position: absolute;
    width: 250px;
    max-height: 170px;
    overflow: auto;
    padding: 12px 14px;
    border: 1px solid #dfe7f0;
    border-left-width: 4px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 10px 24px rgba(29, 52, 84, 0.08);
}
.regional-contests-card-left {
    left: 22px;
}
.regional-contests-card-right {
    right: 22px;
}
.regional-contests-card-title {
    font-size: 16px;
    font-weight: 700;
    line-height: 1.35;
    margin-bottom: 4px;
}
.regional-contests-card-city {
    color: #3f5873;
    font-weight: 600;
    margin-bottom: 6px;
}
.regional-contests-card-meta,
.regional-contests-card-note {
    color: #5f6f82;
    line-height: 1.5;
    font-size: 13px;
}
.regional-contests-card-meta + .regional-contests-card-note {
    margin-top: 6px;
}
.regional-contests-card-link {
    margin-top: 8px;
    font-size: 13px;
}
.regional-contests-empty {
    padding: 48px 20px;
    text-align: center;
    color: #6b7c90;
    font-size: 15px;
}
.regional-contests-legend {
    margin-top: 12px;
    color: #6d7b8a;
    font-size: 13px;
}
</style>

<div class="regional-contests-panel">
    <div class="regional-contests-header">
        <div>
            <h3><?= HTML::escape($regionalContestsTitle) ?></h3>
            <?php if ($regionalContestsSubtitle !== ''): ?>
                <div class="regional-contests-subtitle"><?= HTML::escape($regionalContestsSubtitle) ?></div>
            <?php endif ?>
        </div>
        <?php if ($regionalContestsMoreHref): ?>
            <a class="btn btn-default btn-sm" href="<?= HTML::escape($regionalContestsMoreHref) ?>"><?= HTML::escape($regionalContestsMoreText) ?></a>
        <?php endif ?>
    </div>

    <?php if (empty($regionalContests)): ?>
        <div class="regional-contests-empty"><?= HTML::escape($regionalContestsEmptyMessage) ?></div>
    <?php else: ?>
        <div class="regional-contests-scroll">
            <div class="regional-contests-board" id="<?= HTML::escape($regionalContestsBoardId) ?>" data-events='<?= HTML::escape($regionalContestsJson) ?>' data-map-url="<?= HTML::escape($regionalContestsMapUrl) ?>">
                <svg class="regional-contests-board-svg" viewBox="0 0 1000 <?= (int)$regionalContestsBoardHeight ?>" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="<?= HTML::escape($regionalContestsBoardId) ?>-map-fill" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#EAF2FF"></stop>
                            <stop offset="100%" stop-color="#D8E6FF"></stop>
                        </linearGradient>
                    </defs>
                    <rect x="0" y="0" width="1000" height="<?= (int)$regionalContestsBoardHeight ?>" fill="transparent"></rect>
                    <line x1="184" y1="60" x2="184" y2="<?= (int)$regionalContestsBoardHeight - 60 ?>" stroke="#B6C5D8" stroke-width="2" stroke-dasharray="4 8"></line>
                    <line x1="816" y1="60" x2="816" y2="<?= (int)$regionalContestsBoardHeight - 60 ?>" stroke="#B6C5D8" stroke-width="2" stroke-dasharray="4 8"></line>
                    <g class="regional-contests-map-layer"></g>
                    <g class="regional-contests-connector-layer"></g>
                    <g class="regional-contests-axis-layer">
                        <?php foreach ($regionalContests as $event): ?>
                            <?php
                            $timelineY = round(60 + ($regionalContestsBoardHeight - 120) * $event['position_pct'] / 100, 2);
                            $axisX = $event['side'] === 'right' ? 816 : 184;
                            $textX = $event['side'] === 'right' ? 832 : 168;
                            $textAnchor = $event['side'] === 'right' ? 'start' : 'end';
                            ?>
                            <circle cx="<?= $axisX ?>" cy="<?= $timelineY ?>" r="6" fill="<?= HTML::escape($event['accent_color']) ?>" stroke="#fff" stroke-width="2"></circle>
                            <text x="<?= $textX ?>" y="<?= $timelineY - 12 ?>" fill="#4E6178" font-size="13" text-anchor="<?= $textAnchor ?>"><?= HTML::escape($event['display_time']) ?></text>
                        <?php endforeach ?>
                    </g>
                </svg>

                <?php foreach ($regionalContests as $event): ?>
                    <?php
                    $top = max(24, min($regionalContestsBoardHeight - 164, round(60 + ($regionalContestsBoardHeight - 120) * $event['position_pct'] / 100 + 18)));
                    $cardClass = $event['side'] === 'right' ? 'regional-contests-card regional-contests-card-right' : 'regional-contests-card regional-contests-card-left';
                    $location = $event['city_name'];
                    if ($event['province_name'] !== '') {
                        $location .= ' / ' . $event['province_name'];
                    }
                    if ($event['venue'] !== '') {
                        $location .= ' / ' . $event['venue'];
                    }
                    ?>
                    <div class="<?= $cardClass ?>" style="top: <?= $top ?>px; border-left-color: <?= HTML::escape($event['accent_color']) ?>;">
                        <div class="regional-contests-card-title"><?= HTML::escape($event['title']) ?></div>
                        <div class="regional-contests-card-city"><?= HTML::escape($location) ?></div>
                        <?php if ($event['teams_summary'] !== ''): ?>
                            <div class="regional-contests-card-meta"><?= nl2br(HTML::escape($event['teams_summary'])) ?></div>
                        <?php endif ?>
                        <?php if ($event['note'] !== ''): ?>
                            <div class="regional-contests-card-note"><?= nl2br(HTML::escape($event['note'])) ?></div>
                        <?php endif ?>
                        <?php if ($event['contest_url'] !== ''): ?>
                            <div class="regional-contests-card-link"><a href="<?= HTML::escape($event['contest_url']) ?>" target="_blank">查看详情</a></div>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <div class="regional-contests-legend">中间为中国地图投影，城市位置按经纬度标注；左右时间轴分别承接赛事时间点与信息牌。</div>
        <script>
        (function() {
            var board = document.getElementById(<?= json_encode($regionalContestsBoardId) ?>);
            if (!board) {
                return;
            }

            var mapLayer = board.querySelector('.regional-contests-map-layer');
            var connectorLayer = board.querySelector('.regional-contests-connector-layer');
            var events = [];
            try {
                events = JSON.parse(board.getAttribute('data-events') || '[]');
            } catch (e) {
                return;
            }

            function decodePolygon(coordinate, encodeOffsets) {
                var result = [];
                var prevX = encodeOffsets[0];
                var prevY = encodeOffsets[1];

                for (var i = 0; i < coordinate.length; i += 2) {
                    var x = coordinate.charCodeAt(i) - 64;
                    var y = coordinate.charCodeAt(i + 1) - 64;
                    x = (x >> 1) ^ (-(x & 1));
                    y = (y >> 1) ^ (-(y & 1));
                    x += prevX;
                    y += prevY;
                    prevX = x;
                    prevY = y;
                    result.push([x / 1024, y / 1024]);
                }
                return result;
            }

            function decodeGeoJSON(json) {
                if (!json || !json.UTF8Encoding) {
                    return json;
                }

                json.features.forEach(function(feature) {
                    var geometry = feature.geometry;
                    var coordinates = geometry.coordinates;
                    var encodeOffsets = geometry.encodeOffsets;

                    if (geometry.type === 'Polygon') {
                        for (var i = 0; i < coordinates.length; i++) {
                            coordinates[i] = decodePolygon(coordinates[i], encodeOffsets[i]);
                        }
                    } else if (geometry.type === 'MultiPolygon') {
                        for (var p = 0; p < coordinates.length; p++) {
                            for (var r = 0; r < coordinates[p].length; r++) {
                                coordinates[p][r] = decodePolygon(coordinates[p][r], encodeOffsets[p][r]);
                            }
                        }
                    }
                });

                json.UTF8Encoding = false;
                return json;
            }

            function eachRing(geometry, callback) {
                if (geometry.type === 'Polygon') {
                    geometry.coordinates.forEach(callback);
                    return;
                }
                if (geometry.type === 'MultiPolygon') {
                    geometry.coordinates.forEach(function(polygon) {
                        polygon.forEach(callback);
                    });
                }
            }

            fetch(board.getAttribute('data-map-url'))
                .then(function(response) {
                    return response.json();
                })
                .then(function(rawGeoJSON) {
                    var geoJSON = decodeGeoJSON(rawGeoJSON);
                    renderBoard(geoJSON);
                })
                .catch(function() {});

            function renderBoard(geoJSON) {
                if (!geoJSON || !Array.isArray(geoJSON.features)) {
                    return;
                }

                var mapRect = {
                    x: 312,
                    y: 70,
                    width: 376,
                    height: <?= (int)$regionalContestsBoardHeight - 140 ?>
                };

                var bounds = {
                    minLng: Infinity,
                    maxLng: -Infinity,
                    minLat: Infinity,
                    maxLat: -Infinity
                };

                geoJSON.features.forEach(function(feature) {
                    eachRing(feature.geometry, function(ring) {
                        ring.forEach(function(point) {
                            bounds.minLng = Math.min(bounds.minLng, point[0]);
                            bounds.maxLng = Math.max(bounds.maxLng, point[0]);
                            bounds.minLat = Math.min(bounds.minLat, point[1]);
                            bounds.maxLat = Math.max(bounds.maxLat, point[1]);
                        });
                    });
                });

                var scale = Math.min(
                    mapRect.width / Math.max(1e-6, bounds.maxLng - bounds.minLng),
                    mapRect.height / Math.max(1e-6, bounds.maxLat - bounds.minLat)
                );
                var offsetX = mapRect.x + (mapRect.width - (bounds.maxLng - bounds.minLng) * scale) / 2;
                var offsetY = mapRect.y + (mapRect.height - (bounds.maxLat - bounds.minLat) * scale) / 2;

                function project(lng, lat) {
                    return {
                        x: offsetX + (lng - bounds.minLng) * scale,
                        y: offsetY + (bounds.maxLat - lat) * scale
                    };
                }

                geoJSON.features.forEach(function(feature) {
                    eachRing(feature.geometry, function(ring) {
                        if (!ring.length) {
                            return;
                        }
                        var d = '';
                        ring.forEach(function(point, index) {
                            var projected = project(point[0], point[1]);
                            d += (index === 0 ? 'M' : 'L') + projected.x.toFixed(2) + ' ' + projected.y.toFixed(2) + ' ';
                        });
                        d += 'Z';
                        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                        path.setAttribute('d', d);
                        path.setAttribute('fill', 'url(#' + <?= json_encode($regionalContestsBoardId) ?> + '-map-fill)');
                        path.setAttribute('stroke', '#95ADD1');
                        path.setAttribute('stroke-width', '1.2');
                        mapLayer.appendChild(path);
                    });
                });

                var usedLabelY = [];
                events.forEach(function(event, index) {
                    var marker = project(event.longitude, event.latitude);
                    var axisX = event.side === 'right' ? 816 : 184;
                    var midX = event.side === 'right' ? 718 : 282;
                    var axisY = event.timeline_y;

                    var connector = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    connector.setAttribute(
                        'd',
                        'M' + marker.x.toFixed(2) + ' ' + marker.y.toFixed(2)
                        + ' C ' + midX + ' ' + marker.y.toFixed(2)
                        + ', ' + midX + ' ' + axisY.toFixed(2)
                        + ', ' + axisX + ' ' + axisY.toFixed(2)
                    );
                    connector.setAttribute('fill', 'none');
                    connector.setAttribute('stroke', event.accent_color);
                    connector.setAttribute('stroke-width', '2.2');
                    connector.setAttribute('stroke-linecap', 'round');
                    connector.setAttribute('opacity', '0.9');
                    connectorLayer.appendChild(connector);

                    var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    dot.setAttribute('cx', marker.x.toFixed(2));
                    dot.setAttribute('cy', marker.y.toFixed(2));
                    dot.setAttribute('r', '5.5');
                    dot.setAttribute('fill', event.accent_color);
                    dot.setAttribute('stroke', '#fff');
                    dot.setAttribute('stroke-width', '2');
                    connectorLayer.appendChild(dot);

                    var labelY = marker.y - 10;
                    for (var i = 0; i < usedLabelY.length; i++) {
                        if (Math.abs(labelY - usedLabelY[i]) < 16) {
                            labelY = usedLabelY[i] + 16;
                        }
                    }
                    usedLabelY.push(labelY);

                    var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', (marker.x + (event.side === 'right' ? 10 : -10)).toFixed(2));
                    text.setAttribute('y', labelY.toFixed(2));
                    text.setAttribute('fill', '#30465F');
                    text.setAttribute('font-size', '13');
                    text.setAttribute('font-weight', '600');
                    text.setAttribute('text-anchor', event.side === 'right' ? 'start' : 'end');
                    text.textContent = event.city_name;
                    connectorLayer.appendChild(text);
                });
            }
        })();
        </script>
    <?php endif ?>
</div>
