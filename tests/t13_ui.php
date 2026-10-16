<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* 上传目标已从工具栏下拉改为「上传前确认弹窗」：
   工具栏那个下拉又丑又对不齐，现在改成点上传后弹窗里选文件名 / 存放位置 / 目标目录。 */

$view = (string) file_get_contents(BASE_PATH . '/app/views/files.php');
check(!str_contains($view, 'data-upload-source'), '工具栏不应再有 data-upload-source 下拉');
check(str_contains($view, 'window.__UPLOAD__'), '视图应把可选存放位置注入前端');
check(str_contains($view, 'uploadChoices'), '视图应构造存放位置候选（本地 + s3 源）');
check(str_contains($view, 'defaultSource'), '视图应带上上次记住的选择');
check(str_contains($view, '松手后确认存放位置'), '拖拽落区文案应说明松手后会确认');

$js = (string) file_get_contents(BASE_PATH . '/assets/js/upload.js');
check(str_contains($js, 'function askDestination'), 'upload.js 应提供上传前确认弹窗');
check(str_contains($js, 'function pickAndEnqueue'), 'upload.js 应提供「确认后入队」入口');
check(str_contains($js, 'data-up-name'), '弹窗应含文件名输入');
check(str_contains($js, 'data-up-src'), '弹窗应含存放位置选择');
check(str_contains($js, 'data-up-pick'), '弹窗应含目标目录树');
check(str_contains($js, 'name: it.name'), '建会话时应使用可改写的文件名');
check(str_contains($js, 'upload_source_id'), 'upload.js 应记住上次选择');

$files = (string) file_get_contents(BASE_PATH . '/app/modules/files.php');
check(str_contains($files, 'uploadSources'), 'files_list 应传入可选存储源');

$settings = (string) file_get_contents(BASE_PATH . '/app/modules/settings.php');
check(str_contains($settings, 'upload_source_id'), '设置应可保存 upload_source_id');

// 文件选择、文件夹上传、拖拽三条入口都应先弹确认，而不是直接入队
$filesJs = (string) file_get_contents(BASE_PATH . '/assets/js/files.js');
check(substr_count($filesJs, 'pickAndEnqueue') === 3, '三条上传入口都应走确认弹窗');
check(!str_contains($filesJs, 'SY.transfer.enqueueFiles('), 'files.js 不应再绕过弹窗直接入队');

finish();
