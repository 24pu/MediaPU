<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class MediaPU_Widget_Action extends Widget_Abstract
{
    public function execute()
    {
        if (!$this->user->pass('administrator', true)) {
            $this->json(['code' => 403, 'msg' => '权限不足']);
        }

        $action = $this->request->get('action');
        switch ($action) {
            case 'getFolders':
                $this->getFolders();
                break;
            case 'createFolder':
                $this->createFolder();
                break;
            case 'getFiles':
                $this->getFiles();
                break;
            case 'moveFiles':
                $this->moveFiles();
                break;
            case 'deleteFiles':
                $this->deleteFiles();
                break;
            case 'upload':
                $this->uploadFile();
                break;
            case 'moveFolder':
                $this->moveFolder();
                break;
            case 'deleteFolder':
                $this->deleteFolder();
                break;
            case 'getAllFiles':
                $this->getAllFiles();
                break;
            case 'repairAttachments':
                MediaPU_Plugin::repairAttachments();
                $this->json(['code' => 200, 'msg' => '修复完成']);
                break;
            default:
                $this->json(['code' => 400, 'msg' => '无效请求']);
        }
    }

    private function json($data)
    {
        $this->response->setContentType('application/json');
        $this->response->setStatus(200);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 兼容 PHP 序列化和 JSON 的附件数据解析
     */
    private function parseAttachmentText($text)
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

    private function getFolders()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'media_folders';
        $folders = $db->fetchAll($db->select()->from($table)->order('parent_id, name'));
        $this->json(['code' => 200, 'data' => $folders]);
    }

    private function createFolder()
    {
        $name = trim($this->request->get('name'));
        $parentId = intval($this->request->get('parent_id', 0));
        if (empty($name)) {
            $this->json(['code' => 400, 'msg' => '文件夹名称不能为空']);
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'media_folders';
        $now = time();

        try {
            $db->query($db->insert($table)->rows([
                'name' => $name,
                'parent_id' => $parentId,
                'created_at' => $now
            ]));
            $this->json(['code' => 200, 'msg' => '创建成功']);
        } catch (Exception $e) {
            $this->json(['code' => 500, 'msg' => '数据库错误：' . $e->getMessage()]);
        }
    }

    private function getAllFiles()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $contentsTable = $prefix . 'contents';
        $files = $db->fetchAll($db->select('cid, title, text')
            ->from($contentsTable)
            ->where('type = ?', 'attachment'));

        $result = [];
        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = $options->siteUrl;

        foreach ($files as $file) {
            $attach = $this->parseAttachmentText($file['text']);
            $path = $attach['path'] ?? '';

            if (!empty($path)) {
                if (preg_match('#^https?://#i', $path)) {
                    $url = $path;
                } else {
                    $url = Typecho_Common::url($path, $siteUrl);
                }
            } else {
                $url = '';
            }

            $result[] = [
                'cid'   => $file['cid'],
                'title' => $file['title'],
                'url'   => $url,
                'size'  => $attach['size'] ?? 0,
            ];
        }
        $this->json(['code' => 200, 'data' => $result]);
    }

    private function getFiles()
    {
        $folderId = intval($this->request->get('folder_id', 0));
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $relTable = $prefix . 'media_relations';
        $contentsTable = $prefix . 'contents';

        $query = $db->select('c.cid, c.title, c.text, c.created')
            ->from($relTable . ' AS r')
            ->join($contentsTable . ' AS c', 'r.cid = c.cid')
            ->where('r.folder_id = ?', $folderId)
            ->where('c.type = ?', 'attachment');
        $files = $db->fetchAll($query);

        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = $options->siteUrl;

        foreach ($files as &$file) {
            $attach = $this->parseAttachmentText($file['text']);
            $path = $attach['path'] ?? '';
            if (!empty($path)) {
                if (preg_match('#^https?://#i', $path)) {
                    $file['url'] = $path;
                } else {
                    $file['url'] = Typecho_Common::url($path, $siteUrl);
                }
            } else {
                $file['url'] = '';
            }
            $file['size'] = $attach['size'] ?? 0;
            unset($file['text']);
        }
        $this->json(['code' => 200, 'data' => $files]);
    }

    private function moveFiles()
    {
        $cids = $this->request->getArray('cids');
        $targetFolderId = intval($this->request->get('target_folder_id'));
        if (!is_array($cids) || empty($cids)) {
            $this->json(['code' => 400, 'msg' => '请选择文件']);
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $relTable = $prefix . 'media_relations';

        foreach ($cids as $cid) {
            $exists = $db->fetchRow($db->select('id')->from($relTable)->where('cid = ?', $cid));
            if ($exists) {
                $db->query($db->update($relTable)->rows(['folder_id' => $targetFolderId])->where('cid = ?', $cid));
            } else {
                $db->query($db->insert($relTable)->rows(['cid' => $cid, 'folder_id' => $targetFolderId]));
            }
        }
        $this->json(['code' => 200, 'msg' => '移动成功']);
    }

    private function deleteFiles()
    {
        $cids = $this->request->getArray('cids');
        
        if (!is_array($cids) || empty($cids)) {
            $this->json(['code' => 400, 'msg' => '请选择文件']);
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $contentsTable = $prefix . 'contents';
        $relTable = $prefix . 'media_relations';

        foreach ($cids as $cid) {
            $row = $db->fetchRow($db->select('text')->from($contentsTable)->where('cid = ?', $cid));
            if ($row) {
                $attach = $this->parseAttachmentText($row['text']);
                if (!empty($attach['path'])) {
                    $filePath = __TYPECHO_ROOT_DIR__ . '/' . ltrim($attach['path'], '/');
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
            $db->query($db->delete($contentsTable)->where('cid = ?', $cid));
            $db->query($db->delete($relTable)->where('cid = ?', $cid));
        }
        $this->json(['code' => 200, 'msg' => '删除成功']);
    }

    private function moveFolder()
    {
        $folderId = intval($this->request->get('folder_id'));
        $targetFolderId = intval($this->request->get('target_folder_id'));

        if ($folderId <= 0 || $targetFolderId < 0) {
            $this->json(['code' => 400, 'msg' => '参数错误']);
        }
        if ($folderId == $targetFolderId) {
            $this->json(['code' => 400, 'msg' => '不能移动到自身']);
        }

        $childIds = $this->getAllChildFolderIds($folderId);
        if (in_array($targetFolderId, $childIds)) {
            $this->json(['code' => 400, 'msg' => '不能移动到子文件夹中']);
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'media_folders';

        $db->query($db->update($table)
            ->rows(['parent_id' => $targetFolderId])
            ->where('id = ?', $folderId));

        $this->json(['code' => 200, 'msg' => '移动成功']);
    }

    private function deleteFolder()
    {
        $folderId = intval($this->request->get('folder_id'));
        if ($folderId <= 0) {
            $this->json(['code' => 400, 'msg' => '参数错误']);
        }

        $allFolderIds = $this->getAllChildFolderIds($folderId);
        $allFolderIds[] = $folderId;

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $relTable = $prefix . 'media_relations';
        $folderTable = $prefix . 'media_folders';

        foreach ($allFolderIds as $fid) {
            $db->query($db->update($relTable)
                ->rows(['folder_id' => 0])
                ->where('folder_id = ?', $fid));
        }

        foreach ($allFolderIds as $fid) {
            $db->query($db->delete($folderTable)->where('id = ?', $fid));
        }

        $this->json(['code' => 200, 'msg' => '删除成功，文件夹内文件已移至根目录']);
    }

    private function getAllChildFolderIds($parentId)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'media_folders';

        $all = $db->fetchAll($db->select('id', 'parent_id')->from($table));
        $ids = [];
        $this->_collectChildIds($all, $parentId, $ids);
        return $ids;
    }

    private function _collectChildIds($allFolders, $parentId, &$result)
    {
        foreach ($allFolders as $folder) {
            if ($folder['parent_id'] == $parentId) {
                $result[] = $folder['id'];
                $this->_collectChildIds($allFolders, $folder['id'], $result);
            }
        }
    }

    private function uploadFile()
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['code' => 400, 'msg' => '文件上传失败']);
        }

        $folderId = intval($this->request->get('folder_id', 0));
        $file = $_FILES['file'];
        $originalName = $file['name'];

        $options = Typecho_Widget::widget('Widget_Options');

        $allowedExts = (array) $options->allowedAttachmentTypes;
        if (!empty($allowedExts)) {
            $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowedExts)) {
                $this->json([
                    'code' => 400,
                    'msg'  => '不允许的文件类型：.' . $fileExt . '（允许类型：' . implode(', ', $allowedExts) . '）'
                ]);
            }
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $title = pathinfo($originalName, PATHINFO_FILENAME);

        $safeName = time() . '_' . uniqid() . '.' . $extension;

        $uploadDir = isset($options->uploadDir) ? trim($options->uploadDir, '/') : 'usr/uploads';
        if (empty($uploadDir)) {
            $uploadDir = 'usr/uploads';
        }
        $path = __TYPECHO_ROOT_DIR__ . '/' . $uploadDir . '/' . date('Y') . '/' . date('m') . '/';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $newPath = $path . $safeName;
        $i = 1;
        while (file_exists($newPath)) {
            $safeName = time() . '_' . uniqid() . '_' . $i . '.' . $extension;
            $newPath = $path . $safeName;
            $i++;
        }

        if (move_uploaded_file($file['tmp_name'], $newPath)) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            $slug = Typecho_Common::slugName($title);
            $finalSlug = $slug;
            $count = 1;
            while (true) {
                $exists = $db->fetchRow($db->select('cid')
                    ->from('table.contents')
                    ->where('slug = ?', $finalSlug)
                    ->where('type = ?', 'attachment')
                    ->limit(1));
                if (!$exists) break;
                $finalSlug = $slug . '_' . $count;
                $count++;
            }

            $mimeType = $file['type'] ?? '';
            if (empty($mimeType)) {
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $mimeMap = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf'  => 'application/pdf',
                    'zip'  => 'application/zip',
                    'rar'  => 'application/x-rar-compressed',
                    'gp5'  => 'application/octet-stream',
                    'gpx'  => 'application/octet-stream',
                ];
                $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
            }

            $attach = [
                'path' => substr($newPath, strlen(__TYPECHO_ROOT_DIR__) + 1),
                'size' => $file['size'],
                'mime' => $mimeType
            ];

            $content = [
                'title' => $title,
                'slug' => $finalSlug,
                'created' => time(),
                'modified' => time(),
                'text' => json_encode($attach, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'authorId' => $this->user->uid,
                'type' => 'attachment',
                'status' => 'publish',
                'parent' => 0,
                'allowComment' => 0,
                'allowPing' => 0,
                'allowFeed' => 0,
            ];
            $db->query($db->insert('table.contents')->rows($content));

            $cid = $db->fetchObject($db->select('cid')->from('table.contents')->where('slug = ?', $finalSlug)->limit(1))->cid;

            $relTable = $prefix . 'media_relations';
            $db->query($db->insert($relTable)->rows(['cid' => $cid, 'folder_id' => $folderId]));

            $this->json(['code' => 200, 'msg' => '上传成功', 'cid' => $cid, 'title' => $title]);
        } else {
            $this->json(['code' => 500, 'msg' => '文件保存失败']);
        }
    }
}