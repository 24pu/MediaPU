<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$user = Typecho_Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) {
    throw new Typecho_Http_Exception(403, '权限不足');
}
$options = Typecho_Widget::widget('Widget_Options');
$siteUrl = $options->siteUrl;
$mediaApiUrl = Typecho_Common::url('/action/media', $options->siteUrl);
$pluginUrl = $options->pluginUrl . '/MediaPU';  // 根据实际插件目录名调整
$menu = Typecho_Widget::widget('Widget_Menu');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>媒体库 - <?php $options->title(); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
   <!-- 引用外部 CSS -->
    <link rel="stylesheet" href="<?php echo $pluginUrl; ?>/assets/css/light.css">
</head>
<body>
    <!-- 新增顶部导航 -->
<nav class="admin-top-nav">
    <div class="nav-container">
        <a href="<?php $options->adminUrl(); ?>" class="nav-brand"><?php $options->title(); ?></a>
        <ul class="nav-menu">
            <?php $menu->output(); ?>
        </ul>
        <!-- 右侧用户区域 -->
        <div class="nav-right">
            <a href="<?php $options->siteUrl(); ?>" class="nav-item" target="_blank" title="查看站点">
                <i class="fas fa-external-link-alt"></i> 站点
            </a>
            <span class="nav-user">
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($user->screenName ?: $user->name); ?>
            </span>
            <a href="<?php echo $options->adminUrl('logout.php'); ?>" class="nav-item" title="退出">
                <i class="fas fa-sign-out-alt"></i> 退出
            </a>
        </div>
    </div>
</nav>
<div class="media-manager">
    
    <div class="manager-header">
        <h1><i class="fas fa-images mr-2"></i> 媒体库管理</h1>
    </div>
    <div class="manager-layout">
        <!-- 左侧文件夹区 -->
        <div class="folder-sidebar">
            <div class="folder-header">
                <h2><i class="fas fa-folder-open"></i> 文件夹</h2>
                <div class="folder-actions-toolbar">
                    <button id="uploadFileBtn" class="icon-btn" title="上传文件"><i class="fas fa-upload"></i></button>
                    <button id="createFolderBtn" class="icon-btn" title="新建文件夹"><i class="fas fa-folder-plus"></i></button>
                </div>
            </div>
            <div id="folderTree" class="folder-tree"></div>
        </div>

        <!-- 右侧文件列表区 -->
        <div class="file-content">
            <div class="file-toolbar">
                <h2><i class="fas fa-th-large"></i> 文件列表</h2>
                <div class="toolbar-buttons">
                    <button id="moveSelectedBtn" class="btn-gray"><i class="fas fa-exchange-alt"></i> 移动</button>
                    <button id="deleteSelectedBtn" class="btn-gray btn-danger"><i class="fas fa-trash-alt"></i> 删除</button>
                </div>
            </div>
            <div id="fileList" class="file-grid"></div>
        </div>
    </div>
</div>
<footer class="site-footer">
    <p><?php _e('由 <a href="http://typecho.org" target=_blank>Typecho</a> 驱动'); ?></p>
    <p><a href="https://24pu.com/" target=_blank>24pu</a>制作</p>
</footer>
<!-- 新建文件夹模态框 -->
<div id="createFolderModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-folder-plus"></i> 新建文件夹</h3>
        <input type="text" id="folderName" placeholder="文件夹名称" autocomplete="off">
        <div class="modal-buttons">
            <button id="cancelCreate" class="btn-secondary">取消</button>
            <button id="confirmCreate" class="btn-primary">创建</button>
        </div>
    </div>
</div>

<!-- 移动文件/文件夹模态框 -->
<div id="moveModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-folder-tree"></i> 移动到</h3>
        <select id="targetFolder"></select>
        <div class="modal-buttons">
            <button id="cancelMove" class="btn-secondary">取消</button>
            <button id="confirmMove" class="btn-primary">确认移动</button>
        </div>
    </div>
</div>

