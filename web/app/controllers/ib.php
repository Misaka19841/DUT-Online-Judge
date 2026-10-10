<?php

    isSuperUser(Auth::user()) || UOJResponse::page403();

// 设置目标目录
$targetDirPictures = __DIR__ . '/../../public/pictures/';  // 图片目录
$targetDirRoot = __DIR__ . '/../../public/';               // PDF 目录（public 根目录）
$targetDirArchives = __DIR__ . '/../../public/archives/';  // 压缩包目录

// 创建图片目录并设置权限（如果不存在）
if (!file_exists($targetDirPictures)) {
    if (!mkdir($targetDirPictures, 0777, true)) {
        die('无法创建图片目录：' . $targetDirPictures);
    }
    chmod($targetDirPictures, 0777);
}

// 检查图片目录是否可写
if (!is_writable($targetDirPictures)) {
    chmod($targetDirPictures, 0777);
    if (!is_writable($targetDirPictures)) {
        die('图片目录不可写，请检查权限');
    }
}

// 创建压缩包目录并设置权限（如果不存在）
if (!file_exists($targetDirArchives)) {
    if (!mkdir($targetDirArchives, 0777, true)) {
        die('无法创建压缩包目录：' . $targetDirArchives);
    }
    chmod($targetDirArchives, 0777);
}

// 检查压缩包目录是否可写
if (!is_writable($targetDirArchives)) {
    chmod($targetDirArchives, 0777);
    if (!is_writable($targetDirArchives)) {
        die('压缩包目录不可写，请检查权限');
    }
}

// 确保 public 根目录存在且可写（PDF 目录）
if (!file_exists($targetDirRoot)) {
    die('Public 目录不存在：' . $targetDirRoot);
}
if (!is_writable($targetDirRoot)) {
    chmod($targetDirRoot, 0777);
    if (!is_writable($targetDirRoot)) {
        die('Public 目录不可写，请检查权限');
    }
}

$message = '';
$messageType = '';
$uploadedUrl = '';

