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
$regionalContestsLeftEvents = [];
$regionalContestsRightEvents = [];
foreach ($regionalContests as $event) {
    if (($event['side'] ?? 'left') === 'right') {
        $regionalContestsRightEvents[] = $event;
    } else {
        $regionalContestsLeftEvents[] = $event;
    }
}
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
.regional-contests-stage {
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    padding: 0 0 10px;
}
.regional-contests-board {
    position: relative;
    width: 100vw;
    height: <?= (int)$regionalContestsBoardHeight ?>px;
    min-height: 760px;
    overflow: hidden;
    background: transparent;
}
.regional-contests-map-wrap {
    position: absolute;
    z-index: 1;
    pointer-events: none;
}
.regional-contests-map-svg,
.regional-contests-connector-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: visible;
}
.regional-contests-connector-svg {
    z-index: 2;
    pointer-events: none;
}
.regional-contests-side {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 430px;
    z-index: 3;
}
.regional-contests-side-left {
    left: 0;
}
.regional-contests-side-right {
    right: 0;
}
.regional-contests-axis-line {
    position: absolute;
    top: 12px;
    bottom: 12px;
    border-left: 2px dashed #B6C5D8;
}
.regional-contests-side-left .regional-contests-axis-line {
    left: 24px;
}
.regional-contests-side-right .regional-contests-axis-line {
    right: 24px;
}
.regional-contests-axis-point {
    position: absolute;
    width: 0;
    height: 0;
    transform: translateY(-50%);
}
.regional-contests-side-left .regional-contests-axis-point {
    left: 24px;
}
.regional-contests-side-right .regional-contests-axis-point {
    right: 24px;
}
.regional-contests-axis-dot {
    position: absolute;
    top: 0;
    left: 0;
    width: 12px;
    height: 12px;
    border: 2px solid #fff;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    box-shadow: 0 2px 6px rgba(41, 60, 84, 0.12);
}
.regional-contests-side-right .regional-contests-axis-dot {
    left: auto;
    right: 0;
    transform: translate(50%, -50%);
}
.regional-contests-axis-time {
    position: absolute;
    top: -11px;
    color: #4E6178;
    font-size: 13px;
    line-height: 1.2;
    white-space: nowrap;
}
.regional-contests-side-left .regional-contests-axis-time {
    left: 16px;
}
.regional-contests-side-right .regional-contests-axis-time {
    right: 16px;
    text-align: right;
}
.regional-contests-card {
    position: absolute;
    display: block;
    width: 340px;
    max-height: 220px;
    overflow: auto;
    padding: 12px 14px;
    border: 1px solid #dfe7f0;
    border-left-width: 4px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.98);
    box-shadow: 0 14px 30px rgba(29, 52, 84, 0.10);
    pointer-events: auto;
    color: inherit;
    text-decoration: none;
}
.regional-contests-card-left {
    left: 58px;
}
.regional-contests-card-right {
    left: auto;
    right: 58px;
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
.regional-contests-card[data-href] {
    cursor: pointer;
}
.regional-contests-card[data-href]:hover,
.regional-contests-card[data-href]:focus {
    text-decoration: none;
    box-shadow: 0 16px 36px rgba(29, 52, 84, 0.16);
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
        <div class="regional-contests-stage">
            <div class="regional-contests-board" id="<?= HTML::escape($regionalContestsBoardId) ?>" data-events='<?= HTML::escape($regionalContestsJson) ?>' data-map-url="<?= HTML::escape($regionalContestsMapUrl) ?>" data-board-height="<?= (int)$regionalContestsBoardHeight ?>">
                <div class="regional-contests-map-wrap">
                    <svg class="regional-contests-map-svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="<?= HTML::escape($regionalContestsBoardId) ?>-map-fill" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#EAF2FF"></stop>
                                <stop offset="100%" stop-color="#D8E6FF"></stop>
                            </linearGradient>
                        </defs>
                        <g class="regional-contests-map-layer"></g>
                    </svg>
                </div>
                <svg class="regional-contests-connector-svg" aria-hidden="true">
                    <g class="regional-contests-connector-layer"></g>
                </svg>

                <div class="regional-contests-side regional-contests-side-left">
                    <div class="regional-contests-axis-line"></div>
                    <?php foreach ($regionalContestsLeftEvents as $event): ?>
                        <?php
                        $axisTop = round(20 + ($regionalContestsBoardHeight - 40) * $event['position_pct'] / 100);
                        $cardTop = max(8, min($regionalContestsBoardHeight - 210, $axisTop + 14));
                        $location = $event['city_name'];
                        if ($event['venue'] !== '') {
                            $location .= ' / ' . $event['venue'];
                        }
                        $cardColor = $event['side'] === 'right' ? '#D9534F' : '#337AB7';
                        ?>
                        <div class="regional-contests-axis-point" data-event-id="<?= (int)$event['id'] ?>" style="top: <?= $axisTop ?>px;">
                            <span class="regional-contests-axis-dot" style="background: <?= HTML::escape($cardColor) ?>;"></span>
                            <span class="regional-contests-axis-time"><?= HTML::escape($event['display_time']) ?></span>
                        </div>
                        <div class="regional-contests-card regional-contests-card-left" data-event-id="<?= (int)$event['id'] ?>"<?= $event['contest_url'] !== '' ? ' data-href="' . HTML::escape($event['contest_url']) . '"' : '' ?> style="top: <?= $cardTop ?>px; border-left-color: <?= HTML::escape($cardColor) ?>;">
                            <div class="regional-contests-card-title"><?= HTML::escape($event['title']) ?></div>
                            <div class="regional-contests-card-city"><?= HTML::escape($location) ?></div>
                            <?php if ($event['teams_summary'] !== ''): ?>
                                <div class="regional-contests-card-meta"><?= nl2br(HTML::escape($event['teams_summary'])) ?></div>
                            <?php endif ?>
                            <?php if ($event['note'] !== ''): ?>
                                <div class="regional-contests-card-note"><?= nl2br(HTML::escape($event['note'])) ?></div>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>

                <div class="regional-contests-side regional-contests-side-right">
                    <div class="regional-contests-axis-line"></div>
                    <?php foreach ($regionalContestsRightEvents as $event): ?>
                        <?php
                        $axisTop = round(20 + ($regionalContestsBoardHeight - 40) * $event['position_pct'] / 100);
                        $cardTop = max(8, min($regionalContestsBoardHeight - 210, $axisTop + 14));
                        $location = $event['city_name'];
                        if ($event['venue'] !== '') {
                            $location .= ' / ' . $event['venue'];
                        }
                        $cardColor = $event['side'] === 'right' ? '#D9534F' : '#337AB7';
                        ?>
                        <div class="regional-contests-axis-point" data-event-id="<?= (int)$event['id'] ?>" style="top: <?= $axisTop ?>px;">
                            <span class="regional-contests-axis-dot" style="background: <?= HTML::escape($cardColor) ?>;"></span>
                            <span class="regional-contests-axis-time"><?= HTML::escape($event['display_time']) ?></span>
                        </div>
                        <div class="regional-contests-card regional-contests-card-right" data-event-id="<?= (int)$event['id'] ?>"<?= $event['contest_url'] !== '' ? ' data-href="' . HTML::escape($event['contest_url']) . '"' : '' ?> style="top: <?= $cardTop ?>px; right: 58px; border-left-color: <?= HTML::escape($cardColor) ?>;">
                            <div class="regional-contests-card-title"><?= HTML::escape($event['title']) ?></div>
                            <div class="regional-contests-card-city"><?= HTML::escape($location) ?></div>
                            <?php if ($event['teams_summary'] !== ''): ?>
                                <div class="regional-contests-card-meta"><?= nl2br(HTML::escape($event['teams_summary'])) ?></div>
                            <?php endif ?>
                            <?php if ($event['note'] !== ''): ?>
                                <div class="regional-contests-card-note"><?= nl2br(HTML::escape($event['note'])) ?></div>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        <div class="regional-contests-legend">时间轴独立贴在页面左右边缘，中间地图单独绘制，连线只负责把两者接起来。</div>
        <script>
        (function() {
            var board = document.getElementById(<?= json_encode($regionalContestsBoardId) ?>);
            if (!board) {
                return;
            }

            var container = board.closest('.container.theme-showcase') || document.querySelector('.container.theme-showcase');
            var mapWrap = board.querySelector('.regional-contests-map-wrap');
            var mapSvg = board.querySelector('.regional-contests-map-svg');
            var mapLayer = board.querySelector('.regional-contests-map-layer');
            var connectorSvg = board.querySelector('.regional-contests-connector-svg');
            var connectorLayer = board.querySelector('.regional-contests-connector-layer');
            var clickableCards = Array.prototype.slice.call(board.querySelectorAll('.regional-contests-card[data-href]'));
            var events = [];
            try {
                events = JSON.parse(board.getAttribute('data-events') || '[]');
            } catch (e) {
                return;
            }

            clickableCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    var href = card.getAttribute('data-href');
                    if (href) {
                        window.open(href, '_blank');
                    }
                });
            });

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
                    window.addEventListener('resize', function() {
                        renderBoard(geoJSON);
                    });
                })
                .catch(function() {});

            function renderBoard(geoJSON) {
                if (!geoJSON || !Array.isArray(geoJSON.features)) {
                    return;
                }

                mapLayer.innerHTML = '';
                connectorLayer.innerHTML = '';

                var boardWidth = board.clientWidth;
                var boardHeight = board.clientHeight;
                var boardRect = board.getBoundingClientRect();
                var containerRect = container ? container.getBoundingClientRect() : boardRect;
                connectorSvg.setAttribute('viewBox', '0 0 ' + boardWidth + ' ' + boardHeight);

                var containerLeft = Math.max(16, containerRect.left - boardRect.left);
                var containerRight = Math.min(boardWidth - 16, containerRect.right - boardRect.left);
                var leftAxisX = 24;
                var rightAxisX = boardWidth - 24;

                var leftCardWidth = Math.min(390, Math.max(240, containerLeft - 88));
                var rightCardWidth = Math.min(390, Math.max(240, boardWidth - containerRight - 88));
                var leftCardLeft = Math.max(58, containerLeft - leftCardWidth - 24);

                Array.prototype.forEach.call(board.querySelectorAll('.regional-contests-card-left'), function(card) {
                    card.style.width = leftCardWidth + 'px';
                    card.style.left = leftCardLeft + 'px';
                    card.style.right = 'auto';
                });
                Array.prototype.forEach.call(board.querySelectorAll('.regional-contests-card-right'), function(card) {
                    card.style.width = rightCardWidth + 'px';
                    card.style.left = 'auto';
                    card.style.right = '58px';
                });

                var baseMapWidth = Math.min(1180, Math.max(840, (containerRight - containerLeft) * 0.95));
                var baseMapHeight = Math.max(420, boardHeight - 80);
                var mapWidth = baseMapWidth * 0.75;
                var mapHeight = baseMapHeight * 0.75;
                var mapLeft = containerLeft + ((containerRight - containerLeft) - mapWidth) / 2;
                var mapTop = (boardHeight - mapHeight) / 2;
                mapWrap.style.left = mapLeft + 'px';
                mapWrap.style.top = mapTop + 'px';
                mapWrap.style.width = mapWidth + 'px';
                mapWrap.style.height = mapHeight + 'px';
                mapSvg.setAttribute('viewBox', '0 0 ' + mapWidth + ' ' + mapHeight);
                var mapRect = {
                    width: mapWidth,
                    height: mapHeight
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

                var baseScale = Math.min(
                    mapRect.width / Math.max(1e-6, bounds.maxLng - bounds.minLng),
                    mapRect.height / Math.max(1e-6, (bounds.maxLat - bounds.minLat) * 1.1)
                );
                var scaleX = baseScale;
                var scaleY = baseScale * 1.1;
                var projectedWidth = (bounds.maxLng - bounds.minLng) * scaleX;
                var projectedHeight = (bounds.maxLat - bounds.minLat) * scaleY;
                var offsetX = (mapRect.width - projectedWidth) / 2;
                var offsetY = (mapRect.height - projectedHeight) / 2;

                function project(lng, lat) {
                    return {
                        x: offsetX + (lng - bounds.minLng) * scaleX,
                        y: offsetY + (bounds.maxLat - lat) * scaleY
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
                        path.setAttribute('stroke-width', '1.6');
                        path.setAttribute('opacity', '0.98');
                        mapLayer.appendChild(path);
                    });
                });

                events.forEach(function(event) {
                    var markerLocal = project(event.longitude, event.latitude);
                    var marker = {
                        x: mapLeft + markerLocal.x,
                        y: mapTop + markerLocal.y
                    };
                    var axisX = event.side === 'right' ? rightAxisX : leftAxisX;
                    var axisY = event.timeline_y;
                    var firstControlX = event.side === 'right' ? marker.x + 70 : marker.x - 70;
                    var secondControlX = event.side === 'right' ? axisX - 120 : axisX + 120;
                    var connectorColor = event.side === 'right' ? '#D9534F' : '#337AB7';

                    var connector = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    connector.setAttribute(
                        'd',
                        'M' + marker.x.toFixed(2) + ' ' + marker.y.toFixed(2)
                        + ' C ' + firstControlX.toFixed(2) + ' ' + marker.y.toFixed(2)
                        + ', ' + secondControlX.toFixed(2) + ' ' + axisY.toFixed(2)
                        + ', ' + axisX + ' ' + axisY.toFixed(2)
                    );
                    connector.setAttribute('fill', 'none');
                    connector.setAttribute('stroke', connectorColor);
                    connector.setAttribute('stroke-width', '2.2');
                    connector.setAttribute('stroke-linecap', 'round');
                    connector.setAttribute('opacity', '0.9');
                    connectorLayer.appendChild(connector);

                    var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    dot.setAttribute('cx', marker.x.toFixed(2));
                    dot.setAttribute('cy', marker.y.toFixed(2));
                    dot.setAttribute('r', '5.5');
                    dot.setAttribute('fill', connectorColor);
                    dot.setAttribute('stroke', '#fff');
                    dot.setAttribute('stroke-width', '2');
                    connectorLayer.appendChild(dot);
                });
            }
        })();
        </script>
    <?php endif ?>
</div>
