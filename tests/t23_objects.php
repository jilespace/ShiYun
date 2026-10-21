<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   对象存储管理页的回归测试。
   本文件按任务增量扩展：账号 → 协议 → 设置 → 上传/下载/复制/删除。
   ========================================================================= */

$email = 't23-objects@example.test';
$otherEmail = 't23-other@example.test';
foreach ([$email, $otherEmail] as $e) {
    $u = find_user_by_email($e);
    if ($u !== null) {
        $oid = (int) $u['id'];
        q_exec('DELETE FROM object_accounts WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM users WHERE id = ?', [$oid]);
    }
}
$uid = create_user($email, 'fixt-pass-123', '对象存储');
$otherId = create_user($otherEmail, 'fixt-pass-123', '旁观者');

echo "\n· 账号\n";
$id = object_account_save($uid, [
    'name' => '腾讯云主账号',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETEXAMPLE',
]);
check($id > 0, '新建账号返回 id');

$list = object_account_list($uid);
check(count($list) === 1, '账号列表返回 1 条');
check($list[0]['secret_key'] === '••••••', '账号列表掩码 Secret Key');
check($list[0]['session_token'] === '', '没有临时令牌时不回显掩码');
check(object_account_get($otherId, $id) === null, '别的用户读不到该账号');

object_account_save($uid, [
    'name' => '改名',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => '••••••',
], $id);
$row = object_account_get($uid, $id);
check($row !== null && $row['name'] === '改名', '更新账号名称');
check($row !== null && $row['secret_key'] === 'SECRETEXAMPLE', '掩码原样回传时不覆盖已存密钥');

$origins = object_account_origins($uid);
check(in_array('https://cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含精确 endpoint');
check(in_array('https://*.cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含桶名子域通配');

$bad = false;
try {
    object_account_save($uid, [
        'name' => '非法', 'provider' => 'cos', 'endpoint' => 'file:///etc/passwd',
        'region' => 'ap-guangzhou', 'access_key' => 'a', 'secret_key' => 'b',
    ]);
} catch (InvalidArgumentException $e) {
    $bad = true;
}
check($bad, '非 http/https endpoint 被拒');

echo "\n· 键名与桶名\n";
check(object_key_valid('photos/2026/a b+c.jpg'), '普通对象键合法');
check(!object_key_valid(''), '空键非法');
check(!object_key_valid("a\0b"), '含 NUL 的键非法');
check(!object_key_valid(str_repeat('a', 1025)), '超过 1024 字节的键非法');
check(object_key_join('photos/', '/2026/a.jpg') === 'photos/2026/a.jpg', '键拼接去掉多余斜杠');
check(object_cos_bucket_name_valid('photos-1250000000'), 'COS 桶名带 APPID 合法');
check(!object_cos_bucket_name_valid('photos'), 'COS 桶名缺 APPID 非法');
check(!object_cos_bucket_name_valid('Photos-1250000000'), 'COS 桶名大写非法');
check(object_storage_class_or_fail('standard_ia') === 'STANDARD_IA', '存储类型归一化为大写');
$badClass = false;
try { object_storage_class_or_fail('GLACIER'); } catch (InvalidArgumentException $e) { $badClass = true; }
check($badClass, '未知存储类型被拒');

echo "\n· 绑定守卫\n";
q_insert('storage_sources', [
    'user_id' => $uid, 'name' => '照片桶', 'driver' => 's3',
    'config' => json_encode(['bucket' => 'photos-1250000000']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok', 'created_at' => now(),
]);
check(object_bound_by($uid, 'photos-1250000000') === '照片桶', '已绑定桶能查到存储源名称');
check(object_bound_by($uid, 'other-1250000000') === null, '未绑定桶返回 null');
check(object_bound_by($otherId, 'photos-1250000000') === null, '别的用户看不到该绑定');

echo "\n· CSP\n";
$csp = csp_content(['https://cos.ap-guangzhou.myqcloud.com']);
check(str_contains($csp, "connect-src 'self' https://cos.ap-guangzhou.myqcloud.com;"), 'CSP 追加对象存储来源');
check(str_contains($csp, "script-src 'self' 'nonce-"), 'CSP 仍带脚本 nonce');
check(str_contains(csp_content(), "connect-src 'self';"), '无额外来源时 connect-src 仍只有 self');

object_account_delete($uid, $id);
check(object_account_get($uid, $id) === null, '删除账号');

q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM user_settings WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM users WHERE id IN (?, ?)', [$uid, $otherId]);

finish();
