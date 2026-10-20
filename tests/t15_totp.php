<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   TOTP 单元测试：RFC 6238 标准测试向量 + Base32 往返 + 校验窗口。
   ========================================================================= */

// RFC 6238 附录 B：SHA1、密钥 ASCII "12345678901234567890"（Base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ）
// 8 位向量取模 10^6 得到本项目使用的 6 位码
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check(totp_code($secret, 1) === '287082', 'RFC 向量 counter=1 → 94287082 → 287082');
check(totp_code($secret, 37037036) === '081804', 'RFC 向量 counter=37037036 → 07081804 → 081804');
check(totp_code($secret, 41152263) === '005924', 'RFC 向量 counter=41152263 → 89005924 → 005924');

// Base32 往返
for ($i = 0; $i < 50; $i++) {
    $s = totp_secret_generate();
    if (strlen($s) !== 32 || preg_match('/^[A-Z2-7]+$/', $s) !== 1) {
        check(false, "随机密钥格式合法（第 {$i} 次）");
        goto decode_check;
    }
    if (totp_base32_decode($s) !== totp_base32_decode(strtolower($s))) {
        check(false, 'Base32 大小写不敏感');
        goto decode_check;
    }
}
check(true, '随机密钥格式合法（50 次）');
check(true, 'Base32 大小写不敏感');

decode_check:
check(totp_base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ') === "12345678901234567890", 'Base32 解码回已知字节串');
check(totp_base32_decode('') === null, '空串解码返回 null');

// 校验逻辑：当前码与 ±1 窗口
$s = totp_secret_generate();
$now = totp_code($s);
$prev = totp_code($s, (int) floor(time() / 30) - 1);
$next = totp_code($s, (int) floor(time() / 30) + 1);
$far = totp_code($s, (int) floor(time() / 30) + 9);
check(totp_verify($s, $now), '当前时间片验证通过');
check(totp_verify($s, $prev), '上一时间片（时钟偏移-1）验证通过');
check(totp_verify($s, $next), '下一时间片（时钟偏移+1）验证通过');
check(!totp_verify($s, $far), '远端时间片被拒绝');
check(!totp_verify($s, '00000x'), '非 6 位数字被拒绝');
check(!totp_verify($s, '000000'), '错误码被拒绝');
check(totp_verify($s, ' ' . substr($now, 0, 3) . ' ' . substr($now, 3) . ' '), '带空格/杂字符的输入被清洗后通过');

// otpauth URI
$uri = totp_uri('JBSWY3DPEHPK3PXP', 'a@b.c', '拾云');
check(str_starts_with($uri, 'otpauth://totp/%E6%8B%BE%E4%BA%91:a%40b.c?secret=JBSWY3DPEHPK3PXP'), 'otpauth URI 格式正确');

/* ------------------------------------------- 两步验证必须同时约束 WebDAV

   WebDAV 走 Basic 认证，是一条独立的入口。它若仍接受登录密码，
   开了两步验证也等于白开——所以这里用静态断言钉住顺序。 */

$webdav = (string) file_get_contents(BASE_PATH . '/app/modules/webdav.php');
$guardPos = strpos($webdav, 'totp_enabled');
$verifyPos = strpos($webdav, "password_verify(\$secret, (string) \$user['password_hash'])");
check($guardPos !== false, 'webdav.php 必须检查 totp_enabled');
check(
    $verifyPos !== false && $guardPos < $verifyPos,
    'totp_enabled 检查必须排在 password_verify 之前（否则开了两步验证仍能用密码过 WebDAV）'
);

/* ------------------------------------------------------ 一次性恢复码逻辑

   恢复码是「认证器丢了」的兜底。这里连成套流程一起验：
   生成 -> 归一化匹配 -> 核销 -> 不可重用 -> 重新生成作废旧码 -> 关闭时清空。 */

$recEmail = 't15-recovery@example.test';
$oldUser = find_user_by_email($recEmail);
if ($oldUser !== null) {
    $oid = (int) $oldUser['id'];
    q_exec('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM login_logs WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}
$recUser = create_user($recEmail, 'fixt-pass-123', '恢复码测试');

// 归一化：用户抄写常带空格 / 短横 / 小写
check(totp_recovery_normalize(' k7f2-9qxm-3tb8 ') === 'K7F29QXM3TB8', '归一化去掉分隔符并转大写');
check(totp_recovery_looks_like('K7F2-9QXM-3TB8'), '12 位输入被认作恢复码');
check(!totp_recovery_looks_like('123456'), '6 位动态码不会被误判成恢复码');
check(!totp_recovery_looks_like('abcdefgh'), '长度不符的输入不会被误判');

$codes = totp_recovery_generate($recUser);
check(count($codes) === TOTP_RECOVERY_COUNT, '一次生成 8 个恢复码（实际 ' . count($codes) . '）');
check(count(array_unique($codes)) === count($codes), '生成的恢复码互不相同');

$shaped = true;
foreach ($codes as $c) {
    if (preg_match('/^[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}$/', $c) !== 1) {
        $shaped = false;
    }
}
check($shaped, '恢复码形如 XXXX-XXXX-XXXX（仅 Base32 字符）');

$stats = totp_recovery_stats($recUser);
check($stats['total'] === 8 && $stats['unused'] === 8, '生成后统计为 8/8');

// 明文不入库：库里只能是哈希
check(
    (int) q_val('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ?',
        [$recUser, $codes[0]]) === 0,
    '恢复码明文不入库（只存 sha256）'
);

check(totp_recovery_consume($recUser, $codes[0]), '有效恢复码核销成功');
check(totp_recovery_stats($recUser)['unused'] === 7, '核销后可用数减一');
check(!totp_recovery_consume($recUser, $codes[0]), '同一个恢复码不能重复使用');
check(!totp_recovery_consume($recUser, 'ZZZZ-ZZZZ-ZZZZ'), '不存在的恢复码核销失败');
check(!totp_recovery_consume($recUser, '123456'), '6 位动态码不会被当作恢复码核销');

// 归一化后仍能匹配（用户抄写时带短横）
$second = $codes[1];
check(totp_recovery_consume($recUser, strtolower(str_replace('-', ' ', $second))), '带空格与小写的输入仍能匹配');

// 重新生成：旧码作废，计数归零重来
$fresh = totp_recovery_generate($recUser);
$stats = totp_recovery_stats($recUser);
check($stats['total'] === 8 && $stats['unused'] === 8, '重新生成后统计回到 8/8');
check(!totp_recovery_consume($recUser, $codes[2]), '重新生成会让旧码全部作废');

totp_recovery_clear($recUser);
check(totp_recovery_stats($recUser)['total'] === 0, '关闭两步验证时清空恢复码');

q_exec('DELETE FROM login_logs WHERE user_id = ?', [$recUser]);
q_exec('DELETE FROM users WHERE id = ?', [$recUser]);

/* ---------------------------------------- 两处接线必须存在（防回退） */

$secSrc = (string) file_get_contents(BASE_PATH . '/app/modules/security.php');
check(str_contains($secSrc, 'totp_recovery_generate($userId)'), '开启两步验证时要发恢复码');
check(str_contains($secSrc, 'totp_recovery_clear($userId)'), '关闭两步验证时要清恢复码');

$authSrc = (string) file_get_contents(BASE_PATH . '/app/modules/auth.php');
check(str_contains($authSrc, 'totp_recovery_consume('), '登录二步必须接受恢复码');

finish();
