<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 媒体库 文件上传和管理 在文章编辑可以 打开媒体库进行插入操作
 * 
 * @package MediaPU
 * @author 24pu.com
 * @version 1.0.0
 */
class MediaPU_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        self::createTables();
        // 创建缩略图缓存目录
        $thumbDir = __TYPECHO_ROOT_DIR__ . '/usr/thumbnails';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0755, true);
        }
        self::repairAttachments();
        Helper::addPanel(3, 'MediaPU/panel.php', '媒体库', '管理所有媒体文件', 'administrator');
        Helper::addRoute('media_api', '/action/media', 'MediaPU_Widget_Action', 'execute');
        Typecho_Plugin::factory('admin/header.php')->header = array('MediaPU_Plugin', 'addFontAwesome');
        Typecho_Plugin::factory('Widget_Upload')->upload = array(__CLASS__, 'uploadMimeFix');
        return '插件已激活，请到「扩展」菜单中管理媒体库。';
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removePanel(3, 'MediaPU/panel.php');
        Helper::removeRoute('media_api');
        return '插件已禁用，数据已保留。';
    }

    /**
     * 上传时确保 MIME 不为空
     */
    public static function uploadMimeFix($file, $attachment)
    {
        if (is_array($attachment) && (empty($attachment['mime']) || $attachment['mime'] === null)) {
            $ext = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
            $mimeMap = [
                'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
                'gif'  => 'image/gif', 'webp' => 'image/webp', 'bmp'  => 'image/bmp',
                'svg'  => 'image/svg+xml', 'pdf'  => 'application/pdf',
                'zip'  => 'application/zip', 'rar'  => 'application/x-rar-compressed',
                'gp'   => 'application/octet-stream', 'gp5'  => 'application/octet-stream',
                'gpx'  => 'application/octet-stream', 'gtp'  => 'application/octet-stream',
                'mid'  => 'audio/midi', 'ico'  => 'image/x-icon',
            ];
            $attachment['mime'] = $mimeMap[$ext] ?? 'application/octet-stream';
        }
        return $attachment;
    }

    /**
     * 兼容序列化与 JSON 的附件解析
     */
    private static function parseAttachmentText($text)
    {
        $attach = @unserialize($text);
        if ($attach !== false && is_array($attach)) {
            return $attach;
        }
        $attach = @json_decode($text, true);
        if (is_array($attach)) {
            return $attach;
        }
        return [];
    }

    /**
     * 修复附件：序列化→JSON，并补全缺失的 MIME
     */
    public static function repairAttachments()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'contents';

        $rows = $db->fetchAll($db->select('cid', 'text')->from($table)->where('type = ?', 'attachment'));
        $updated = 0;

        foreach ($rows as $row) {
            $attach = self::parseAttachmentText($row['text']);
            if (empty($attach)) continue;

            if (empty($attach['mime'])) {
                $path = $attach['path'] ?? '';
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mimeMap = [
                    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
                    'gif'  => 'image/gif', 'webp' => 'image/webp', 'bmp'  => 'image/bmp',
                    'svg'  => 'image/svg+xml', 'pdf'  => 'application/pdf',
                    'zip'  => 'application/zip', 'rar'  => 'application/x-rar-compressed',
                    'gp'   => 'application/octet-stream', 'gp5'  => 'application/octet-stream',
                    'gpx'  => 'application/octet-stream', 'gtp'  => 'application/octet-stream',
                    'mid'  => 'audio/midi', 'ico'  => 'image/x-icon',
                ];
                $attach['mime'] = $mimeMap[$ext] ?? 'application/octet-stream';
            }

            $json = json_encode($attach, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) continue;

            $db->query($db->update($table)->rows(['text' => $json])->where('cid = ?', $row['cid']));
            $updated++;
        }

        if ($updated > 0) {
            error_log("[MediaPU] 已修复并转换 {$updated} 个附件为 JSON 格式。");
        }
    }

    /**
     * 后台编辑器注入媒体库弹窗
     */
    public static function addFontAwesome($header)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = Helper::options()->plugin('MediaPU');
        $thumbWidth = intval($pluginOptions->thumb_width ?: 150);
        $thumbHeight = intval($pluginOptions->thumb_height ?: 150);
        
        $header .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">';
        $mediaApiUrl = Typecho_Common::url('/action/media', $options->siteUrl);
        $header .= <<<EOT
