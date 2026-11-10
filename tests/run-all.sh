#!/bin/sh
# 一次跑完 PHP 功能测试与前端交互测试。
# PHP 测试必须以 Web 运行身份执行；前端测试需要能访问 headless Chromium。
set -u
cd "$(dirname "$0")/.." || exit 1

fail=0

php_test() {
    if [ "$(id -u)" -eq 0 ] && command -v runuser >/dev/null 2>&1; then
        runuser -u www -- php "$1"
    else
        php "$1"
    fi
}

for t in tests/t*.php; do
    echo "== $t"
    php_test "$t" || fail=1
done

echo "== node tests/js/run.mjs"
node tests/js/run.mjs || fail=1

if [ "$fail" -ne 0 ]; then
    printf '\n有测试失败\n'
else
    printf '\n全部测试通过\n'
fi
exit "$fail"