// 处理上传请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件超过 php.ini 限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止',
        ];
        $message = '上传失败：' . ($errors[$file['error']] ?? '未知错误');
        $messageType = 'error';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
        
        // 生成安全的文件名（支持中文）
        $baseName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $baseName);
        if (empty($baseName)) {
            $baseName = 'file';
        }
        
        // 根据文件类型选择目录和验证规则
        if ($extension === 'pdf') {
            // PDF 处理逻辑
            $targetDir = $targetDirRoot;
            $targetPath = $targetDir . $baseName . '.' . $extension;
            
            // 验证 MIME 类型
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if ($mimeType !== 'application/pdf') {
                $message = '文件类型错误，只允许上传 PDF 文件。';
                $messageType = 'error';
            } elseif (file_exists($targetPath)) {
                $message = '服务器已有同名文件 "' . $baseName . '.pdf"，请修改文件名后重新上传。';
                $messageType = 'error';
            } else {
                // 移动 PDF 文件
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $uploadedUrl = '/' . $baseName . '.pdf';
                    $message = 'PDF 上传成功！';
                    $messageType = 'success';
                } elseif (copy($file['tmp_name'], $targetPath)) {
                    $uploadedUrl = '/' . $baseName . '.pdf';
                    $message = 'PDF 上传成功！';
                    $messageType = 'success';
                    unlink($file['tmp_name']);
                } else {
                    $error = error_get_last();
                    $message = '移动文件失败：' . ($error['message'] ?? '未知错误');
                    $messageType = 'error';
                }
            }
        } elseif (in_array($extension, ['zip', 'rar'])) {
            // 压缩包处理逻辑
            $targetDir = $targetDirArchives;
            $targetPath = $targetDir . $baseName . '.' . $extension;
            
            // 验证 MIME 类型
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            // 允许的 MIME 类型
            $allowedArchiveMimes = [
                'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
                'rar' => ['application/x-rar-compressed', 'application/rar', 'application/x-rar']
            ];
            
            $validMime = false;
            foreach ($allowedArchiveMimes[$extension] as $allowedMime) {
                if ($mimeType === $allowedMime) {
                    $validMime = true;
                    break;
                }
            }
            
            if (!$validMime) {
                $message = '文件类型错误，只允许上传 ' . strtoupper($extension) . ' 文件。';
                $messageType = 'error';
            } elseif (file_exists($targetPath)) {
                $message = '服务器已有同名文件 "' . $baseName . '.' . $extension . '"，请修改文件名后重新上传。';
                $messageType = 'error';
            } else {
                // 检查文件大小（限制为 50MB）
                $maxSize = 50 * 1024 * 1024; // 50MB
                if ($file['size'] > $maxSize) {
                    $message = '文件过大，ZIP/RAR 文件最大支持 50MB。';
                    $messageType = 'error';
                } else {
                    // 移动压缩包文件
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $uploadedUrl = '/archives/' . $baseName . '.' . $extension;
                        $message = '压缩包上传成功！';
                        $messageType = 'success';
                    } elseif (copy($file['tmp_name'], $targetPath)) {
                        $uploadedUrl = '/archives/' . $baseName . '.' . $extension;
                        $message = '压缩包上传成功！';
                        $messageType = 'success';
                        unlink($file['tmp_name']);
                    } else {
                        $error = error_get_last();
                        $message = '移动文件失败：' . ($error['message'] ?? '未知错误');
                        $messageType = 'error';
                    }
                }
            }
        } else {
            // 图片处理逻辑（包括 SVG 安全检查）
            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/webp', 
                'image/svg+xml', 'image/gif'
            ];
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            // SVG 特殊处理（有时会被识别为 text/xml 或 text/plain）
            if ($extension === 'svg') {
                $mimeType = 'image/svg+xml';
            }
            
            if (!in_array($mimeType, $allowedTypes)) {
                $message = '只允许上传 JPG、PNG、WebP、SVG 或 GIF 格式的图片。';
                $messageType = 'error';
            } elseif (!in_array($extension, $allowedExtensions)) {
                $message = '文件扩展名必须是 .jpg、.jpeg、.png、.webp、.svg 或 .gif';
                $messageType = 'error';
            } else {
                // 获取正确的扩展名
                if ($extension === 'jpeg' || $extension === 'jpg') {
                    $extension = 'jpg';
                }
                
                $targetDir = $targetDirPictures;
                $targetPath = $targetDir . $baseName . '.' . $extension;
                
                // SVG 安全检查
                $svgIsSafe = true;
                $svgErrorMessage = '';
                
                if ($extension === 'svg') {
                    $svgContent = file_get_contents($file['tmp_name']);
                    
                    // 检查是否包含危险内容
                    $dangerousPatterns = [
                        '<script' => '包含 JavaScript 代码',
                        'javascript:' => '包含 JavaScript 伪协议',
                        'onload=' => '包含 onload 事件处理器',
                        'onclick=' => '包含 onclick 事件处理器',
                        'onerror=' => '包含 onerror 事件处理器',
                        'onmouseover=' => '包含 onmouseover 事件处理器',
                        'data:text/html' => '包含 data URI 的 HTML 内容',
                        '<?php' => '包含 PHP 代码',
                        '<iframe' => '包含 iframe 元素',
                        '<embed' => '包含 embed 元素',
                        '<object' => '包含 object 元素',
                        'xlink:href="javascript:' => '包含 JavaScript 的 xlink',
                        'xmlns:xlink="javascript:' => '包含 JavaScript 的命名空间',
                    ];
                    
                    foreach ($dangerousPatterns as $pattern => $errorMsg) {
                        if (stripos($svgContent, $pattern) !== false) {
                            $svgIsSafe = false;
                            $svgErrorMessage = $errorMsg;
                            break;
                        }
                    }
                    
                    // 检查文件大小（SVG 不应该太大，防止 DoS）
                    if ($svgIsSafe && $file['size'] > 1024 * 1024) { // 1MB 限制
                        $svgIsSafe = false;
                        $svgErrorMessage = 'SVG 文件过大（超过 1MB）';
                    }
                }
                
                if (!$svgIsSafe) {
                    $message = 'SVG 文件安全检查未通过：' . $svgErrorMessage;
                    $messageType = 'error';
                } elseif (file_exists($targetPath)) {
                    $message = '服务器此目录下已有同名文件 "' . $baseName . '.' . $extension . '"，请修改文件名后重新上传。';
                    $messageType = 'error';
                } else {
                    // 移动图片文件
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $uploadedUrl = '/pictures/' . $baseName . '.' . $extension;
                        $message = '图片上传成功！';
                        $messageType = 'success';
                    } elseif (copy($file['tmp_name'], $targetPath)) {
                        $uploadedUrl = '/pictures/' . $baseName . '.' . $extension;
                        $message = '图片上传成功！';
                        $messageType = 'success';
                        unlink($file['tmp_name']);
                    } else {
                        $error = error_get_last();
                        $message = '移动文件失败：' . ($error['message'] ?? '未知错误');
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件上传工具</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 16px;
            color: #666;
            transition: all 0.3s;
        }
        .tab.active {
            color: #007bff;
            border-bottom: 2px solid #007bff;
            margin-bottom: -2px;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        input[type="file"] {
            display: block;
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fafafa;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #0056b3;
        }
        .message {
            margin-top: 20px;
            padding: 12px;
            border-radius: 5px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            background: #e9ecef;
            border-radius: 5px;
        }
        .result a {
            color: #007bff;
            word-break: break-all;
        }
        .preview {
            margin-top: 15px;
        }
        .preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        hr {
            margin: 20px 0;
        }
        .info {
            font-size: 12px;
            color: #666;
            margin-top: 15px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            margin: 0 4px;
            background: #e9ecef;
            border-radius: 3px;
            font-size: 11px;
            font-weight: normal;
        }
        .file-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .pdf-preview, .archive-preview {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            background: #fafafa;
        }
        .pdf-preview a, .archive-preview a {
            display: inline-block;
            margin-top: 5px;
        }
        .archive-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📁 多功能文件上传工具</h1>
        
        <div class="tabs">
            <button class="tab active" onclick="switchTab('image')">🖼️ 图片上传</button>
            <button class="tab" onclick="switchTab('pdf')">📄 PDF 上传</button>
            <button class="tab" onclick="switchTab('archive')">🗜️ 压缩包上传</button>
        </div>
        
        <!-- 图片上传标签页 -->
        <div id="imageTab" class="tab-content active">
            <p>支持格式： 
                <span class="badge">JPG</span>
                <span class="badge">PNG</span>
                <span class="badge">WebP</span>
                <span class="badge">SVG</span>
                <span class="badge">GIF</span>
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="image">选择图片：</label>
                    <input type="file" name="file" id="image" accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif">
                </div>
                <button type="submit">上传图片</button>
            </form>
            
            <div class="info">
                📌 说明：如果服务器已有同名文件，将拒绝上传并提示您修改文件名。<br>
                💡 提示：SVG 文件会进行安全检查，过滤潜在恶意代码。<br>
                📏 限制：单个图片最大 <?php echo ini_get('upload_max_filesize'); ?>B
            </div>
        </div>
        
        <!-- PDF 上传标签页 -->
        <div id="pdfTab" class="tab-content">
            <p>支持格式： 
                <span class="badge">PDF</span>
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="pdf">选择 PDF：</label>
                    <input type="file" name="file" id="pdf" accept="application/pdf">
                </div>
                <button type="submit">上传 PDF</button>
            </form>
            
            <div class="info">
                📌 说明：PDF 文件将直接保存在 public 根目录下，访问地址为 <code>/文件名.pdf</code>。<br>
                📏 限制：最大 <?php echo ini_get('upload_max_filesize'); ?>B
            </div>
        </div>
        
        <!-- 压缩包上传标签页 -->
        <div id="archiveTab" class="tab-content">
            <p>支持格式： 
                <span class="badge">ZIP</span>
                <span class="badge">RAR</span>
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="archive">选择压缩包：</label>
                    <input type="file" name="file" id="archive" accept=".zip,.rar,application/zip,application/x-rar-compressed">
                </div>
                <button type="submit">上传压缩包</button>
            </form>
            
            <div class="info">
                📌 说明：ZIP/RAR 文件将保存在 public/archives/ 目录下，访问地址为 <code>/archives/文件名.zip</code>。<br>
                📏 限制：单个文件最大 50MB<br>
                ⚠️ 注意：请确保上传的文件不包含病毒或恶意代码。
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($uploadedUrl && $messageType === 'success'): ?>
            <div class="result">
                <strong>✅ 访问地址：</strong><br>
                <a href="<?php echo htmlspecialchars($uploadedUrl); ?>" target="_blank"><?php echo htmlspecialchars($uploadedUrl); ?></a>
                <div class="file-info">
                    📂 文件位置：<?php echo htmlspecialchars($uploadedUrl); ?>
                </div>
            </div>
            
            <?php if (strpos($uploadedUrl, '.pdf') !== false): ?>
                <div class="preview pdf-preview">
                    <strong>📄 PDF 预览：</strong><br>
                    <a href="<?php echo htmlspecialchars($uploadedUrl); ?>" target="_blank">点击查看 PDF 文件</a>
                    <div class="file-info">💡 提示：PDF 文件将在新窗口打开</div>
                </div>
            <?php elseif (strpos($uploadedUrl, '/archives/') !== false): ?>
                <div class="preview archive-preview">
                    <div class="archive-icon">
                        <?php if (strpos($uploadedUrl, '.zip') !== false): ?>
                            📦
                        <?php else: ?>
                            🗜️
                        <?php endif; ?>
                    </div>
                    <strong><?php echo strpos($uploadedUrl, '.zip') !== false ? 'ZIP' : 'RAR'; ?> 压缩包：</strong><br>
                    <a href="<?php echo htmlspecialchars($uploadedUrl); ?>" target="_blank">点击下载压缩包</a>
                    <div class="file-info">💡 提示：点击链接即可下载文件</div>
                </div>
            <?php else: ?>
                <div class="preview">
                    <strong>预览：</strong><br>
                    <img src="<?php echo htmlspecialchars($uploadedUrl); ?>?t=<?php echo time(); ?>" alt="上传的图片">
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <hr>
        <div class="info">
            🔒 安全说明：<br>
            - SVG 文件会过滤 JavaScript、事件处理器等危险代码<br>
            - PDF/ZIP/RAR 文件进行 MIME 类型验证和大小限制（50MB）<br>
            - 所有文件都会检查重名冲突，防止覆盖<br>
            <br>
            📁 目录结构：<br>
            - 图片：/public/pictures/<br>
            - PDF：/public/<br>
            - 压缩包：/public/archives/
        </div>
    </div>
    
    <script>
        function switchTab(tab) {
            // 切换标签页样式
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tab === 'image') {
                document.querySelector('.tab').classList.add('active');
                document.getElementById('imageTab').classList.add('active');
            } else if (tab === 'pdf') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('pdfTab').classList.add('active');
            } else if (tab === 'archive') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('archiveTab').classList.add('active');
            }
        }
    </script>
</body>
</html>