<!-- 上传文件模态框 -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-cloud-upload-alt"></i> 上传文件到：<span id="uploadFolderName" style="font-weight:400;">根目录</span></h3>
        <input type="file" id="fileInput">
        <div class="modal-buttons">
            <button id="cancelUpload" class="btn-secondary">取消</button>
            <button id="confirmUpload" class="btn-primary">开始上传</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    let currentFolderId = 0;
    let _isMovingFolder = false;
    let _movingFolderId = null;
    let _movingCids = null;

    // ========= 加载文件夹树 =========
    function loadFolders() {
        $.get('<?php echo $mediaApiUrl; ?>?action=getFolders', function(data) {
            if (data.code === 200) renderTree(data.data, $('#folderTree'), 0);
        });
    }

  function renderTree(folders, $container, parentId) {
    let children = folders.filter(f => f.parent_id == parentId);
    let $ul = $('<ul>');
    children.forEach(f => {
        let $li = $('<li>');
        let $span = $('<span>')
            .addClass('folder-name')
            .html(`
                <span class="folder-label">
                    <i class="fas fa-folder"></i> ${escapeHtml(f.name)}
                </span>
                <span class="folder-actions">
                    <i class="fas fa-exchange-alt move-folder" title="移动文件夹" data-id="${f.id}"></i>
                    <i class="fas fa-trash-alt delete-folder" title="删除文件夹" data-id="${f.id}"></i>
                </span>
            `);
        $span.find('.folder-label').on('click', function(e) {
            e.stopPropagation();
            $('.folder-tree .active').removeClass('active');
            $li.addClass('active');
            currentFolderId = f.id;
            loadFiles(currentFolderId);
        });
        $li.append($span);
        let $sub = $('<div>');
        renderTree(folders, $sub, f.id);   // 递归子文件夹
        $li.append($sub);
        $ul.append($li);
    });

    $container.empty();

    // 如果是根级（parentId == 0），在最顶部添加“根目录”节点
    if (parentId === 0) {
        let $rootLi = $('<li>').addClass('root-folder');
        let $rootSpan = $('<span>')
            .addClass('folder-name')
            .html('<span class="folder-label"><i class="fas fa-hdd"></i> 根目录</span>');
        $rootSpan.on('click', function(e) {
            e.stopPropagation();
            $('.folder-tree .active').removeClass('active');
            $rootLi.addClass('active');
            currentFolderId = 0;
            loadFiles(0);
        });
        $rootLi.append($rootSpan);
        $container.append($rootLi);
    }

    $container.append($ul);

    // 如果当前选中的是根目录，高亮显示
    if (currentFolderId === 0) {
        $('#folderTree .root-folder').addClass('active');
    }
}

    // ========= 加载文件列表 =========
    function loadFiles(folderId) {
        $.get('<?php echo $mediaApiUrl; ?>?action=getFiles&folder_id=' + folderId, function(data) {
            if (data.code === 200) renderFiles(data.data);
        });
    }

    function renderFiles(files) {
        let $list = $('#fileList');
        $list.empty();
        if (!files.length) {
            $list.html('<div class="empty-files"><i class="fas fa-folder-open"></i> 暂无文件，点击左上角上传</div>');
            return;
        }
        files.forEach(file => {
            let $card = $('<div>').addClass('file-card').data('cid', file.cid);
            let icon = getFileIcon(file.url);
            let $checkbox = $('<input>').attr({ type: 'checkbox', class: 'file-checkbox' }).data('cid', file.cid);
            $card.html(`
                <div class="file-icon"><i class="${icon}"></i></div>
                <div class="file-name" title="${escapeHtml(file.title)}">${escapeHtml(file.title)}</div>
                <div class="file-size">${formatSize(file.size)}</div>
                <span class="copy-url-btn" data-url="${escapeHtml(file.url)}" title="复制附件地址">
                    <i class="fas fa-copy"></i>
                </span>
            `);
            $card.prepend($checkbox);
            $card.on('click', function(e) {
                if ($(e.target).is('input[type=checkbox]')) return;
                let cb = $(this).find('.file-checkbox');
                cb.prop('checked', !cb.prop('checked'));
                cb.trigger('change');
            });
            $checkbox.on('change', function() {
                let $parent = $(this).closest('.file-card');
                if ($(this).is(':checked')) {
                    $parent.addClass('selected');
                } else {
                    $parent.removeClass('selected');
                }
            });
            $list.append($card);
        });
    }

    function getFileIcon(url) {
        let ext = url.split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) return 'fas fa-image';
        if (['mp4','webm','ogg'].includes(ext)) return 'fas fa-video';
        if (['mp3','wav','ogg'].includes(ext)) return 'fas fa-music';
        if (['pdf'].includes(ext)) return 'fas fa-file-pdf';
        if (['zip','rar','7z'].includes(ext)) return 'fas fa-file-archive';
        return 'fas fa-file';
    }

    function formatSize(bytes) {
        if (!bytes) return '0 B';
        let k = 1024, sizes = ['B','KB','MB','GB'];
        let i = Math.floor(Math.log(bytes) / Math.log(k));
        return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
    }

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function getSelectedCids() {
        let cids = [];
        $('.file-checkbox:checked').each(function() {
            let cid = $(this).data('cid');
            if (cid) cids.push(cid);
        });
        return cids;
    }

    // ========= 新建文件夹 =========
    $('#createFolderBtn').on('click', () => $('#createFolderModal').addClass('active'));
    $('#cancelCreate').on('click', () => $('#createFolderModal').removeClass('active'));
    $('#confirmCreate').on('click', () => {
        let name = $('#folderName').val();
        if (!name) return alert('请输入文件夹名称');
        $.post('<?php echo $mediaApiUrl; ?>', { action: 'createFolder', name, parent_id: currentFolderId }, function(data) {
            if (data.code === 200) {
                $('#createFolderModal').removeClass('active');
                $('#folderName').val('');
                loadFolders();
            } else alert(data.msg);
        });
    });

    // ========= 移动文件夹 =========
    $(document).on('click', '.move-folder', function(e) {
        e.stopPropagation();
        let folderId = $(this).data('id');
        if (!folderId) return;
        $.get('<?php echo $mediaApiUrl; ?>?action=getFolders', function(data) {
            if (data.code === 200) {
                let $select = $('#targetFolder');
                $select.empty();
                $select.append($('<option>', { value: 0, text: '根目录' }));
                data.data.forEach(f => {
                    if (f.id != folderId) $select.append($('<option>', { value: f.id, text: f.name }));
                });
                _isMovingFolder = true;
                _movingFolderId = folderId;
                $('#moveModal').addClass('active');
            }
        });
    });

    // 删除文件夹
    $(document).on('click', '.delete-folder', function(e) {
        e.stopPropagation();
        let folderId = $(this).data('id');
        if (!folderId) return;
        if (confirm('永久删除该文件夹？子文件夹也将被删除，内部文件将移至根目录。')) {
            $.post('<?php echo $mediaApiUrl; ?>', { action: 'deleteFolder', folder_id: folderId }, function(res) {
                if (res.code === 200) {
                    loadFolders();
                    if (currentFolderId == folderId) { currentFolderId = 0; loadFiles(0); }
                } else alert(res.msg);
            });
        }
    });

    // ========= 移动确认（文件夹或文件） =========
    $('#cancelMove').on('click', () => {
        $('#moveModal').removeClass('active');
        _isMovingFolder = false; _movingFolderId = null; _movingCids = null;
    });
    $('#confirmMove').on('click', () => {
        let targetId = $('#targetFolder').val();
        if (_isMovingFolder && _movingFolderId) {
            $.post('<?php echo $mediaApiUrl; ?>', { action: 'moveFolder', folder_id: _movingFolderId, target_folder_id: targetId }, function(data) {
                if (data.code === 200) {
                    $('#moveModal').removeClass('active');
                    loadFolders();
                    if (currentFolderId == _movingFolderId && targetId != currentFolderId) {
                        currentFolderId = targetId;
                        loadFiles(targetId);
                    }
                } else alert(data.msg);
                _isMovingFolder = false; _movingFolderId = null;
            });
        } else if (_movingCids && _movingCids.length) {
            $.post('<?php echo $mediaApiUrl; ?>', { action: 'moveFiles', cids: _movingCids, target_folder_id: targetId }, function(data) {
                if (data.code === 200) {
                    $('#moveModal').removeClass('active');
                    loadFiles(currentFolderId);
                } else alert(data.msg);
                _movingCids = null;
            });
        }
    });

    // 移动选中文件按钮
    $('#moveSelectedBtn').on('click', () => {
        let selected = getSelectedCids();
        if (!selected.length) { alert('请先勾选文件'); return; }
        _isMovingFolder = false;
        $.get('<?php echo $mediaApiUrl; ?>?action=getFolders', function(data) {
            if (data.code === 200) {
                let $select = $('#targetFolder');
                $select.empty();
                $select.append($('<option>', { value: 0, text: '根目录' }));
                data.data.forEach(f => $select.append($('<option>', { value: f.id, text: f.name })));
                _movingCids = selected;
                $('#moveModal').addClass('active');
            }
        });
    });

    // 删除选中文件
    $('#deleteSelectedBtn').on('click', () => {
        let selected = getSelectedCids();
        if (!selected.length) { alert('请先勾选文件'); return; }
        if (confirm('确定删除选中的文件吗？不可恢复。')) {
            $.post('<?php echo $mediaApiUrl; ?>', { action: 'deleteFiles', cids: selected }, function(data) {
                if (data.code === 200) loadFiles(currentFolderId);
                else alert(data.msg);
            });
        }
    });

    // ========= 上传文件 =========
    let currentUploadFolderId = 0;
    $('#uploadFileBtn').on('click', () => {
        currentUploadFolderId = currentFolderId;
        let folderName = currentFolderId === 0 ? '根目录' : ($('#folderTree .active .folder-label').text().trim() || '根目录');
        $('#uploadFolderName').text(folderName);
        $('#uploadModal').addClass('active');
    });
    $('#cancelUpload').on('click', () => $('#uploadModal').removeClass('active'));
    $('#confirmUpload').on('click', () => {
        let file = $('#fileInput')[0].files[0];
        if (!file) { alert('请选择文件'); return; }
        let formData = new FormData();
        formData.append('file', file);
        formData.append('folder_id', currentUploadFolderId);
        $.ajax({
            url: '<?php echo $mediaApiUrl; ?>?action=upload',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.code === 200) {
                    alert('上传成功');
                    $('#uploadModal').removeClass('active');
                    $('#fileInput').val('');
                    loadFiles(currentFolderId);
                } else alert(res.msg);
            },
            error: function(xhr) {
                try { var res = JSON.parse(xhr.responseText); alert(res.msg || '上传失败'); } catch(e) { alert('上传失败'); }
            }
        });
    });

    // 初始加载
    loadFolders();
    loadFiles(0);

    // 复制附件地址
$(document).on('click', '.copy-url-btn', function(e) {
    e.stopPropagation();   // 阻止事件冒泡到文件卡片，避免触发选中
    var url = $(this).data('url');
    if (!url) return;
    // 使用现代 API，失败时降级到传统方法
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            alert('已复制附件地址');
        }, function() {
            fallbackCopy(url);
        });
    } else {
        fallbackCopy(url);
    }
});

function fallbackCopy(text) {
    var $temp = $('<input>');
    $('body').append($temp);
    $temp.val(text).select();
    try {
        document.execCommand('copy');
        alert('已复制附件地址');
    } catch (e) {
        alert('复制失败，请手动复制');
    }
    $temp.remove();
}
</script>
</body>
</html>