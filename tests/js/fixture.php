<?php

declare(strict_types=1);

/* JS 交互测试的数据库夹具。
   用法（必须以 Web 服务运行身份执行，保证 runtime/ 与存储目录属主正确）：
     php tests/js/fixture.php create
     php tests/js/fixture.php destroy
   create 会建一个临时用户、一个空文件夹，并写一个会话文件，输出 JSON。 */

require dirname(__DIR__) . '/lib.php';

const JS_TEST_EMAIL = 'js-interactions@example.test';

function js_fixture_destroy_by_email(string $email): void
{
    $user = find_user_by_email($email);
    if ($user === null) {
        return;
    }
    $uid = (int) $user['id'];

    q_exec('DELETE FROM node_tags WHERE node_id IN (SELECT id FROM nodes WHERE user_id = ?)', [$uid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
    q_exec('DELETE FROM tags WHERE user_id = ?', [$uid]);
    q_exec('DELETE FROM user_settings WHERE user_id = ?', [$uid]);
    q_exec('DELETE FROM users WHERE id = ?', [$uid]);
}

$action = (string) ($argv[1] ?? '');

if ($action === 'destroy') {
    js_fixture_destroy_by_email(JS_TEST_EMAIL);
    echo "destroyed\n";
    exit(0);
}

if ($action !== 'create') {
    fwrite(STDERR, "usage: php tests/js/fixture.php create|destroy\n");
    exit(2);
}

js_fixture_destroy_by_email(JS_TEST_EMAIL);

$uid = create_user(JS_TEST_EMAIL, 'js-fixture-pass-123', 'JS 交互测试');
$folder = node_create_folder($uid, user_root_id($uid), 'js-interactions');

$sid = 'jsui' . bin2hex(random_bytes(8));
session_write_close();
ini_set('session.use_strict_mode', '0');
session_id($sid);
session_start();
$_SESSION['user_id'] = $uid;
$_SESSION['auth_epoch'] = (int) q_val('SELECT session_epoch FROM users WHERE id = ?', [$uid]);
session_write_close();

echo json_encode([
    'sid'        => $sid,
    'uid'        => $uid,
    'folderId'   => (int) $folder['id'],
    'folderName' => (string) $folder['name'],
], JSON_UNESCAPED_UNICODE), "\n";