<style>
.media-modal-write{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:9999}
.media-modal-write.active{display:flex}
.media-modal-content{background:#fff;border-radius:8px;width:900px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column;overflow:hidden}
.media-modal-header{display:flex;justify-content:space-between;align-items:center;padding:15px 20px;border-bottom:1px solid #eee}
.media-modal-body{display:flex;flex:1;overflow:hidden}
.media-folder-sidebar{width:220px;border-right:1px solid #eee;overflow-y:auto;padding:10px;background:#fafafa}
.media-folder-sidebar h4{margin:0 0 10px;font-size:14px;color:#333}
.media-folder-list{list-style:none;padding:0;margin:0}
.media-folder-list li{padding:6px 10px;cursor:pointer;border-radius:4px;margin-bottom:2px;font-size:13px;color:#555}
.media-folder-list li:hover{background:#eef2ff}
.media-folder-list li.active{background:#467b96;color:#fff}
.media-folder-list li i{margin-right:6px;width:14px}
.media-files-area{flex:1;overflow-y:auto;padding:15px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;align-content:start}
.media-file-item{border:1px solid #ddd;border-radius:6px;padding:10px;text-align:center;cursor:pointer;background:#fff}
.media-file-item:hover{background:#f0f4ff}
.media-file-item .file-icon{font-size:2rem;color:#666;margin-bottom:5px}
.media-file-item .file-thumb img{max-width:100%; height:auto; max-height:100px; object-fit:cover; border-radius:4px;}
.media-file-item .file-name{font-size:0.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.media-file-item .file-size{font-size:0.7rem;color:#999}
.media-file-item .insert-btn{display:block;margin-top:8px;padding:3px 8px;background:#467b96;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem}
</style>
<script>
var thumbWidth = {$thumbWidth};
var thumbHeight = {$thumbHeight};
var mediaApiUrl = '{$mediaApiUrl}';

(function() {
    function init() {
        if (typeof jQuery === 'undefined') return;
        var $ = jQuery;
        if (!$('textarea[name="text"]').length) return;

        var btn = $('<button type="button" id="media-library-btn" class="btn btn-xs" style="margin-left:10px;"><i class="fas fa-photo-video"></i> 媒体库</button>');
        $('#title').after(btn);

        var modalParts = [
            '<div id="MediaPUModal" class="media-modal-write">',
            '<div class="media-modal-content">',
            '<div class="media-modal-header">',
            '<h3>选择文件</h3>',
            '<span id="closeMediaModal" style="cursor:pointer;font-size:1.2rem;">&times;</span>',
            '</div>',
            '<div class="media-modal-body">',
            '<div class="media-folder-sidebar">',
            '<h4><i class="fas fa-folder-tree"></i> 文件夹</h4>',
            '<ul class="media-folder-list" id="mediaFolderList">',
            '<li data-folder="0" class="active"><i class="fas fa-hdd"></i> 根目录</li>',
            '</ul>',
            '</div>',
            '<div class="media-files-area" id="mediaFileList">加载中...</div>',
            '</div>',
            '</div>',
            '</div>'
        ];
        $('body').append(modalParts.join(''));

        var currentMediaFolder = 0;

        $('#media-library-btn').click(function() {
            $('#MediaPUModal').addClass('active');
            loadFolderTree();
            loadMediaFiles(0);
        });
        $('#closeMediaModal').click(function() {
            $('#MediaPUModal').removeClass('active');
        });
        $(window).click(function(e) {
            if (e.target === $('#MediaPUModal')[0]) $('#MediaPUModal').removeClass('active');
        });

        function loadFolderTree() {
            $.get(mediaApiUrl + '?action=getFolders', function(res) {
                if (res.code === 200) {
                    var folders = res.data;
                    var items = [];
                    items.push('<li data-folder="0" class="' + (currentMediaFolder === 0 ? 'active' : '') + '"><i class="fas fa-hdd"></i> 根目录</li>');
                    folders.forEach(function(f) {
                        items.push('<li data-folder="' + f.id + '" class="' + (currentMediaFolder === f.id ? 'active' : '') + '"><i class="fas fa-folder"></i> ' + escapeHtml(f.name) + '</li>');
                    });
                    $('#mediaFolderList').html(items.join(''));
                    $('#mediaFolderList li').click(function() {
                        var fid = parseInt($(this).data('folder'));
                        currentMediaFolder = fid;
                        $('#mediaFolderList li').removeClass('active');
                        $(this).addClass('active');
                        loadMediaFiles(fid);
                    });
                }
            });
        }

        function loadMediaFiles(folderId) {
            $('#mediaFileList').html('<p style="text-align:center;padding:20px;">加载中...</p>');
            $.get(mediaApiUrl + '?action=getFiles&folder_id=' + folderId, function(res) {
                if (res.code === 200) {
                    renderMediaFiles(res.data);
                } else {
                    $('#mediaFileList').html('<p style="text-align:center;padding:20px;">无法加载文件</p>');
                }
            });
        }

        function renderMediaFiles(files) {
            var list = $('#mediaFileList');
            list.empty();
            if (!files.length) {
                list.html('<p style="grid-column:1/-1;text-align:center;padding:20px;color:#999;">暂无文件</p>');
                return;
            }
            files.forEach(function(file) {
                var isImage = /\\.(jpg|jpeg|png|gif|webp|bmp)$/i.test(file.url);
                var thumbUrl = mediaApiUrl + '?action=thumb&cid=' + file.cid + '&w=' + thumbWidth + '&h=' + thumbHeight;
                var icon = getFileIcon(file.url);
                var card = '<div class="media-file-item">';
                if (isImage) {
                    card += '<div class="file-thumb"><img src="' + thumbUrl + '" style="max-width:100%; height:auto;"></div>';
                } else {
                    card += '<div class="file-icon"><i class="' + icon + '"></i></div>';
                }
                card += '<div class="file-name" title="' + escapeHtml(file.title) + '">' + escapeHtml(file.title) + '</div>' +
                        '<div class="file-size">' + formatSize(file.size) + '</div>' +
                        '<button class="insert-btn" data-url="' + escapeHtml(file.url) + '" data-title="' + escapeHtml(file.title) + '">插入</button>' +
                        '</div>';
                list.append(card);
            });
        }

        $(document).on('click', '.insert-btn', function() {
            var url = $(this).data('url');
            var title = $(this).data('title');
            var textarea = $('textarea[name="text"]');
            if (!textarea.length) {
                prompt('复制链接：', url);
                return;
            }
            var ext = url.split('.').pop().toLowerCase();
            var guitarExts = ['gp5', 'gpx', 'gp', 'gtp'];
            var insertText;
            if (guitarExts.indexOf(ext) !== -1) {
                insertText = '[guitarpro]' + url + '[/guitarpro]';
            } else {
                insertText = '[' + title + '](' + url + ')';
            }
            var start = textarea[0].selectionStart, end = textarea[0].selectionEnd;
            textarea.val(textarea.val().substring(0, start) + insertText + textarea.val().substring(end));
            textarea[0].selectionStart = textarea[0].selectionEnd = start + insertText.length;
            textarea.focus();
            $('#MediaPUModal').removeClass('active');
        });

        function getFileIcon(url) {
            var ext = url.split('.').pop().toLowerCase();
            var icons = {jpg:'fa-image',jpeg:'fa-image',png:'fa-image',gif:'fa-image',webp:'fa-image',mp4:'fa-video',webm:'fa-video',ogg:'fa-video',mp3:'fa-music',wav:'fa-music',pdf:'fa-file-pdf',zip:'fa-file-archive',rar:'fa-file-archive','7z':'fa-file-archive'};
            return 'fas ' + (icons[ext] || 'fa-file');
        }
        function formatSize(bytes) {
            if (!bytes) return '0 B';
            var k = 1024, sizes = ['B','KB','MB','GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
        }
        function escapeHtml(str) {
            return $('<div>').text(str).html();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
EOT;
        return $header;
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $options = Typecho_Widget::widget('Widget_Options');

        $description = new Typecho_Widget_Helper_Form_Element_Text(
            'description',
            null,
            null,
            '插件说明',
            '通过「扩展」菜单中的「媒体库」管理文件和文件夹。'
        );
        $description->input->setAttribute('disabled', 'disabled');
        $form->addInput($description);

        $thumbWidth = new Typecho_Widget_Helper_Form_Element_Text(
            'thumb_width',
            null,
            '150',
            _t('缩略图宽度(px)'),
            _t('媒体库中图片显示的缩略图宽度，默认150px')
        );
        $form->addInput($thumbWidth);
        
        $thumbHeight = new Typecho_Widget_Helper_Form_Element_Text(
            'thumb_height',
            null,
            '150',
            _t('缩略图高度(px)'),
            _t('媒体库中图片显示的缩略图高度，默认150px，留空则自动按比例')
        );
        $form->addInput($thumbHeight);
        
        echo '<div class="typecho-option" style="margin-top:20px;">
            <label class="typecho-label">修复附件数据</label>
            <p class="description">如果文件管理页面出现 0kb、无链接 等问题，可点击下方按钮修复。</p>
            <button type="button" id="repair-attachments-btn" class="btn btn-xs btn-primary">立即修复</button>
            <span id="repair-attachments-msg" style="margin-left:10px;"></span>
        </div>
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            var btn = document.getElementById("repair-attachments-btn");
            if (!btn) return;
            btn.addEventListener("click", function() {
                if (!confirm("确定要修复所有附件吗？")) return;
                btn.disabled = true;
                btn.textContent = "修复中...";
                var xhr = new XMLHttpRequest();
                xhr.open("POST", "' . Typecho_Common::url('/action/media', $options->siteUrl) . '?action=repairAttachments");
                xhr.onload = function() {
                    var res = JSON.parse(xhr.responseText);
                    var msg = document.getElementById("repair-attachments-msg");
                    if (msg) msg.textContent = res.msg;
                    btn.disabled = false;
                    btn.textContent = "立即修复";
                };
                xhr.onerror = function() {
                    alert("请求失败");
                    btn.disabled = false;
                    btn.textContent = "立即修复";
                };
                xhr.send();
            });
        });
        </script>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 创建数据库表
     */
    private static function createTables()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $folderTable = $prefix . 'media_folders';
        $folderSql = "CREATE TABLE IF NOT EXISTS `{$folderTable}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `parent_id` int(10) unsigned NOT NULL DEFAULT 0,
            `created_at` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $relationTable = $prefix . 'media_relations';
        $relationSql = "CREATE TABLE IF NOT EXISTS `{$relationTable}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `cid` int(10) unsigned NOT NULL,
            `folder_id` int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cid` (`cid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $db->query($folderSql);
            $db->query($relationSql);
        } catch (Exception $e) {
            // 表已存在，忽略错误
        }
    }
}