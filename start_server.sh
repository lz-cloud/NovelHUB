#!/bin/bash
# Quick start script for NovelHub development server

echo "========================================="
echo "NovelHub 开发服务器启动脚本"
echo "========================================="
echo ""

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "PHP Version: $PHP_VERSION"

if php -r "exit(version_compare(PHP_VERSION, '7.4.0', '<') ? 1 : 0);"; then
    echo "✗ PHP 版本过低。需要 PHP 7.4 或更高版本。"
    exit 1
else
    echo "✓ PHP 版本符合要求"
fi

# Check required PHP extensions
echo ""
echo "检查 PHP 扩展..."
REQUIRED_EXTS=("json" "fileinfo" "mbstring")
ALL_OK=true

for ext in "${REQUIRED_EXTS[@]}"; do
    if php -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
        echo "✓ $ext"
    else
        echo "✗ $ext (缺失)"
        ALL_OK=false
    fi
done

if [ "$ALL_OK" = false ]; then
    echo ""
    echo "请安装缺失的 PHP 扩展后再试。"
    exit 1
fi

# Set permissions
echo ""
echo "设置目录权限..."
chmod -R 775 data/ uploads/ chapters/ 2>/dev/null || true
chmod 664 data/*.json 2>/dev/null || true

echo "✓ 权限设置完成"

# Determine host and port
HOST="${1:-0.0.0.0}"
PORT="${2:-8000}"

echo ""
echo "========================================="
echo "启动 PHP 内置开发服务器"
echo "地址: http://$HOST:$PORT"
echo "========================================="
echo ""
echo "按 Ctrl+C 停止服务器"
echo ""

# Start PHP built-in server
cd "$(dirname "$0")"
php -S "$HOST:$PORT"
