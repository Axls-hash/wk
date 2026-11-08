<?php
/**
 * syytianheban（爽歪歪天河版）- 前端管理界面
 *
 * 设计特点：
 * - Material Design 扁平化风格
 * - 移动端优先响应式设计
 * - 渐变色主题（蓝紫色调）
 * - 流畅的过渡动画
 * - 卡片式布局
 *
 * @version 3.3.3
 * @author 天河skyriver
 */
require_once __DIR__ . '/ct.php';

$ct = new CT();

// 检查登录
if (!$ct->isLogin()) {
    header('Location: /index/login.php');
    exit;
}

$userInfo = $ct->getUserInfo();
$is_admin = ($userInfo['uid'] == 1);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>爽歪歪天河版 - 项目管理</title>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ==================== CSS 变量系统 ==================== */
        :root {
            /* 主色调 - 渐变蓝紫 */
            --primary-start: #667eea;
            --primary-end: #764ba2;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --primary-shadow: rgba(102, 126, 234, 0.3);

            /* 辅助色 */
            --primary: #667eea;
            --primary-light: #e0e7ff;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;

            /* 背景色 */
            --bg-primary: #f5f7fa;
            --bg-card: #ffffff;
            --bg-overlay: rgba(0, 0, 0, 0.5);

            /* 文字颜色 */
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-tertiary: #9ca3af;
            --text-light: #ffffff;
            --white: #ffffff;

            /* 灰色系 */
            --gray-200: #e5e7eb;
            --gray-600: #4b5563;
            --gray-800: #1f2937;

            /* 边框和分隔线 */
            --border: #e5e7eb;
            --divider: #f3f4f6;

            /* 阴影 */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.15);

            /* 圆角 */
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-full: 9999px;

            /* 间距 */
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;

            /* 过渡动画 */
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 350ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* 深色模式 */
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-card: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-tertiary: #94a3b8;
            --border: #334155;
            --divider: #1e293b;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.5);
        }

        /* ==================== 全局样式 ==================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        *:focus {
            outline: none;
        }

        *:focus-visible {
            outline: 2px solid var(--primary-start);
            outline-offset: 2px;
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 16px;
            transition: background-color var(--transition-base), color var(--transition-base);
            overflow-x: hidden;
        }

        /* ==================== 顶部导航栏 ==================== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: var(--bg-card);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
            z-index: 1000;
            transition: all var(--transition-base);
        }

        [data-theme="dark"] .navbar {
            background: rgba(30, 41, 59, 0.95);
        }

        .navbar-container {
            max-width: 1200px;
            height: 100%;
            margin: 0 auto;
            padding: 0 var(--spacing-md);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            font-size: 20px;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            cursor: pointer;
        }

        .navbar-actions {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }

        .balance-badge {
            padding: 8px 16px;
            background: var(--bg-primary);
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
            transition: all var(--transition-base);
        }

        .balance-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .balance-amount {
            color: var(--success);
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            border: none;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all var(--transition-base);
        }

        .icon-btn:hover {
            background: var(--bg-primary);
            color: var(--text-primary);
            transform: scale(1.1);
        }

        .icon-btn:active {
            transform: scale(0.95);
        }

        /* ==================== 主内容区 ==================== */
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: calc(64px + var(--spacing-lg)) var(--spacing-md) var(--spacing-lg);
        }

        /* ==================== 顶部统计卡片 ==================== */
        .hero-section {
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            color: var(--text-light);
        }

        .hero-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: var(--spacing-sm);
        }

        .hero-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: var(--spacing-lg);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--spacing-md);
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-md);
            padding: var(--spacing-md);
            transition: all var(--transition-base);
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-4px);
        }

        .stat-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: var(--spacing-xs);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
        }

        /* ==================== 标签页导航 ==================== */
        .tabs-container {
            margin-bottom: var(--spacing-lg);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .tabs-container::-webkit-scrollbar {
            display: none;
        }

        .tabs {
            display: flex;
            gap: var(--spacing-sm);
            min-width: max-content;
            padding: 4px;
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        .tab-btn {
            padding: 12px 24px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-base);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }

        .tab-btn:hover {
            color: var(--text-primary);
            background: var(--bg-primary);
        }

        .tab-btn.active {
            background: var(--primary-gradient);
            color: var(--text-light);
            box-shadow: var(--shadow-md);
        }

        /* ==================== 卡片组件 ==================== */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
            overflow: hidden;
            transition: all var(--transition-base);
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: var(--spacing-md);
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }

        .card-body {
            padding: var(--spacing-lg);
        }

        /* ==================== 按钮组件 ==================== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-xs);
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-base);
            text-decoration: none;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width var(--transition-base), height var(--transition-base);
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:active {
            transform: scale(0.96);
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: var(--text-light);
            box-shadow: 0 4px 12px var(--primary-shadow);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 16px var(--primary-shadow);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: var(--text-light);
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--text-light);
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: var(--danger);
            color: var(--text-light);
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background: var(--bg-primary);
            border-color: var(--primary-start);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
        }

        .btn-lg {
            padding: 14px 28px;
            font-size: 16px;
        }

        .btn-group {
            display: flex;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
        }

        /* ==================== 表格组件 ==================== */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            position: relative;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: var(--bg-primary);
        }

        .table th {
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
        }

        .table tbody tr {
            transition: all var(--transition-fast);
        }

        .table tbody tr:hover {
            background: var(--bg-primary);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .link-primary {
            color: var(--primary-start);
            text-decoration: none;
            transition: color var(--transition-base);
        }

        .link-primary:hover {
            color: var(--primary-end);
            text-decoration: underline;
        }

        /* 移动端表格样式 - 保持表格式，支持横向滚动 */
        @media (max-width: 768px) {
            .table thead {
                display: table-header-group;
            }

            .table tbody, .table tr {
                display: table-row-group;
            }

            .table tr {
                display: table-row;
            }

            .table th, .table td {
                display: table-cell;
                padding: 10px 8px;
                font-size: 12px;
                white-space: nowrap;
            }

            .table th {
                font-size: 11px;
                padding: 10px 8px;
            }

            /* 代理列表表格固定布局 */
            .table-fixed {
                display: table !important;
                min-width: 100%;
            }

            .table-fixed thead {
                display: table-header-group !important;
            }

            .table-fixed tbody {
                display: table-row-group !important;
            }

            .table-fixed tr {
                display: table-row !important;
                background: transparent !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                margin-bottom: 0 !important;
                padding: 0 !important;
                border: none !important;
                flex-direction: row !important;
            }

            .table-fixed td,
            .table-fixed th {
                display: table-cell !important;
                padding: 12px 8px !important;
                border-bottom: 1px solid var(--border) !important;
                font-size: 13px !important;
                white-space: nowrap;
                text-align: left;
            }

            /* 金额列右对齐 */
            .table-fixed td:nth-child(3),
            .table-fixed td:nth-child(5),
            .table-fixed td:nth-child(6),
            .table-fixed th:nth-child(3),
            .table-fixed th:nth-child(5),
            .table-fixed th:nth-child(6) {
                text-align: right;
            }

            /* UID和项目数列居中 */
            .table-fixed td:nth-child(1),
            .table-fixed td:nth-child(4),
            .table-fixed th:nth-child(1),
            .table-fixed th:nth-child(4) {
                text-align: center;
            }

            /* 操作列居中 */
            .table-fixed td:last-child,
            .table-fixed th:last-child {
                text-align: center;
            }

            .table-fixed td:first-child {
                border-bottom: 1px solid var(--border) !important;
                padding-bottom: 12px !important;
                margin-bottom: 0 !important;
                font-size: 13px !important;
                order: 0 !important;
                width: auto !important;
            }

            .table-fixed td:last-child {
                margin-top: 0 !important;
                padding-top: 12px !important;
                border-top: none !important;
                width: auto !important;
            }

            .table-fixed td::before {
                display: none !important;
            }

            /* 确保容器可以水平滚动 */
            .table-container:has(.table-fixed) {
                overflow-x: auto !important;
            }

            /* 优化代理列表按钮在移动端的显示 */
            .table-fixed .btn-sm {
                font-size: 12px;
                padding: 6px 10px;
            }

            .table-fixed .btn-sm i {
                margin-right: 4px;
            }
        }

        /* ==================== 项目卡片布局 ==================== */
        .projects-container {
            padding: var(--spacing-md);
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .project-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            overflow: hidden;
            position: relative;
        }

        .project-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .project-card-header {
            padding: 12px 16px 8px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .project-card-main {
            flex: 1;
            min-width: 0;
        }

        .project-card-name {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.3;
            margin-bottom: 4px;
        }

        .project-card-id {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-family: 'Roboto Mono', monospace;
        }

        .project-card-body {
            padding: 0 16px 12px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .project-data-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .project-data-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .project-data-value {
            font-size: 0.875rem;
            color: var(--text-primary);
            word-break: break-all;
        }

        .project-data-value.number {
            font-family: 'Roboto Mono', monospace;
            font-weight: 600;
            color: var(--primary);
        }

        .project-data-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .project-card-actions {
            padding: 8px 12px;
            border-top: 1px solid var(--divider);
            background: var(--bg-primary);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }

        .project-card-actions .btn {
            padding: 6px 12px;
            font-size: 0.8125rem;
        }

        .project-card-actions .btn i {
            font-size: 0.75rem;
        }

        /* 状态徽章优化 */
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 12px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .status-badge.active {
            background: #e6f4ea;
            color: #137333;
        }

        .status-badge.inactive {
            background: #fce8e6;
            color: #c5221f;
        }

        /* 移动端优化 */
        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
            }

            .project-card-actions {
                flex-wrap: wrap;
            }

            .project-card-actions .btn {
                flex: 1;
                min-width: 0;
            }
        }

        /* 空状态样式 */
        .projects-grid .empty-state {
            grid-column: 1 / -1;
            padding: var(--spacing-xl);
            text-align: center;
        }

        /* ==================== 我的秘钥卡片布局 ==================== */
        .keys-container {
            padding: var(--spacing-md);
        }

        .keys-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .key-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            overflow: hidden;
            position: relative;
        }

        .key-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .key-card-header {
            padding: 10px 14px;
            border-bottom: 1px solid var(--divider);
            background: var(--surface);
        }

        .key-card-name {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .key-card-body {
            padding: 10px 14px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .key-balance-display {
            text-align: center;
            padding: 10px;
            background: linear-gradient(135deg, #e6f4ea 0%, #f0fdf4 100%);
            border-radius: 8px;
            margin-bottom: 4px;
        }

        .key-balance-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #137333;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .key-balance-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #137333;
            font-family: 'Roboto Mono', monospace;
        }

        .key-data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed var(--divider);
        }

        .key-data-row:last-child {
            border-bottom: none;
        }

        .key-data-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .key-data-value {
            font-size: 0.875rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .key-data-value.rate {
            font-family: 'Roboto Mono', monospace;
            color: var(--primary);
        }

        .key-data-value.time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        .key-card-actions {
            padding: 8px 12px;
            border-top: 1px solid var(--divider);
            background: var(--bg-primary);
            display: flex;
            justify-content: center;
            gap: 8px;
        }

        .key-card-actions .btn {
            padding: 7px 10px;
            font-size: 0.8125rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .key-card-actions .btn i {
            font-size: 1rem;
        }

        .key-card-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        /* 移动端保持2列布局 */
        @media (max-width: 768px) {
            .keys-grid {
                gap: 12px;
            }
        }

        /* 空状态样式 */
        .keys-grid .empty-state {
            grid-column: 1 / -1;
            padding: var(--spacing-xl);
            text-align: center;
        }

        /* ==================== 徽章组件 ==================== */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
        }

        .badge-success {
            background: var(--success-light);
            color: var(--success);
        }

        .badge-warning {
            background: var(--warning-light);
            color: var(--warning);
        }

        .badge-danger {
            background: var(--danger-light);
            color: var(--danger);
        }

        .badge-info,
        .bg-info {
            background: var(--info-light);
            color: var(--info);
        }

        .bg-success {
            background: var(--success-light);
            color: var(--success);
        }

        .bg-warning,
        .bg-warning.text-dark {
            background: var(--warning-light);
            color: var(--warning);
        }

        .bg-danger {
            background: var(--danger-light);
            color: var(--danger);
        }

        .bg-primary {
            background: var(--primary-light);
            color: var(--primary);
        }

        .bg-secondary {
            background: var(--gray-200);
            color: var(--gray-600);
        }

        .bg-dark {
            background: var(--gray-800);
            color: var(--white);
        }

        /* ==================== 表单组件 ==================== */
        .form-group {
            margin-bottom: var(--spacing-lg);
        }

        .form-label {
            display: block;
            margin-bottom: var(--spacing-sm);
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-primary);
            font-size: 16px;
            transition: all var(--transition-base);
        }

        .form-control:focus {
            border-color: var(--primary-start);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-tertiary);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 20px;
            padding-right: 40px;
        }

        .form-hint {
            margin-top: var(--spacing-sm);
            font-size: 13px;
            color: var(--text-secondary);
        }

        /* ==================== 工单筛选器样式 ==================== */
        .tickets-filter-row {
            display: flex;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
            flex-wrap: wrap;
        }

        .tickets-filter-item {
            flex: 1;
            min-width: 140px;
        }

        .tickets-filter-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }

        .tickets-filter-label i {
            font-size: 12px;
            color: var(--primary-start);
        }

        .tickets-filter-select {
            padding: 10px 14px;
            font-size: 14px;
            border-radius: var(--radius-sm);
        }

        /* ==================== 工单表单样式 ==================== */
        .ticket-form-group {
            margin-bottom: var(--spacing-md);
        }

        .ticket-form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xs);
        }

        .ticket-form-label i {
            color: var(--primary-start);
            font-size: 14px;
            width: 16px;
            text-align: center;
        }

        .ticket-form-label .text-danger {
            color: var(--danger);
            margin-left: 2px;
        }

        .ticket-file-upload {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
        }

        .ticket-upload-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all var(--transition-base);
        }

        .ticket-upload-btn:hover {
            border-color: var(--primary-start);
            color: var(--primary-start);
            background: rgba(102, 126, 234, 0.05);
        }

        .ticket-image-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        /* ==================== 工单详情样式（表格式） ==================== */
        .ticket-detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: var(--spacing-lg);
            font-size: 14px;
        }

        .ticket-detail-table td {
            padding: 10px 12px;
            border: 1px solid var(--border);
        }

        .ticket-detail-table td:first-child {
            background: var(--bg-secondary);
            font-weight: 500;
            color: var(--text-secondary);
            width: 100px;
            white-space: nowrap;
        }

        .ticket-detail-table td:last-child {
            color: var(--text-primary);
        }

        .ticket-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }

        .ticket-badge-suggestion { background: #e3f2fd; color: #1565c0; }
        .ticket-badge-bug { background: #ffebee; color: #c62828; }
        .ticket-badge-compensation { background: #fff8e1; color: #f57f17; }
        .ticket-badge-pending { background: #f5f5f5; color: #616161; }
        .ticket-badge-processing { background: #e3f2fd; color: #1565c0; }
        .ticket-badge-resolved { background: #e8f5e9; color: #2e7d32; }
        .ticket-badge-closed { background: #424242; color: #fff; }

        .ticket-section {
            margin-bottom: var(--spacing-lg);
        }

        .ticket-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--spacing-sm);
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }

        .ticket-section-title i {
            color: var(--primary-start);
        }

        .ticket-section-content {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
            white-space: pre-wrap;
            padding: var(--spacing-sm) 0;
        }

        .ticket-image-box img {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid var(--border);
            margin-top: var(--spacing-sm);
        }

        .ticket-compensation-box {
            padding: 10px 12px;
            background: #fff8e1;
            border: 1px solid #ffecb3;
            margin-bottom: var(--spacing-md);
            font-size: 14px;
            color: #f57f17;
        }

        .ticket-review-box {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-md);
            font-size: 14px;
            font-weight: 500;
        }

        .ticket-review-box.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .ticket-review-box.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .ticket-replies-list {
            max-height: 300px;
            overflow-y: auto;
            padding-right: var(--spacing-xs);
        }

        .ticket-replies-list .card {
            border: 1px solid var(--border) !important;
        }

        .ticket-reply-actions {
            display: flex;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-sm);
        }

        .ticket-reply-actions .btn {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ==================== 模态框组件 ==================== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--bg-overlay);
            backdrop-filter: blur(4px);
            z-index: 2000;
            padding: var(--spacing-md);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity var(--transition-base);
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            transform: scale(0.9) translateY(20px);
            transition: transform var(--transition-slow);
        }

        .modal.show .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            padding: var(--spacing-lg);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-full);
            border: none;
            background: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all var(--transition-base);
        }

        .modal-close:hover {
            background: var(--danger);
            color: var(--text-light);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: var(--spacing-lg);
        }

        .modal-footer {
            padding: var(--spacing-lg);
            border-top: 1px solid var(--border);
            display: flex;
            gap: var(--spacing-md);
            justify-content: flex-end;
        }

        /* ==================== Toast 通知 ==================== */
        .toast-container {
            position: fixed;
            top: 80px;
            right: var(--spacing-md);
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
            max-width: 400px;
            width: calc(100% - 32px);
        }

        .toast {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: var(--spacing-md);
            display: flex;
            align-items: flex-start;
            gap: var(--spacing-md);
            animation: slideInRight var(--transition-base) ease-out;
            border-left: 4px solid var(--info);
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        .toast.warning {
            border-left-color: var(--warning);
        }

        .toast-content {
            flex: 1;
            font-size: 14px;
            color: var(--text-primary);
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 18px;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ==================== 加载器 ==================== */
        .loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            z-index: 10;
        }

        [data-theme="dark"] .loader {
            background: rgba(15, 23, 42, 0.9);
        }

        .loader.show {
            display: flex;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid var(--border);
            border-top-color: var(--primary-start);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* ==================== 空状态 ==================== */
        .empty-state {
            text-align: center;
            padding: var(--spacing-xl);
            color: var(--text-secondary);
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: var(--spacing-md);
            opacity: 0.3;
        }

        .empty-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: var(--spacing-sm);
        }

        .empty-desc {
            font-size: 14px;
            margin-bottom: var(--spacing-lg);
        }

        /* ==================== 提示框 ==================== */
        .alert {
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-md);
            display: flex;
            align-items: flex-start;
            gap: var(--spacing-md);
        }

        .alert-info {
            background: var(--info-light);
            color: var(--info);
        }

        .alert-success {
            background: var(--success-light);
            color: var(--success);
        }

        .alert-warning {
            background: var(--warning-light);
            color: var(--warning);
        }

        .alert-danger {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* ==================== iframe容器 ==================== */
        #iframe-container {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--bg-card);
            z-index: 9999;
        }

        #iframe-container.active {
            display: block;
        }

        #content-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .iframe-close-btn {
            position: fixed;
            bottom: var(--spacing-md);
            right: var(--spacing-md);
            width: 56px;
            height: 56px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            color: var(--text-light);
            border: none;
            cursor: grab;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: var(--shadow-xl);
            z-index: 10000;
            transition: all var(--transition-base);
            touch-action: none;
            user-select: none;
        }

        .iframe-close-btn:hover {
            transform: scale(1.15);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .iframe-close-btn:active {
            cursor: grabbing;
            transform: scale(1.05);
        }

        .iframe-close-btn.dragging {
            transition: none;
        }

        /* ==================== 动画 ==================== */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* ==================== 工具类 ==================== */
        .text-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .text-center {
            text-align: center;
        }

        .font-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .hidden {
            display: none !important;
        }

        /* ==================== 响应式断点 - 移动端优化 ==================== */
        @media (max-width: 768px) {
            /* CSS变量移动端调整 */
            :root {
                --spacing-lg: 16px;
                --spacing-xl: 24px;
            }

            /* 导航栏 */
            .navbar-container {
                padding: 0 var(--spacing-md);
            }

            .navbar-brand {
                font-size: 18px;
            }

            .balance-badge {
                padding: 6px 12px;
                font-size: 12px;
            }

            /* 增大触摸区域 */
            .icon-btn {
                width: 44px;
                height: 44px;
            }

            /* 主容器 */
            .main-container {
                padding-top: calc(64px + var(--spacing-md));
                padding-bottom: 80px; /* 防止底部栏遮挡 */
            }

            /* Hero区域优化 */
            .hero-section {
                padding: var(--spacing-md);
                border-radius: var(--radius-md);
            }

            .hero-title {
                font-size: 20px;
            }

            .hero-subtitle {
                font-size: 13px;
            }

            /* 统计卡片强制一行两个 */
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: var(--spacing-sm);
            }

            .stat-card {
                padding: var(--spacing-sm);
            }

            .stat-value {
                font-size: 20px;
            }

            .stat-label {
                font-size: 12px;
            }

            /* 标签页导航 */
            .tab-btn {
                padding: 10px 16px;
                height: 44px;
                font-size: 13px;
            }

            /* 卡片 */
            .card-header {
                padding: var(--spacing-md);
            }

            .card-body {
                padding: var(--spacing-md);
            }

            .card-title {
                font-size: 16px;
            }

            /* 按钮组优化 - 水平排列 */
            .btn-group {
                flex-direction: row;
                gap: var(--spacing-xs);
            }

            .btn-group .btn {
                flex: 1;
                width: auto;
                padding: 8px 4px;
                font-size: 12px;
                justify-content: center;
            }

            .btn-group .btn i {
                font-size: 14px;
            }

            /* Toast通知 - 改为底部弹出 */
            .toast-container {
                top: auto;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                right: auto;
                width: 90%;
                max-width: 400px;
            }

            .toast {
                animation: fadeInUp 0.3s ease-out;
            }

            /* 模态框 */
            .modal-close {
                width: 40px;
                height: 40px;
            }

            .modal-title {
                font-size: 18px;
            }

            /* 表单 */
            .form-label {
                font-size: 13px;
            }

            .form-control {
                font-size: 16px; /* 防止iOS自动缩放 */
            }

            /* 空状态 */
            .empty-icon {
                font-size: 48px;
            }

            .empty-title {
                font-size: 16px;
            }

            .empty-desc {
                font-size: 13px;
            }
        }

        /* ==================== 公告弹窗 ==================== */
        .announcement-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 4000;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-md);
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .announcement-modal.show {
            display: flex;
            opacity: 1;
        }
        .announcement-modal-box {
            position: relative;
            width: 100%;
            max-width: 460px;
            background: var(--bg-card);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,0.22), 0 0 0 1px rgba(255,255,255,0.06);
            transform: scale(0.9) translateY(32px);
            transition: transform 0.4s cubic-bezier(.34,1.46,.64,1);
        }
        .announcement-modal.show .announcement-modal-box {
            transform: scale(1) translateY(0);
        }

        /* 顶部横幅 */
        .ann-modal-banner {
            background: var(--primary-gradient);
            padding: 32px 32px 24px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .ann-modal-banner::before {
            content: '';
            position: absolute;
            top: -60px; right: -40px;
            width: 200px; height: 200px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            pointer-events: none;
        }
        .ann-modal-banner::after {
            content: '';
            position: absolute;
            bottom: -70px; left: -30px;
            width: 160px; height: 160px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        /* 图标圆圈 */
        .ann-modal-icon-wrap {
            position: relative; z-index: 1;
            flex-shrink: 0;
            width: 52px; height: 52px;
            border-radius: 50%;
            background: rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #fff;
            box-shadow: 0 0 0 6px rgba(255,255,255,0.08);
        }
        .ann-modal-banner-text {
            position: relative; z-index: 1;
        }
        .ann-modal-tag {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            margin-bottom: 4px;
        }
        .ann-modal-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            letter-spacing: 0.3px;
        }

        /* 正文区域 */
        .ann-modal-body {
            padding: 24px 28px 8px;
        }
        .ann-modal-content {
            font-size: 0.9375rem;
            color: var(--text-primary);
            line-height: 1.85;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 220px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--border) transparent;
        }
        .ann-modal-content::-webkit-scrollbar { width: 4px; }
        .ann-modal-content::-webkit-scrollbar-track { background: transparent; }
        .ann-modal-content::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }
        .ann-modal-divider {
            margin: 16px 0 0;
            height: 1px;
            background: var(--divider);
        }

        /* 底部操作栏 */
        .ann-modal-footer {
            padding: 14px 28px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .ann-modal-time {
            font-size: 0.72rem;
            color: var(--text-tertiary);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        /* 自定义 checkbox */
        .ann-modal-check {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8125rem;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
            padding: 4px 0;
        }
        .ann-modal-check-box {
            width: 16px; height: 16px;
            border: 2px solid var(--border);
            border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
            background: var(--bg-card);
        }
        .ann-modal-check-box.checked {
            background: var(--primary-start);
            border-color: var(--primary-start);
        }
        .ann-modal-check-box.checked::after {
            content: '';
            width: 9px; height: 6px;
            border-left: 2px solid #fff;
            border-bottom: 2px solid #fff;
            transform: rotate(-45deg) translateY(-1px);
            display: block;
        }
        /* 隐藏原生 checkbox */
        .ann-modal-check input[type=checkbox] { display: none; }
        .ann-modal-close-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 26px;
            background: var(--primary-gradient);
            color: #fff;
            border: none;
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition-base);
            box-shadow: 0 4px 14px var(--primary-shadow);
            letter-spacing: 0.3px;
            margin-left: auto;
        }
        .ann-modal-close-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--primary-shadow);
        }
        .ann-modal-close-btn:active { transform: scale(0.97); }

        @media (max-width: 480px) {
            .announcement-modal-box { border-radius: 22px; max-width: 100%; }
            .ann-modal-banner { padding: 22px 20px 18px; gap: 12px; }
            .ann-modal-body { padding: 18px 20px 8px; }
            .ann-modal-footer { padding: 12px 20px 20px; }
            .ann-modal-icon-wrap { width: 44px; height: 44px; font-size: 1.2rem; }
            .ann-modal-title { font-size: 1.1rem; }
        }

        /* ==================== 兑换记录移动端紧凑布局 ==================== */
        @media (max-width: 768px) {
            .table-exchange thead { display: none; }
            .table-exchange tbody { display: block; }

            .table-exchange tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                grid-template-rows: auto auto;
                gap: 2px 8px;
                background: var(--bg-card);
                border-radius: 10px;
                border: 1px solid var(--border);
                padding: 8px 12px;
                margin-bottom: 8px;
                box-shadow: var(--shadow-sm);
            }

            .table-exchange td {
                display: block;
                padding: 2px 0;
                border: none;
                font-size: 13px;
            }

            /* 第1格：时间（左上，小字灰色） */
            .table-exchange td:nth-child(1) {
                grid-column: 1; grid-row: 1;
                font-size: 11px;
                color: var(--text-tertiary) !important;
                order: 0;
                width: auto;
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            .table-exchange td:nth-child(1)::before { display: none; }

            /* 第2格：项目名（左下，粗体） */
            .table-exchange td:nth-child(2) {
                grid-column: 1; grid-row: 2;
                font-weight: 600;
                font-size: 13px;
                color: var(--text-primary);
            }

            /* 第3格：类型徽章（右上，右对齐） */
            .table-exchange td:nth-child(3) {
                grid-column: 2; grid-row: 1;
                text-align: right;
                display: flex;
                justify-content: flex-end;
                align-items: center;
            }

            /* 第4格：平台变动（右下，右对齐，大字） */
            .table-exchange td:nth-child(4) {
                grid-column: 2; grid-row: 2;
                text-align: right;
                font-size: 14px;
                font-weight: 700;
                display: flex;
                justify-content: flex-end;
            }

            /* 第5、6、7格：隐藏次要信息（项目变动/比例/余额），可按需展开 */
            .table-exchange td:nth-child(5),
            .table-exchange td:nth-child(6),
            .table-exchange td:nth-child(7) {
                display: none;
            }

            /* 去掉 before 伪元素 */
            .table-exchange td::before { display: none !important; }

            /* 最后一列不需要特殊样式 */
            .table-exchange td:last-child {
                margin-top: 0 !important;
                padding-top: 0 !important;
                border-top: none !important;
                width: auto !important;
            }
        }

        /* ==================== 公告翻转卡片 ==================== */
        .hero-flip-wrapper {
            perspective: 1200px;
            width: 100%;
        }

        .hero-flip-inner {
            position: relative;
            width: 100%;
            transition: transform 0.7s cubic-bezier(.4,0,.2,1);
            transform-style: preserve-3d;
        }

        .hero-flip-inner.flipped {
            transform: rotateX(180deg);
        }

        .hero-flip-front,
        .hero-flip-back {
            width: 100%;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
        }

        .hero-flip-back {
            position: absolute;
            top: 0;
            left: 0;
            transform: rotateX(180deg);
            cursor: pointer;
        }

        .announcement-panel {
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 28px 32px;
            backdrop-filter: blur(10px);
            min-height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .announcement-panel .ann-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.9;
        }

        .announcement-panel .ann-title {
            font-size: 1rem;
            font-weight: 600;
            color: rgba(255,255,255,0.8);
            margin-bottom: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .announcement-panel .ann-content {
            font-size: 1.05rem;
            color: #fff;
            line-height: 1.7;
            white-space: pre-wrap;
        }

        .announcement-panel .ann-hint {
            margin-top: 12px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.55);
        }

        /* Toast底部弹出动画 */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1025px) {
            .stats-row {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* ==================== 工单详情加载动画 ==================== */
        .ticket-spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto;
            border: 3px solid var(--border);
            border-top-color: var(--primary-start);
            border-radius: 50%;
            animation: ticket-spin 0.8s linear infinite;
        }
        
        @keyframes ticket-spin {
            to { transform: rotate(360deg); }
        }
        
        /* ==================== 移动端工单详情优化 ==================== */
        @media (max-width: 768px) {
            .modal-dialog {
                max-width: 95% !important;
                margin: var(--spacing-sm);
            }

            .modal-body {
                padding: var(--spacing-md);
            }

            .ticket-detail-table {
                font-size: 13px;
            }

            .ticket-detail-table td {
                padding: 8px 10px;
            }

            .ticket-detail-table td:first-child {
                width: 80px;
            }

            .ticket-section-content {
                font-size: 13px;
            }

            .ticket-reply-actions .btn {
                font-size: 12px;
                padding: var(--spacing-sm) var(--spacing-md);
            }
        }
        
        /* 关闭按钮样式 */
        .btn-close {
            width: 32px;
            height: 32px;
            padding: 0;
            background: transparent;
            border: none;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all var(--transition-base);
        }
        
        .btn-close:hover {
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        
        .btn-close::before {
            content: '×';
            line-height: 1;
        }
        
        /* ==================== 工单表格横向滚动 ==================== */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>
<body>

    <!-- 顶部导航栏 -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">
                <i class="fas fa-rocket"></i>
                <span>爽歪歪天河版</span>
            </div>

            <div class="navbar-actions">
                <button class="icon-btn" onclick="showAnnouncementBell()" title="查看公告" id="bellBtn">
                    <i class="fas fa-bell"></i>
                </button>

                <div class="balance-badge" onclick="loadAgentBalance()">
                    <i class="fas fa-wallet"></i>
                    <span class="balance-amount" id="navBalance">--</span>
                    <span>元</span>
                </div>

                <button class="icon-btn" onclick="toggleTheme()" title="切换主题">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="main-container">

        <!-- Hero区域 - 统计信息 -->
        <section class="hero-section">
            <div class="hero-content">
                <h1 class="hero-title">欢迎回来！</h1>
                <p class="hero-subtitle">用本站余额为源台客户 Key 充值</p>

                <div class="hero-flip-wrapper">
                    <div class="hero-flip-inner" id="heroFlipCard">
                        <!-- 正面：统计数据 -->
                        <div class="hero-flip-front">
                            <div class="stats-row" id="heroStats">
                                <div class="stat-card">
                                    <div class="stat-label">账户余额</div>
                                    <div class="stat-value" id="heroBalance">--</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">开户项目</div>
                                    <div class="stat-value" id="heroProjects">--</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">兑换费率</div>
                                    <div class="stat-value" id="heroExchangeRate">--</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">累计充值</div>
                                    <div class="stat-value" id="heroTotalCharge">--</div>
                                </div>
                            </div>
                        </div>

                        <!-- 背面：公告 -->
                        <div class="hero-flip-back" id="heroAnnouncement" onclick="lockAnnouncement()">
                            <div class="announcement-panel">
                                <div class="ann-icon">📢</div>
                                <div class="ann-title">系统公告</div>
                                <div class="ann-content" id="announcementText">暂无公告</div>
                                <div class="ann-hint">点击保持显示</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 标签页导航 -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('myKeys')">
                    <i class="fas fa-key"></i>
                    <span>客户 Key</span>
                </button>
                <button class="tab-btn" onclick="switchTab('integration')">
                    <i class="fas fa-plug"></i>
                    <span>对接中心</span>
                </button>
                <button class="tab-btn" onclick="switchTab('exchange')">
                    <i class="fas fa-exchange-alt"></i>
                    <span>我的账本</span>
                </button>
                <button class="tab-btn" id="ticketTabBtn" onclick="switchTab('tickets')">
                    <i class="fas fa-ticket-alt"></i>
                    <span>客户工单</span>
                </button>
                <?php if ($is_admin): ?>
                <button class="tab-btn" onclick="switchTab('projects')">
                    <i class="fas fa-project-diagram"></i>
                    <span>项目管理</span>
                </button>
                <button class="tab-btn" onclick="switchTab('agents')">
                    <i class="fas fa-database"></i>
                    <span>系统管理</span>
                </button>
                <button class="tab-btn" onclick="switchTab('settings')">
                    <i class="fas fa-cog"></i>
                    <span>系统配置</span>
                </button>
                <button class="tab-btn" onclick="switchTab('diagnostic')">
                    <i class="fas fa-stethoscope"></i>
                    <span>系统检测</span>
                </button>
                <button class="tab-btn" id="auditTabBtn" onclick="switchTab('audit')">
                    <i class="fas fa-chart-line"></i>
                    <span>客户审计</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- 标签页内容 -->

        <!-- 我的秘钥 -->
        <div id="tab-myKeys" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-key"></i>
                        源台客户 Key
                    </div>
                    <button class="btn btn-primary" onclick="quickRegister()">
                        <i class="fas fa-user-plus"></i>
                        一键开户
                    </button>
                </div>
                <div class="keys-container" style="position: relative; min-height: 200px;">
                    <div class="loader" id="keysLoader">
                        <div class="spinner"></div>
                    </div>
                    <div id="keysGrid" class="keys-grid">
                        <!-- 秘钥卡片将通过JavaScript动态生成 -->
                    </div>
                </div>
            </div>
        </div>

        <!-- 对接中心 -->
        <div id="tab-integration" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-plug"></i>
                        我的上游对接信息
                    </div>
                    <button class="btn btn-outline btn-sm" onclick="loadIntegrationCenter()">
                        <i class="fas fa-sync-alt"></i>
                        刷新
                    </button>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <div>把下面的对接 API 地址和对接密钥填到另一套 syytianheban 的系统配置中，即可使用当前账号余额作为上游余额，支持余额和工单继续向下套娃。</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">同系统对接 API 地址</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="openApiUrl" class="form-control" readonly>
                            <button class="btn btn-outline" onclick="copyField('openApiUrl')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">对接密钥</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="openApiKey" class="form-control" readonly>
                            <button class="btn btn-outline" onclick="copyField('openApiKey')"><i class="fas fa-copy"></i></button>
                            <button class="btn btn-danger" onclick="resetOpenApiKey()"><i class="fas fa-redo"></i> 重置</button>
                        </div>
                    </div>

                    <div class="alert alert-warning" style="margin-top:16px;">
                        <i class="fas fa-calculator"></i>
                        <div>账面说明：每次下游充值/提现都会记录项目公里数变动、平台余额变动、单价、用户费率、基准费率和完整计算公式。</div>
                    </div>
                    <div class="stats-row" style="margin-top:20px;">
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);">
                            <div class="stat-label">总调用</div>
                            <div class="stat-value" id="openApiTotalCalls">--</div>
                        </div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);">
                            <div class="stat-label">今日调用</div>
                            <div class="stat-value" id="openApiTodayCalls">--</div>
                        </div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);">
                            <div class="stat-label">下游客户</div>
                            <div class="stat-value" id="openApiCustomerCount">--</div>
                        </div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);">
                            <div class="stat-label">工单数</div>
                            <div class="stat-value" id="openApiTicketCount">--</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-book"></i>
                        余额账本
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>方向</th>
                                <th>项目</th>
                                <th>客户</th>
                                <th>公里数变动</th>
                                <th>平台变动</th>
                                <th>单价</th>
                                <th>计算公式</th>
                            </tr>
                        </thead>
                        <tbody id="openApiLedgerBody">
                            <tr><td colspan="7"><div class="empty-state"><div class="empty-title">暂无余额账本</div></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-line"></i>
                        调用明细
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>业务</th>
                                <th>鉴权</th>
                                <th>项目</th>
                                <th>客户</th>
                                <th>余额变化</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="openApiLogsBody">
                            <tr><td colspan="7"><div class="empty-state"><div class="empty-title">暂无调用记录</div></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 我的账本 -->
        <div id="tab-exchange" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-history"></i>
                        我的账本
                    </div>
                    <button class="btn btn-outline btn-sm" onclick="loadExchangeRecords()">
                        <i class="fas fa-sync-alt"></i>
                        刷新
                    </button>
                </div>
                <div class="table-container" style="position: relative; min-height: 200px;">
                    <div class="loader" id="recordsLoader">
                        <div class="spinner"></div>
                    </div>
                    <table class="table table-exchange">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>来源</th>
                                <th>项目</th>
                                <th>类型</th>
                                <th>平台变动</th>
                                <th>公里数变动</th>
                                <th>单价</th>
                                <th>余额变化/公式</th>
                            </tr>
                        </thead>
                        <tbody id="exchangeTableBody">
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                        <div class="empty-title">暂无记录</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 客户工单 -->
        <div id="tab-tickets" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-ticket-alt"></i>
                        我的工单
                    </div>
                    <button class="btn btn-primary" onclick="showCreateTicketModal()">
                        <i class="fas fa-plus"></i>
                        <span style="margin-left:6px;">提交工单</span>
                    </button>
                </div>
                <div class="card-body">
                    <!-- 筛选器 -->
                    <div class="tickets-filter-row">
                        <div class="tickets-filter-item">
                            <label class="tickets-filter-label">
                                <i class="fas fa-project-diagram"></i> 项目
                            </label>
                            <select id="ticketProjectFilter" class="form-control tickets-filter-select" onchange="loadTickets()">
                                <option value="">全部项目</option>
                            </select>
                        </div>
                        <div class="tickets-filter-item">
                            <label class="tickets-filter-label">
                                <i class="fas fa-filter"></i> 状态
                            </label>
                            <select id="ticketStatusFilter" class="form-control tickets-filter-select" onchange="loadTickets()">
                                <option value="">全部状态</option>
                                <option value="pending">待处理</option>
                                <option value="processing">处理中</option>
                                <option value="resolved">已处理</option>
                                <option value="closed">已关闭</option>
                            </select>
                        </div>
                        <div class="tickets-filter-item">
                            <label class="tickets-filter-label">
                                <i class="fas fa-tag"></i> 类型
                            </label>
                            <select id="ticketTypeFilter" class="form-control tickets-filter-select" onchange="loadTickets()">
                                <option value="">全部类型</option>
                                <option value="suggestion">开发建议</option>
                                <option value="bug">Bug反馈</option>
                                <option value="compensation">余额补偿</option>
                            </select>
                        </div>
                    </div>

                    <!-- 工单列表 -->
                    <div class="table-container" style="position: relative; min-height: 200px;">
                        <div class="loader" id="ticketsLoader">
                            <div class="spinner"></div>
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>工单编号</th>
                                    <th>项目</th>
                                    <th>类型</th>
                                    <th>标题</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="ticketsTableBody">
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                            <div class="empty-title">暂无工单</div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 项目管理（管理员） -->
        <?php if ($is_admin): ?>
        <div id="tab-projects" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-project-diagram"></i>
                        所有项目
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary btn-sm" onclick="addProjectManually()">
                            <i class="fas fa-plus"></i>
                            手动添加
                        </button>
                        <button class="btn btn-warning btn-sm" onclick="syncProjects()">
                            <i class="fas fa-sync"></i>
                            同步项目
                        </button>
                    </div>
                </div>
                <div class="projects-container" style="position: relative; min-height: 200px;">
                    <div class="loader" id="projectsLoader">
                        <div class="spinner"></div>
                    </div>
                    <div id="projectsGrid" class="projects-grid">
                        <!-- 项目卡片将通过JavaScript动态生成 -->
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 系统管理（管理员） -->
        <?php if ($is_admin): ?>
        <div id="tab-agents" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-database"></i>系统总览</div>
                    <button class="btn btn-primary btn-sm" onclick="loadSystemManagement()"><i class="fas fa-sync"></i>刷新</button>
                </div>
                <div class="card-body">
                    <div class="stats-row" id="systemOverviewStats">
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">代理数</div><div class="stat-value" id="sysAgentCount">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">密钥绑定</div><div class="stat-value" id="sysBindingCount">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">普通充值</div><div class="stat-value" id="sysExchangeCharge">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">对接扣费</div><div class="stat-value" id="sysOpenapiCharge">--</div></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-users"></i>
                        系统管理 / 代理账面总览
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="loadAgentList()">
                        <i class="fas fa-sync"></i>
                        刷新
                    </button>
                </div>
                <div class="card-body" style="padding: 15px;">
                    <div class="form-group" style="margin-bottom: 15px;">
                        <input type="text"
                               id="agentSearchInput"
                               class="form-control"
                               placeholder="搜索代理（输入UID或用户名）"
                               onkeydown="if(event.key==='Enter') loadAgentList()"
                               style="max-width: 400px;">
                    </div>
                </div>
                <div class="table-container" style="position: relative; min-height: 200px;">
                    <div class="loader" id="agentsLoader">
                        <div class="spinner"></div>
                    </div>
                    <table class="table table-fixed">
                        <thead>
                            <tr>
                                <th>UID</th>
                                <th>用户名</th>
                                <th>项目数</th>
                                <th>普通充值</th>
                                <th>普通提现</th>
                                <th>对接扣费</th>
                                <th>今日调用</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="agentsTableBody">
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-icon"><i class="fas fa-users"></i></div>
                                        <div class="empty-title">暂无代理数据</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div class="card-title"><i class="fas fa-book"></i>统一账本</div></div>
                <div class="card-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
                        <input type="number" id="ledgerAgentUid" class="form-control" placeholder="代理UID">
                        <input type="number" id="ledgerProjectId" class="form-control" placeholder="项目ID">
                        <select id="ledgerSource" class="form-control"><option value="">全部来源</option><option value="exchange">当前系统</option><option value="openapi">下游套娃</option></select>
                        <select id="ledgerDirection" class="form-control"><option value="">全部方向</option><option value="charge">充值扣费</option><option value="withdraw">提现返款</option></select>
                        <input type="date" id="ledgerDateStart" class="form-control">
                        <input type="date" id="ledgerDateEnd" class="form-control">
                        <input type="text" id="ledgerKeyword" class="form-control" placeholder="代理用户名/关键词/客户ID">
                        <button class="btn btn-primary" onclick="loadSystemLedger(1)"><i class="fas fa-search"></i>查询</button>
                    </div>
                    <div class="stats-row" style="margin-bottom:12px;">
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">累计充值</div><div class="stat-value" id="ledgerPlatformCharge">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">累计提现</div><div class="stat-value" id="ledgerPlatformWithdraw">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">净流入</div><div class="stat-value" id="ledgerNetPlatform">--</div></div>
                        <div class="stat-card" style="background:var(--bg-primary);color:var(--text-primary);"><div class="stat-label">记录数</div><div class="stat-value" id="ledgerRecordCount">--</div></div>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead><tr><th>时间</th><th>来源</th><th>代理</th><th>用户名</th><th>项目</th><th>客户</th><th>方向</th><th>公里数变动</th><th>平台变动</th><th>单价/公式</th></tr></thead>
                        <tbody id="systemLedgerBody"><tr><td colspan="10"><div class="empty-state"><div class="empty-title">暂无账本</div></div></td></tr></tbody>
                    </table>
                </div>
                <div class="card-body" style="display:flex;justify-content:center;gap:10px;">
                    <button class="btn btn-outline btn-sm" onclick="loadSystemLedger(systemLedgerPage-1)">上一页</button>
                    <span id="systemLedgerPager" style="padding:8px 12px;color:var(--text-secondary);">第 1 页</span>
                    <button class="btn btn-outline btn-sm" onclick="loadSystemLedger(systemLedgerPage+1)">下一页</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 系统配置（管理员） -->
        <?php if ($is_admin): ?>
        <div id="tab-settings" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-cog"></i>
                        系统配置
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">套娃模式</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <label class="toggle-switch">
                                <input type="checkbox" id="nestedModeEnabled" onchange="updateNestedModeLabels()">
                                <span class="toggle-slider"></span>
                            </label>
                            <span id="nestedModeLabel" style="color: var(--text-secondary);">关闭</span>
                        </div>
                        <div class="form-hint">开启后，下方填写另一套 syytianheban 提供的同系统对接 API 地址和对接密钥。</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="sourceApiUrlLabel">源平台API地址</label>
                        <input type="text" id="sourceApiUrl" class="form-control" placeholder="https://syyrun.com/api.php">
                        <div class="form-hint" id="sourceApiUrlHint">
                            用于开户、余额兑换和余额查询，默认 https://syyrun.com/api.php
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="sourceApiKeyLabel">源平台API密钥</label>
                        <input type="text" id="sourceApiKey" class="form-control" placeholder="请输入源平台API密钥">
                    </div>
                    <div class="form-group">
                        <label class="form-label">基准费率</label>
                        <input type="number" id="baseRate" class="form-control" placeholder="默认 0.2" step="0.01" value="0.2">
                        <div class="form-hint">
                            基准费率用于计算用户的实际单价，默认 0.2
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg" onclick="saveConfig()">
                        <i class="fas fa-save"></i>
                        保存配置
                    </button>
                </div>
            </div>

            <!-- 公告管理 -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-bullhorn"></i>
                        系统公告管理
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">公告内容</label>
                        <textarea id="announcementContent" class="form-control" rows="4" placeholder="输入系统公告内容，留空则不显示公告"></textarea>
                        <div class="form-hint">
                            <span id="announcementUpdatedAt" style="color: var(--text-secondary);"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">弹出模式</label>
                        <select id="announcementPopupMode" class="form-control">
                            <option value="once">仅在更新后第一次阅读（用户勾选后不再提醒）</option>
                            <option value="always">每次登录都弹出</option>
                        </select>
                        <div class="form-hint">
                            选择「仅一次」时，用户勾选「不再提醒」后不再弹出，直到下次更新公告。
                        </div>
                    </div>
                    <button class="btn btn-success btn-lg" onclick="saveAnnouncement()">
                        <i class="fas fa-save"></i>
                        保存公告
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 系统检测（管理员） -->
        <?php if ($is_admin): ?>
        <div id="tab-diagnostic" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-stethoscope"></i>
                        系统检测
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-primary btn-sm" onclick="runAllDiagnostics()">
                            <i class="fas fa-play"></i> 一键检测
                        </button>
                        <button class="btn btn-outline btn-sm" onclick="clearDiagnostics()">
                            <i class="fas fa-trash"></i> 清空
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="background:var(--bg-secondary);padding:10px 16px;border-radius:8px;margin-bottom:16px;">
                        <strong>登录状态：</strong><span id="diagUserStatus">检测中...</span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;">

                        <!-- 1. 查询余额 -->
                        <div class="card" style="margin:0;">
                            <div class="card-header" style="padding:12px 16px;">
                                <div class="card-title" style="font-size:0.9rem;">1. 查询余额</div>
                                <button class="btn btn-primary btn-sm" onclick="diagGetBalance()"><i class="fas fa-play"></i></button>
                            </div>
                            <div class="card-body" style="padding:12px 16px;">
                                <div id="diagBalanceResult" style="background:var(--bg-secondary);border-radius:6px;padding:10px;font-family:monospace;font-size:0.8rem;white-space:pre-wrap;min-height:40px;max-height:150px;overflow-y:auto;color:var(--text-secondary);"></div>
                            </div>
                        </div>

                        <!-- 2. 检查余额 -->
                        <div class="card" style="margin:0;">
                            <div class="card-header" style="padding:12px 16px;">
                                <div class="card-title" style="font-size:0.9rem;">2. 检查余额</div>
                                <button class="btn btn-primary btn-sm" onclick="diagCheckBalance()"><i class="fas fa-play"></i></button>
                            </div>
                            <div class="card-body" style="padding:12px 16px;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">需要金额</label>
                                    <input type="number" id="diagRequiredAmount" class="form-control" value="10" step="0.01">
                                </div>
                                <div id="diagCheckBalanceResult" style="background:var(--bg-secondary);border-radius:6px;padding:10px;font-family:monospace;font-size:0.8rem;white-space:pre-wrap;min-height:40px;max-height:150px;overflow-y:auto;color:var(--text-secondary);"></div>
                            </div>
                        </div>

                        <!-- 3. 修改余额 -->
                        <div class="card" style="margin:0;">
                            <div class="card-header" style="padding:12px 16px;">
                                <div class="card-title" style="font-size:0.9rem;">3. 修改余额</div>
                                <button class="btn btn-primary btn-sm" onclick="diagUpdateBalance()"><i class="fas fa-play"></i></button>
                            </div>
                            <div class="card-body" style="padding:12px 16px;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">操作类型</label>
                                    <select id="diagBalanceType" class="form-control">
                                        <option value="add">增加</option>
                                        <option value="sub">扣除</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">金额</label>
                                    <input type="number" id="diagBalanceAmount" class="form-control" value="0.01" step="0.01">
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">备注</label>
                                    <input type="text" id="diagBalanceRemark" class="form-control" value="系统检测">
                                </div>
                                <div id="diagUpdateBalanceResult" style="background:var(--bg-secondary);border-radius:6px;padding:10px;font-family:monospace;font-size:0.8rem;white-space:pre-wrap;min-height:40px;max-height:150px;overflow-y:auto;color:var(--text-secondary);"></div>
                            </div>
                        </div>

                        <!-- 4. 查询费率 -->
                        <div class="card" style="margin:0;">
                            <div class="card-header" style="padding:12px 16px;">
                                <div class="card-title" style="font-size:0.9rem;">4. 查询费率</div>
                                <button class="btn btn-primary btn-sm" onclick="diagGetRate()"><i class="fas fa-play"></i></button>
                            </div>
                            <div class="card-body" style="padding:12px 16px;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">课程ID（留空查全部）</label>
                                    <input type="number" id="diagCourseId" class="form-control" placeholder="留空查全部">
                                </div>
                                <div id="diagRateResult" style="background:var(--bg-secondary);border-radius:6px;padding:10px;font-family:monospace;font-size:0.8rem;white-space:pre-wrap;min-height:40px;max-height:150px;overflow-y:auto;color:var(--text-secondary);"></div>
                            </div>
                        </div>

                        <!-- 5. 写入日志 -->
                        <div class="card" style="margin:0;">
                            <div class="card-header" style="padding:12px 16px;">
                                <div class="card-title" style="font-size:0.9rem;">5. 写入日志</div>
                                <button class="btn btn-primary btn-sm" onclick="diagWriteLog()"><i class="fas fa-play"></i></button>
                            </div>
                            <div class="card-body" style="padding:12px 16px;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">日志类型</label>
                                    <input type="text" id="diagLogType" class="form-control" value="系统检测">
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">日志内容</label>
                                    <input type="text" id="diagLogText" class="form-control" value="这是一条系统检测日志">
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label" style="font-size:0.8rem;">金额变动</label>
                                    <input type="text" id="diagLogMoney" class="form-control" value="0">
                                </div>
                                <div id="diagLogResult" style="background:var(--bg-secondary);border-radius:6px;padding:10px;font-family:monospace;font-size:0.8rem;white-space:pre-wrap;min-height:40px;max-height:150px;overflow-y:auto;color:var(--text-secondary);"></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 客户审计（管理员） -->
        <?php if ($is_admin): ?>
        <div id="tab-audit" class="tab-content hidden">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-line"></i>
                        客户审计
                    </div>
                    <div class="btn-group" style="display:flex;align-items:center;gap:8px;">
                        <button class="btn btn-primary btn-sm" id="auditRunBtn" onclick="runAudit()">
                            <i class="fas fa-play"></i> 审计
                        </button>
                        <button class="btn btn-outline btn-sm" onclick="refreshAuditResults()">
                            <i class="fas fa-sync"></i> 刷新
                        </button>
                        <span id="auditProgressText" style="font-size:13px;color:var(--text-secondary);"></span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- 审计统计卡片 -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:20px;">
                        <div style="background:var(--bg-primary);padding:16px;border-radius:12px;text-align:center;">
                            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px;">总客户数</div>
                            <div id="auditTotal" style="font-size:24px;font-weight:700;color:var(--primary);">-</div>
                        </div>
                        <div style="background:var(--bg-primary);padding:16px;border-radius:12px;text-align:center;">
                            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px;">已完成</div>
                            <div id="auditCompleted" style="font-size:24px;font-weight:700;color:var(--success);">-</div>
                        </div>
                        <div style="background:var(--bg-primary);padding:16px;border-radius:12px;text-align:center;">
                            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px;">待处理</div>
                            <div id="auditPending" style="font-size:24px;font-weight:700;color:var(--warning);">-</div>
                        </div>
                        <div style="background:var(--bg-primary);padding:16px;border-radius:12px;text-align:center;">
                            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px;">有差异</div>
                            <div id="auditDiscrepancy" style="font-size:24px;font-weight:700;color:var(--danger);">-</div>
                        </div>
                    </div>

                    <!-- 筛选器 -->
                    <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                        <select id="auditTypeFilter" class="form-control" style="width:auto;" onchange="refreshAuditStats();refreshAuditResults();">
                            <option value="all">全部审计</option>
                            <option value="upstream">上游项目密钥审计</option>
                            <option value="downstream">下游套娃客户审计</option>
                        </select>
                        <select id="auditFilter" class="form-control" style="width:auto;" onchange="refreshAuditResults()">
                            <option value="-1">全部</option>
                            <option value="1">仅显示有差异</option>
                            <option value="0">仅显示无差异</option>
                        </select>
                        <select id="auditPageSize" class="form-control" style="width:auto;" onchange="refreshAuditResults()">
                            <option value="10">每页10条</option>
                            <option value="20" selected>每页20条</option>
                            <option value="50">每页50条</option>
                            <option value="100">每页100条</option>
                        </select>
                    </div>

                    <!-- 审计结果表格 -->
                    <div class="table-container">
                        <table class="table table-fixed">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>客户ID</th>
                                    <th>类型</th>
                                    <th>代理</th>
                                    <th>项目</th>
                                    <th>差异</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="auditResultsBody">
                                <tr>
                                    <td colspan="8" class="text-center">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 分页 -->
                    <div id="auditPagination" style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:16px;">
                    </div>
                </div>
            </div>

            <!-- 差异详情模态框 -->
            <div id="auditDetailModal" class="modal">
                <div class="modal-content" style="max-width:700px;">
                    <div class="modal-header">
                        <h3 class="modal-title">差异详情</h3>
                        <button class="modal-close" onclick="closeModal('auditDetailModal')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="auditDetailContent"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline" onclick="closeModal('auditDetailModal')">关闭</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <!-- Toast通知容器 -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- 模态框 -->

    <!-- 开户项目模态框 -->
    <div id="bindProjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">生成源台客户 Key</h3>
                <button class="modal-close" onclick="closeModal('bindProjectModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">选择项目</label>
                    <select id="bindProjectId" class="form-control">
                        <option value="">加载中...</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('bindProjectModal')">取消</button>
                <button class="btn btn-primary" onclick="bindProject()">确认绑定</button>
            </div>
        </div>
    </div>

    <!-- 充值模态框 -->
    <div id="chargeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">充值到项目</h3>
                <button class="modal-close" onclick="closeModal('chargeModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chargeProjectId">
                <input type="hidden" id="chargeRate">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <div style="margin-bottom: 4px;">
                            项目：<strong id="chargeProjectName"></strong>
                        </div>
                        <div>
                            单价：<strong id="chargeExchangeRate"></strong> 元/km
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">充值公里数（km）</label>
                    <input type="number" id="chargeAmount" class="form-control" placeholder="0.0000" step="0.0001" min="0.0001">
                    <div class="form-hint">
                        预计扣除：<strong style="color: var(--danger);" id="chargeProjectAmount">0.00</strong> 元
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('chargeModal')">取消</button>
                <button class="btn btn-success" onclick="confirmCharge()">
                    <i class="fas fa-check"></i>
                    确认充值
                </button>
            </div>
        </div>
    </div>

    <!-- 提现模态框 -->
    <div id="withdrawModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">从项目提现</h3>
                <button class="modal-close" onclick="closeModal('withdrawModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="withdrawProjectId">
                <input type="hidden" id="withdrawRate">

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <div style="margin-bottom: 4px;">
                            项目余额：<strong id="withdrawCurrentBalance"></strong> km
                        </div>
                        <div>
                            单价：<strong id="withdrawExchangeRate"></strong> 元/km
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">提现公里数（km）</label>
                    <input type="number" id="withdrawAmount" class="form-control" placeholder="0.0000" step="0.0001" min="0.0001">
                    <div class="form-hint">
                        预计到账：<strong style="color: var(--warning);" id="withdrawPlatformAmount">0.00</strong> 元
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('withdrawModal')">取消</button>
                <button class="btn btn-warning" onclick="confirmWithdraw()">
                    <i class="fas fa-check"></i>
                    确认提现
                </button>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- 编辑项目模态框 -->
    <div id="editProjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">编辑项目</h3>
                <button class="modal-close" onclick="closeModal('editProjectModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editProjectId">
                <div class="form-group">
                    <label class="form-label">项目名称</label>
                    <input type="text" id="editProjectName" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">爽歪歪源项目ID</label>
                    <input type="number" id="editProjectSourceId" class="form-control" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">项目访问地址</label>
                    <input type="text" id="editProjectRedirectUrl" class="form-control" placeholder="https://项目地址/">
                </div>
                <div class="form-group">
                    <label class="form-label">单价（元/km）</label>
                    <input type="number" id="editProjectPrice" class="form-control" step="0.01" placeholder="1">
                    <div class="form-hint">
                        标准用户充值 1 km 需扣多少本站余额，实际单价 = 单价 × 用户费率 / 基准费率
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">允许充值</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="editProjectAllowCharge" checked>
                            <span class="toggle-slider"></span>
                        </label>
                        <span id="editProjectAllowChargeLabel" style="color: var(--success);">允许</span>
                    </div>
                    <div class="form-hint">
                        禁止后用户无法向该项目充值
                    </div>
                </div>
                <style>
                .toggle-switch {
                    position: relative;
                    display: inline-block;
                    width: 50px;
                    height: 26px;
                }
                .toggle-switch input {
                    opacity: 0;
                    width: 0;
                    height: 0;
                }
                .toggle-slider {
                    position: absolute;
                    cursor: pointer;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: var(--danger);
                    transition: .4s;
                    border-radius: 26px;
                }
                .toggle-slider:before {
                    position: absolute;
                    content: "";
                    height: 20px;
                    width: 20px;
                    left: 3px;
                    bottom: 3px;
                    background-color: white;
                    transition: .4s;
                    border-radius: 50%;
                }
                .toggle-switch input:checked + .toggle-slider {
                    background-color: var(--success);
                }
                .toggle-switch input:checked + .toggle-slider:before {
                    transform: translateX(24px);
                }
                </style>
                <script>
                // 监听开关变化
                document.addEventListener('DOMContentLoaded', function() {
                    const allowChargeToggle = document.getElementById('editProjectAllowCharge');
                    const allowChargeLabel = document.getElementById('editProjectAllowChargeLabel');
                    if (allowChargeToggle && allowChargeLabel) {
                        allowChargeToggle.addEventListener('change', function() {
                            if (this.checked) {
                                allowChargeLabel.textContent = '允许';
                                allowChargeLabel.style.color = 'var(--success)';
                            } else {
                                allowChargeLabel.textContent = '禁止';
                                allowChargeLabel.style.color = 'var(--danger)';
                            }
                        });
                    }

                    const statusToggle = document.getElementById('editProjectStatus');
                    const statusLabel = document.getElementById('editProjectStatusLabel');
                    if (statusToggle && statusLabel) {
                        statusToggle.addEventListener('change', function() {
                            if (this.checked) {
                                statusLabel.textContent = '启用';
                                statusLabel.style.color = 'var(--success)';
                            } else {
                                statusLabel.textContent = '禁用';
                                statusLabel.style.color = 'var(--danger)';
                            }
                        });
                    }
                });
                </script>
                <div class="form-group">
                    <label class="form-label">项目状态</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="editProjectStatus" checked>
                            <span class="toggle-slider"></span>
                        </label>
                        <span id="editProjectStatusLabel" style="color: var(--success);">启用</span>
                    </div>
                    <div class="form-hint">
                        禁用后用户无法看到该项目
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('editProjectModal')">取消</button>
                <button class="btn btn-primary" onclick="updateProject()">
                    <i class="fas fa-save"></i>
                    保存修改
                </button>
            </div>
        </div>
    </div>

    <!-- 代理详情模态框 -->
    <div id="agentDetailModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user"></i>
                    代理详情 - <span id="agentDetailUsername"></span>
                </h3>
                <button class="modal-close" onclick="closeModal('agentDetailModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="currentAgentUid">
                <h4 style="margin:0 0 12px;">代理绑定的上游项目密钥</h4>
                <div class="table-container" style="max-height: 360px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>项目名称</th>
                                <th>API密钥</th>
                                <th>充值/提现</th>
                                <th>创建时间</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="agentProjectsBody">
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <div class="empty-icon"><i class="fas fa-key"></i></div>
                                        <div class="empty-title">暂无源台客户 Key</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h4 style="margin:20px 0 12px;">该代理对接密钥下游客户</h4>
                <div class="table-container" style="max-height: 360px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>客户ID</th>
                                <th>项目</th>
                                <th>客户密钥</th>
                                <th>公里余额</th>
                                <th>累计扣费/返款</th>
                                <th>创建时间</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="agentOpenApiCustomersBody">
                            <tr><td colspan="8"><div class="empty-state"><div class="empty-title">暂无下游客户</div></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 充值日志模态框 -->
    <div id="exchangeLogsModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-list-alt"></i>
                    充值日志 - <span id="logsProjectName"></span>
                </h3>
                <button class="modal-close" onclick="closeModal('exchangeLogsModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-container" style="max-height: 500px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>类型</th>
                                <th>平台金额</th>
                                <th>项目金额</th>
                                <th>比例</th>
                                <th>余额变化</th>
                            </tr>
                        </thead>
                        <tbody id="exchangeLogsBody">
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                        <div class="empty-title">暂无充值记录</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 公告弹窗 -->
    <div id="announcementModal" class="announcement-modal" onclick="handleAnnModalBgClick(event)">
        <div class="announcement-modal-box">
            <div class="ann-modal-banner">
                <div class="ann-modal-icon-wrap">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="ann-modal-banner-text">
                    <div class="ann-modal-tag">Notice</div>
                    <div class="ann-modal-title">系统公告</div>
                </div>
            </div>
            <div class="ann-modal-body">
                <div class="ann-modal-content" id="annModalContent">加载中...</div>
                <div class="ann-modal-divider"></div>
            </div>
            <div class="ann-modal-footer">
                <label class="ann-modal-check" id="annModalCheckLabel" onclick="toggleAnnCheck()">
                    <input type="checkbox" id="annModalNoMore">
                    <div class="ann-modal-check-box" id="annModalCheckBox"></div>
                    <span id="annModalCheckText">不再提醒此条公告</span>
                </label>
                <button class="ann-modal-close-btn" onclick="closeAnnouncementModal()">
                    <i class="fas fa-check"></i>
                    我知道了
                </button>
            </div>
            <div style="padding: 0 28px 14px; text-align:right;">
                <span class="ann-modal-time" id="annModalTime"></span>
            </div>
        </div>
    </div>

    <!-- iframe容器 -->
    <div id="iframe-container">
        <iframe id="content-iframe"></iframe>
        <button id="iframeCloseBtn" class="iframe-close-btn" onclick="closeIframe()">
            <i class="fas fa-home"></i>
        </button>
    </div>

    <!-- JavaScript -->
    <script>
        // 全局状态
        let currentProjectKeys = [];
        let allProjects = [];
        let userExchangeRate = 1.0; // 用户兑换费率
        let userBaseRate = 0.2; // 系统基准费率

        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();

            // 直接加载数据，不自动初始化项目（避免每次进页面都请求源台拖慢速度）
            loadAgentBalance();
            loadExchangeStats();
            loadOpenApiKey();

            // 延迟加载次要数据
            setTimeout(() => {
                loadExchangeRecords();
                loadAnnouncement();
                <?php if ($is_admin): ?>
                loadProjects();
                loadAgentList();
                loadConfig();
                refreshAuditStats();
                <?php endif; ?>
            }, 100);

            // 实时计算 - 充值
            document.getElementById('chargeAmount')?.addEventListener('input', function() {
                const coins = parseFloat(this.value) || 0;
                const rate = parseFloat(document.getElementById('chargeRate').value) || 1;
                document.getElementById('chargeProjectAmount').textContent = (coins * rate).toFixed(4);
            });

            // 实时计算 - 提现
            document.getElementById('withdrawAmount')?.addEventListener('input', function() {
                const coins = parseFloat(this.value) || 0;
                const rate = parseFloat(document.getElementById('withdrawRate').value) || 1;
                document.getElementById('withdrawPlatformAmount').textContent = (coins * rate).toFixed(4);
            });
        });

        // ==================== UI 工具函数 ====================

        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.body.setAttribute('data-theme', 'dark');
                document.getElementById('themeIcon').className = 'fas fa-sun';
            } else {
                document.getElementById('themeIcon').className = 'fas fa-moon';
            }
        }

        function toggleTheme() {
            const current = document.body.getAttribute('data-theme');
            const newTheme = current === 'dark' ? 'light' : 'dark';

            document.body.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);

            document.getElementById('themeIcon').className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            toast.innerHTML = `
                <i class="fas ${icons[type] || icons.info}"></i>
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        function showLoader(elementId) {
            const loader = document.getElementById(elementId);
            if (loader) loader.classList.add('show');
        }

        function hideLoader(elementId) {
            const loader = document.getElementById(elementId);
            if (loader) loader.classList.remove('show');
        }

        function switchTab(tabId, evt = null) {
            // 隐藏所有标签页
            document.querySelectorAll('.tab-content').forEach(el => {
                el.classList.remove('active');
                el.classList.add('hidden');
            });

            // 取消所有按钮激活状态
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

            // 显示目标标签页
            const targetTab = document.getElementById('tab-' + tabId);
            if (targetTab) {
                targetTab.classList.add('active');
                targetTab.classList.remove('hidden');
            }

            // 激活对应按钮
            const trigger = evt?.target || window.event?.target;
            const btn = trigger?.closest?.('.tab-btn') || document.querySelector(`.tab-btn[onclick*="'${tabId}'"]`);
            btn?.classList.add('active');

            // 加载对应数据
            if (tabId === 'myKeys') loadMyProjectKeys();
            else if (tabId === 'integration') loadIntegrationCenter();
            else if (tabId === 'exchange') loadExchangeRecords();
            else if (tabId === 'tickets') loadTickets();
            else if (tabId === 'projects') loadProjects();
            else if (tabId === 'agents') loadSystemManagement();
            else if (tabId === 'diagnostic') diagCheckUserStatus();
            <?php if ($is_admin): ?>
            else if (tabId === 'settings') { loadConfig(); loadAnnouncementForEdit(); }
            else if (tabId === 'audit') {
                // 延迟加载，确保DOM已经渲染
                setTimeout(() => {
                    refreshAuditStats();
                    refreshAuditResults();
                }, 50);
            }
            <?php endif; ?>
        }

        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show'); // 移除show类来隐藏模态框
                document.body.style.overflow = ''; // 恢复页面滚动
            }
        }
        // 点击背景关闭模态框
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        });

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('已复制到剪贴板');
            }).catch(() => {
                showToast('复制失败', 'error');
            });
        }

        function copyField(id) {
            const el = document.getElementById(id);
            if (el) copyToClipboard(el.value || el.textContent || '');
        }

        function loadOpenApiKey() {
            return fetch('api.php?action=getMyOpenApiKey')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('openApiUrl').value = data.data.api_url || '';
                        document.getElementById('openApiKey').value = data.data.api_key || '';
                    }
                    return data;
                });
        }

        function loadOpenApiUsageStats() {
            return fetch('api.php?action=getOpenApiUsageStats&limit=30')
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return data;
                    const s = data.data;
                    document.getElementById('openApiTotalCalls').textContent = s.total_calls || 0;
                    document.getElementById('openApiTodayCalls').textContent = s.today_calls || 0;
                    document.getElementById('openApiCustomerCount').textContent = s.customer_count || 0;
                    document.getElementById('openApiTicketCount').textContent = s.ticket_count || 0;
                    const ledgerBody = document.getElementById('openApiLedgerBody');
                    const ledger = s.recent_ledger || [];
                    if (!ledger.length) {
                        ledgerBody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-title">暂无余额账本</div></div></td></tr>';
                    } else {
                        ledgerBody.innerHTML = ledger.map(row => `
                            <tr>
                                <td>${row.created_at || '-'}</td>
                                <td><span class="badge ${row.direction === 'charge' ? 'badge-danger' : 'badge-success'}">${row.direction === 'charge' ? '充值扣费' : '提现返款'}</span></td>
                                <td>${row.project_name || row.project_id || '-'}</td>
                                <td>${row.customer_id || '-'}</td>
                                <td>${parseFloat(row.project_amount || 0).toFixed(4)} km<br><small>${parseFloat(row.project_balance_before || 0).toFixed(4)} → ${parseFloat(row.project_balance_after || 0).toFixed(4)} km</small></td>
                                <td>${parseFloat(row.platform_amount || 0).toFixed(2)} 元<br><small>${parseFloat(row.platform_balance_before || 0).toFixed(2)} → ${parseFloat(row.platform_balance_after || 0).toFixed(2)}</small></td>
                                <td>${parseFloat(row.actual_price || 0).toFixed(4)} 元/km<br><small>${row.project_price}×${row.user_rate}/${row.base_rate}</small></td>
                                <td>${row.formula || '-'}</td>
                            </tr>
                        `).join('');
                    }

                    const tbody = document.getElementById('openApiLogsBody');
                    const logs = s.recent_logs || [];
                    if (!logs.length) {
                        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-title">暂无调用记录</div></div></td></tr>';
                    } else {
                        const ledgerByCall = {};
                        (s.recent_ledger || []).forEach(row => {
                            if (row.action) ledgerByCall[row.action + ':' + row.customer_id + ':' + row.project_id] = row;
                        });
                        const actionNames = {
                            getUserApiKeys: '同步项目',
                            generateCustomer: '开户',
                            adjustCustomerBalance: '余额调整',
                            getCustomerInfo: '查询余额',
                            submitCustomerTicket: '提交工单',
                            getCustomerTickets: '查询工单',
                            getCustomerTicketDetail: '工单详情',
                            replyCustomerTicket: '回复工单',
                            reviewCompensationTicket: '审核补偿'
                        };
                        tbody.innerHTML = logs.map(log => {
                            const ledger = ledgerByCall[log.action + ':' + log.customer_id + ':' + log.project_id];
                            const balanceText = ledger
                                ? `${parseFloat(ledger.project_amount || 0).toFixed(4)} km / ${parseFloat(ledger.platform_amount || 0).toFixed(4)} 元<br><small>${parseFloat(ledger.project_balance_before || 0).toFixed(4)} → ${parseFloat(ledger.project_balance_after || 0).toFixed(4)} km</small>`
                                : (log.action === 'getCustomerInfo' ? '查询余额' : '-');
                            return `
                            <tr>
                                <td>${log.created_at || '-'}</td>
                                <td><span class="badge badge-info">${actionNames[log.action] || log.action || '-'}</span><br><small>${log.action || ''}</small></td>
                                <td>${log.auth_type === 'customer' ? '客户密钥' : '用户密钥'}</td>
                                <td>${log.project_id || '-'}</td>
                                <td>${log.customer_id || '-'}</td>
                                <td>${balanceText}</td>
                                <td>${log.ip || '-'}</td>
                            </tr>`;
                        }).join('');
                    }
                    return data;
                });
        }

        function loadIntegrationCenter() {
            loadOpenApiKey();
            loadOpenApiUsageStats();
        }

        function resetOpenApiKey() {
            if (!confirm('确定重置对接密钥吗？重置后旧密钥将无法继续对接。')) return;
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=resetMyOpenApiKey'
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('openApiUrl').value = data.data.api_url || '';
                    document.getElementById('openApiKey').value = data.data.api_key || '';
                    showToast(data.message || '已重置');
                } else {
                    showToast(data.message || '重置失败', 'error');
                }
            });
        }

        // ==================== 核心业务逻辑 ====================

        /**
         * 自动初始化项目 - 静默绑定所有启用的项目
         */
        function autoInitProjects() {
            return new Promise((resolve, reject) => {
                console.log('[自动初始化] 开始执行...');
                fetch('api.php?action=autoInitProjects')
                    .then(r => r.json())
                    .then(data => {
                        console.log('[自动初始化] 返回数据:', data);
                        if (data.status === 'success') {
                            const stats = data.data;
                            console.log(`[自动初始化] 总启用项目:${stats.total_enabled}, 已绑定:${stats.already_bound}, 新绑定:${stats.newly_bound}, 失败:${stats.failed}`);

                            // 显示详细结果
                            if (stats.results && stats.results.length > 0) {
                                console.group('[自动初始化] 详细结果:');
                                stats.results.forEach(result => {
                                    if (result.status === 'success') {
                                        console.log(`✅ ${result.project_name} - 成功`);
                                    } else {
                                        console.error(`❌ ${result.project_name} - 失败: ${result.error}`);
                                    }
                                });
                                console.groupEnd();
                            }

                            // 显示提示
                            if (stats.newly_bound > 0 && stats.failed === 0) {
                                showToast(`已自动初始化 ${stats.newly_bound} 个项目`, 'success');
                            } else if (stats.newly_bound > 0 && stats.failed > 0) {
                                showToast(`已初始化 ${stats.newly_bound} 个项目，${stats.failed} 个失败`, 'warning');
                            } else if (stats.failed > 0) {
                                showToast(`${stats.failed} 个项目初始化失败`, 'error');
                                // 显示失败项目列表
                                const failedProjects = stats.results.filter(r => r.status === 'failed');
                                failedProjects.forEach(p => {
                                    console.error(`失败项目: ${p.project_name} - ${p.error}`);
                                });
                            } else if (stats.already_bound > 0) {
                                console.log('[自动初始化] 所有启用项目均已绑定，无需操作');
                            }
                            resolve(data);
                        } else {
                            // 初始化失败时不显示错误，静默处理
                            console.warn('[自动初始化] 失败:', data.message);
                            resolve(data); // 用resolve而不是reject，让流程继续
                        }
                    })
                    .catch(err => {
                        console.error('[自动初始化] 网络错误:', err);
                        resolve(); // 静默处理错误
                    });
            });
        }

        /**
         * 一键开户 - 自动初始化所有启用的项目
         */
        function quickRegister() {
            // 显示加载提示
            showToast('正在自动开户项目...', 'info');

            autoInitProjects().then(() => {
                // 初始化完成后刷新项目列表
                loadMyProjectKeys();
                loadExchangeStats();
            });
        }

        function loadAgentBalance() {
            fetch('api.php?action=getUserInfo')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const balance = parseFloat(data.balance || 0).toFixed(2);
                        document.getElementById('navBalance').textContent = balance;
                        document.getElementById('heroBalance').textContent = balance;

                        // 更新兑换费率
                        if (data.exchange_rate !== undefined) {
                            userExchangeRate = parseFloat(data.exchange_rate);
                            document.getElementById('heroExchangeRate').textContent = userExchangeRate.toFixed(2);
                        } else {
                            userExchangeRate = 1.0;
                            document.getElementById('heroExchangeRate').textContent = '1.00';
                        }
                        if (data.base_rate !== undefined) {
                            userBaseRate = parseFloat(data.base_rate);
                        } else {
                            userBaseRate = 0.2;
                        }
                        applyBusinessFeatureVisibility(data.nested_mode_enabled == 1);
                        // 费率已就绪，再渲染项目秘钥
                        loadMyProjectKeys();
                    }
                });
        }

        function loadMyProjectKeys() {
            showLoader('keysLoader');
            fetch('api.php?action=getMyProjectKeys')
                .then(r => r.json())
                .then(data => {
                    hideLoader('keysLoader');
                    if (data.status === 'success') {
                        currentProjectKeys = data.data;
                        renderProjectKeys(data.data);
                        updateHeroStats(data.data);
                        // 立即查询余额
                        loadProjectBalances();
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => hideLoader('keysLoader'));
        }

        function loadProjectBalances() {
            if (!currentProjectKeys || currentProjectKeys.length === 0) return;

            // 显示余额加载状态
            currentProjectKeys.forEach((key) => {
                const pid = parseInt(key.project_id);
                const balanceEl = document.querySelector(`[data-project-id="${pid}"] .key-balance-value`);
                if (balanceEl) {
                    balanceEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                }
            });

            // 一次请求批量查询所有项目余额
            fetch('api.php?action=getProjectKeyBalance')
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') {
                        currentProjectKeys.forEach((key) => {
                            const balanceEl = document.querySelector(`[data-project-id="${parseInt(key.project_id)}"] .key-balance-value`);
                            if (balanceEl) balanceEl.textContent = '查询失败';
                        });
                        return;
                    }
                    const balances = data.balances; // { project_id: balance }

                    currentProjectKeys.forEach((key, index) => {
                        const pid = parseInt(key.project_id);
                        if (balances[pid] !== undefined) {
                            currentProjectKeys[index].balance = balances[pid];
                            const balanceEl = document.querySelector(`[data-project-id="${pid}"] .key-balance-value`);
                            if (balanceEl) {
                                balanceEl.textContent = parseFloat(balances[pid]).toFixed(4) + ' km';
                            }
                        } else {
                            const balanceEl = document.querySelector(`[data-project-id="${pid}"] .key-balance-value`);
                            if (balanceEl) balanceEl.textContent = '未同步';
                        }
                    });
                })
                .catch(() => {
                    currentProjectKeys.forEach((key) => {
                        const pid = parseInt(key.project_id);
                        const balanceEl = document.querySelector(`[data-project-id="${pid}"] .key-balance-value`);
                        if (balanceEl) {
                            balanceEl.textContent = '查询失败';
                        }
                    });
                });
        }

        function renderProjectKeys(keys) {
            const grid = document.getElementById('keysGrid');

            if (keys.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-key"></i></div>
                        <div class="empty-title">暂无源台客户 Key</div>
                        <div class="empty-desc">点击"一键开户"自动绑定所有项目</div>
                        <button class="btn btn-primary" onclick="quickRegister()">
                            <i class="fas fa-user-plus"></i>
                            一键开户
                        </button>
                    </div>
                `;
                return;
            }

            grid.innerHTML = keys.map((key, index) => {
                // 计算实际单价 = 项目单价 × 用户费率 / 基准费率
                const projectPrice = parseFloat(key.price || 1);
                const baseRate = (typeof userBaseRate !== 'undefined' && userBaseRate > 0) ? userBaseRate : 0.2;
                const chargeRate = userExchangeRate > 0 ? (projectPrice * userExchangeRate / baseRate) : projectPrice;

                return `
                <article class="key-card" data-project-id="${key.project_id}">
                    <div class="key-card-header">
                        <h3 class="key-card-name">${escapeHtml(key.project_name || '未知项目')}</h3>
                    </div>

                    <div class="key-card-body">
                        <div class="key-balance-display">
                            <div class="key-balance-label">项目余额</div>
                            <div class="key-balance-value">${parseFloat(key.balance || 0).toFixed(4)} km</div>
                        </div>

                        <div class="key-data-row">
                            <span class="key-data-label">单价</span>
                            <span class="key-data-value rate">${parseFloat(chargeRate.toPrecision(4))}元/km</span>
                        </div>
                    </div>

                    <div class="key-card-actions">
                        <button class="btn btn-primary" onclick='openProjectByIndex(${index})' title="打开源台专属链接">
                            <i class="fas fa-external-link-alt"></i>
                        </button>
                        <button class="btn btn-outline" onclick='copyProjectLinkByIndex(${index})' title="复制源台专属链接">
                            <i class="fas fa-copy"></i>
                        </button>
                        ${key.allow_charge === 0 || key.allow_charge === '0' ?
                            `<button class="btn btn-secondary" onclick='showChargeDisabledMessage()' title="充值已禁用">
                                <i class="fas fa-ban"></i>
                            </button>` :
                            `<button class="btn btn-success" onclick='showChargeModal(${key.project_id}, "${escapeHtml(key.project_name)}", ${key.balance}, ${chargeRate})' title="充值">
                                <i class="fas fa-plus-circle"></i>
                            </button>`
                        }
                        <button class="btn btn-warning" onclick='showWithdrawModal(${key.project_id}, "${escapeHtml(key.project_name)}", ${key.balance}, ${chargeRate})' title="从项目提现">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                    </div>
                </article>
                `;
            }).join('');
        }

        function updateHeroStats(keys) {
            // 更新项目数量
            document.getElementById('heroProjects').textContent = keys.length;
            // 注意：兑换费率在 loadAgentBalance() 中更新
        }

        function loadExchangeRecords() {
            showLoader('recordsLoader');
            fetch('api.php?action=getMyLedger&limit=50')
                .then(r => r.json())
                .then(data => {
                    hideLoader('recordsLoader');
                    if (data.status === 'success') {
                        renderExchangeRecords(data.data);
                    }
                })
                .catch(() => hideLoader('recordsLoader'));
        }

        function renderExchangeRecords(records) {
            const tbody = document.getElementById('exchangeTableBody');

            if (!records || records.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-receipt"></i></div>
                                <div class="empty-title">暂无记录</div>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = records.map(r => {
                const isCharge = r.direction === 'charge';
                return `
                <tr>
                    <td data-label="时间"><span style="color: var(--text-secondary); font-size: 13px;">${r.created_at}</span></td>
                    <td data-label="来源"><span class="badge ${r.ledger_source === 'openapi' ? 'badge-info' : 'badge-success'}">${r.ledger_source === 'openapi' ? 'API调用' : '项目充值提现'}</span></td>
                    <td data-label="项目"><strong>${escapeHtml(r.project_name || '-')}</strong>${r.customer_id ? `<br><small>客户ID:${r.customer_id}</small>` : ''}</td>
                    <td data-label="类型"><span class="badge ${isCharge ? 'badge-success' : 'badge-warning'}"><i class="fas ${isCharge ? 'fa-arrow-down' : 'fa-arrow-up'}"></i>${isCharge ? '充值/扣费' : '提现/返款'}</span></td>
                    <td data-label="平台变动"><strong style="color:${parseFloat(r.platform_amount || 0) < 0 ? 'var(--danger)' : 'var(--success)'};">${parseFloat(r.platform_amount || 0).toFixed(2)} 元</strong></td>
                    <td data-label="公里数变动"><strong style="color:${parseFloat(r.project_amount || 0) > 0 ? 'var(--success)' : 'var(--danger)'};">${parseFloat(r.project_amount || 0).toFixed(4)} km</strong></td>
                    <td data-label="单价"><span class="badge badge-info">${parseFloat(r.actual_price || 0).toFixed(2)}</span></td>
                    <td data-label="余额变化/公式">${r.project_balance_after !== null ? `${parseFloat(r.project_balance_before || 0).toFixed(4)} → ${parseFloat(r.project_balance_after || 0).toFixed(4)}<br>` : ''}<small>${escapeHtml(r.formula || '')}</small></td>
                </tr>`;
            }).join('');
        }

        function loadExchangeStats() {
            fetch('api.php?action=getExchangeStats')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const stats = data.data;
                        document.getElementById('heroTotalCharge').textContent =
                            parseFloat(stats.total_charged || 0).toFixed(2);
                    }
                });
        }

        // ==================== 项目操作 ====================

        function openProjectByIndex(index) {
            const key = currentProjectKeys[index];
            if (!key) {
                showToast('项目数据不存在', 'error');
                return;
            }

            const redirectUrl = key.redirect_url || '';
            const customerLink = key.customer_link || '';
            // 访问真实业务系统优先使用 real_access_key（套娃模式下为透传的真实密钥）；
            // 兼容旧数据/非套娃场景，回退到 source_api_key
            const apiKey = key.real_access_key || key.source_api_key || '';
            const projectName = key.project_name || '未知项目';

            if (!customerLink && !redirectUrl) {
                showToast('源台未返回专属链接，该项目也未配置兜底访问地址', 'error');
                return;
            }

            const separator = redirectUrl.includes('?') ? '&' : '?';
            const projectUrl = customerLink || `${redirectUrl}${separator}key=${encodeURIComponent(apiKey)}`;

            // 这是源台真实业务页，直接打开专属链接。避免跨域 iframe 被
            // X-Frame-Options/CSP 拦截，也避免手机端页面尺寸被二次嵌套。
            const opened = window.open(projectUrl, '_blank');
            if (opened) opened.opener = null;
            else window.location.href = projectUrl;
            showToast(`已打开源台项目：${projectName}`);
        }

        function copyProjectLinkByIndex(index) {
            const key = currentProjectKeys[index];
            if (!key) {
                showToast('项目数据不存在', 'error');
                return;
            }
            const customerLink = key.customer_link || '';
            const redirectUrl = key.redirect_url || '';
            const apiKey = key.real_access_key || key.source_api_key || '';
            if (!customerLink && !redirectUrl) {
                showToast('源台未返回专属链接', 'error');
                return;
            }
            const separator = redirectUrl.includes('?') ? '&' : '?';
            const projectUrl = customerLink || `${redirectUrl}${separator}key=${encodeURIComponent(apiKey)}`;
            copyToClipboard(projectUrl);
            showToast('专属链接已复制');
        }

        function closeIframe() {
            const iframe = document.getElementById('content-iframe');
            const container = document.getElementById('iframe-container');

            container.classList.remove('active');
            iframe.src = '';
            document.body.style.overflow = '';
        }

        function initDraggableIframeCloseButton() {
            const btn = document.getElementById('iframeCloseBtn');
            if (!btn) return;

            let dragging = false;
            let moved = false;
            let startX = 0;
            let startY = 0;
            let startLeft = 0;
            let startTop = 0;

            btn.addEventListener('pointerdown', function(e) {
                dragging = true;
                moved = false;

                const rect = btn.getBoundingClientRect();
                startX = e.clientX;
                startY = e.clientY;
                startLeft = rect.left;
                startTop = rect.top;

                btn.classList.add('dragging');
                btn.setPointerCapture(e.pointerId);
            });

            btn.addEventListener('pointermove', function(e) {
                if (!dragging) return;

                const dx = e.clientX - startX;
                const dy = e.clientY - startY;

                if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                    moved = true;
                }

                const maxLeft = window.innerWidth - btn.offsetWidth;
                const maxTop = window.innerHeight - btn.offsetHeight;
                const nextLeft = Math.max(0, Math.min(maxLeft, startLeft + dx));
                const nextTop = Math.max(0, Math.min(maxTop, startTop + dy));

                btn.style.left = `${nextLeft}px`;
                btn.style.top = `${nextTop}px`;
                btn.style.right = 'auto';
                btn.style.bottom = 'auto';
            });

            btn.addEventListener('pointerup', function(e) {
                dragging = false;
                btn.classList.remove('dragging');
                if (btn.hasPointerCapture(e.pointerId)) {
                    btn.releasePointerCapture(e.pointerId);
                }
            });

            btn.addEventListener('pointercancel', function(e) {
                dragging = false;
                btn.classList.remove('dragging');
                if (btn.hasPointerCapture(e.pointerId)) {
                    btn.releasePointerCapture(e.pointerId);
                }
            });

            btn.addEventListener('click', function(e) {
                if (moved) {
                    e.preventDefault();
                    e.stopPropagation();
                    moved = false;
                }
            }, true);
        }

        initDraggableIframeCloseButton();

        function showBindProjectModal() {
            const select = document.getElementById('bindProjectId');
            select.innerHTML = '<option>加载中...</option>';
            showModal('bindProjectModal');

            fetch('api.php?action=getProjects')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.projects.length === 0) {
                            select.innerHTML = '<option value="">暂无可用项目</option>';
                        } else {
                            select.innerHTML = '<option value="">请选择项目</option>' +
                                data.projects.map(p =>
                                    `<option value="${p.id}">${p.name} (单价: ¥${p.price || '1'})</option>`
                                ).join('');
                        }
                    }
                });
        }

        function bindProject() {
            const projectId = document.getElementById('bindProjectId').value;
            if (!projectId) {
                showToast('请选择项目', 'error');
                return;
            }

            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=bindProjectKey&project_id=${projectId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('绑定成功');
                    closeModal('bindProjectModal');
                    loadMyProjectKeys();
                } else {
                    showToast(data.message, 'error');
                }
            });
        }

        function showChargeDisabledMessage() {
            showToast('该项目已禁止充值', 'error');
        }

        function showChargeModal(projectId, projectName, balance, rate) {
            // 检查项目是否允许充值
            const key = currentProjectKeys.find(k => k.project_id == projectId);
            if (key && (key.allow_charge === 0 || key.allow_charge === '0')) {
                showToast('该项目已禁止充值', 'error');
                return;
            }

            document.getElementById('chargeProjectId').value = projectId;
            document.getElementById('chargeRate').value = rate;
            document.getElementById('chargeProjectName').textContent = projectName;
            document.getElementById('chargeExchangeRate').textContent = parseFloat(parseFloat(rate).toPrecision(4));
            document.getElementById('chargeAmount').value = '';
            document.getElementById('chargeProjectAmount').textContent = '0.0000';
            showModal('chargeModal');
        }

        function confirmCharge() {
            const projectId = document.getElementById('chargeProjectId').value;
            const projectCoins = document.getElementById('chargeAmount').value;
            const rate = parseFloat(document.getElementById('chargeRate').value) || 1;

            if (!projectCoins || projectCoins <= 0) {
                showToast('请输入有效的充值公里数', 'error');
                return;
            }

            // 本站扣款仅用于界面预览，最终由后端按等级与单价重新计算。
            const platformAmount = (parseFloat(projectCoins) * rate).toFixed(4);

            // 获取按钮并设置加载状态
            const modal = document.getElementById('chargeModal');
            const btn = modal.querySelector('.btn-success');
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 充值中...';

            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=chargeToProject&project_id=${projectId}&project_amount=${encodeURIComponent(projectCoins)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('充值成功');
                    closeModal('chargeModal');
                    loadMyProjectKeys();
                    loadAgentBalance();
                    loadExchangeRecords();
                    loadExchangeStats();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => {
                showToast('充值失败，请重试', 'error');
            })
            .finally(() => {
                // 恢复按钮状态
                btn.disabled = false;
                btn.innerHTML = originalContent;
            });
        }

        function showWithdrawModal(projectId, projectName, balance, rate) {
            document.getElementById('withdrawProjectId').value = projectId;
            document.getElementById('withdrawRate').value = rate;
            document.getElementById('withdrawCurrentBalance').textContent = parseFloat(balance).toFixed(4);
            document.getElementById('withdrawExchangeRate').textContent = parseFloat(parseFloat(rate).toPrecision(4));
            document.getElementById('withdrawAmount').value = '';
            document.getElementById('withdrawPlatformAmount').textContent = '0.0000';
            showModal('withdrawModal');
        }

        function confirmWithdraw() {
            const projectId = document.getElementById('withdrawProjectId').value;
            const amount = document.getElementById('withdrawAmount').value;

            if (!amount || amount <= 0) {
                showToast('请输入有效的提现公里数', 'error');
                return;
            }

            // 获取按钮并设置加载状态
            const modal = document.getElementById('withdrawModal');
            const btn = modal.querySelector('.btn-warning');
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提现中...';

            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=withdrawFromProject&project_id=${projectId}&amount=${amount}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('提现成功');
                    closeModal('withdrawModal');
                    loadMyProjectKeys();
                    loadAgentBalance();
                    loadExchangeRecords();
                    loadExchangeStats();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => {
                showToast('提现失败，请重试', 'error');
            })
            .finally(() => {
                // 恢复按钮状态
                btn.disabled = false;
                btn.innerHTML = originalContent;
            });
        }

        // ==================== 管理员功能 ====================

        <?php if ($is_admin): ?>
        function loadProjects() {
            showLoader('projectsLoader');
            fetch('api.php?action=getProjects')
                .then(r => r.json())
                .then(data => {
                    hideLoader('projectsLoader');
                    if (data.status === 'success') {
                        allProjects = data.projects;
                        renderProjects(data.projects);
                    }
                })
                .catch(() => hideLoader('projectsLoader'));
        }

        function renderProjects(projects) {
            const grid = document.getElementById('projectsGrid');

            if (projects.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-project-diagram"></i></div>
                        <div class="empty-title">暂无项目</div>
                        <div class="empty-desc">点击上方"同步项目"按钮从源平台同步</div>
                    </div>
                `;
                return;
            }

            grid.innerHTML = projects.map(p => `
                <article class="project-card">
                    <div class="project-card-header">
                        <div class="project-card-main">
                            <h3 class="project-card-name">${p.name}</h3>
                            <span class="project-card-id">#${p.id}</span>
                        </div>
                        <span class="status-badge ${p.status == 1 ? 'active' : 'inactive'}">
                            ${p.status == 1 ? '启用' : '禁用'}
                        </span>
                    </div>

                    <div class="project-card-body">
                        <div class="project-data-group">
                            <label class="project-data-label">项目入口</label>
                            <div class="project-data-value" title="${p.redirect_url || '-'}">
                                ${p.redirect_url ? p.redirect_url.replace(/^https?:\/\//, '') : '-'}
                            </div>
                        </div>

                        <div class="project-data-grid">
                            <div class="project-data-group">
                                <label class="project-data-label">源项目ID</label>
                                <div class="project-data-value number">${p.source_project_id || '-'}</div>
                            </div>
                            <div class="project-data-group">
                                <label class="project-data-label">单价</label>
                                <div class="project-data-value number">¥${p.price || '1'}</div>
                            </div>
                        </div>
                    </div>

                    <div class="project-card-actions">
                        <button class="btn btn-primary btn-sm" onclick="showEditProjectModal(${p.id})">
                            <i class="fas fa-edit"></i>
                            编辑
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteProject(${p.id})">
                            <i class="fas fa-trash"></i>
                            删除
                        </button>
                    </div>
                </article>
            `).join('');
        }

        function addProjectManually() {
            const name = prompt('请输入项目名称');
            if (!name) return;
            const sourceId = prompt('请输入爽歪歪源项目ID');
            if (!sourceId || !/^\d+$/.test(sourceId) || Number(sourceId) <= 0) {
                showToast('源项目ID格式不正确', 'error');
                return;
            }
            const redirectUrl = prompt('请输入项目访问地址（没有可留空）', '') ?? '';
            const price = prompt('请输入标准用户每单位余额的价格', '1');
            if (!price || Number(price) <= 0) {
                showToast('项目单价格式不正确', 'error');
                return;
            }
            const body = new URLSearchParams({action:'addProject', name, source_project_id:sourceId, redirect_url:redirectUrl, price});
            fetch('api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.status === 'success' ? 'success' : 'error');
                    if (data.status === 'success') loadProjects();
                })
                .catch(() => showToast('添加项目失败', 'error'));
        }

        function showEditProjectModal(projectId) {
            const project = allProjects.find(p => p.id == projectId);
            if (!project) return;

            document.getElementById('editProjectId').value = project.id;
            document.getElementById('editProjectName').value = project.name;
            document.getElementById('editProjectPrice').value = project.price || 1;
            document.getElementById('editProjectSourceId').value = project.source_project_id || '';
            document.getElementById('editProjectRedirectUrl').value = project.redirect_url || '';

            // 设置充值开关状态
            const allowChargeToggle = document.getElementById('editProjectAllowCharge');
            const allowChargeLabel = document.getElementById('editProjectAllowChargeLabel');
            const allowChargeValue = project.allow_charge ?? 1;
            allowChargeToggle.checked = (allowChargeValue == 1);
            if (allowChargeValue == 1) {
                allowChargeLabel.textContent = '允许';
                allowChargeLabel.style.color = 'var(--success)';
            } else {
                allowChargeLabel.textContent = '禁止';
                allowChargeLabel.style.color = 'var(--danger)';
            }

            // 设置状态开关
            const statusToggle = document.getElementById('editProjectStatus');
            const statusLabel = document.getElementById('editProjectStatusLabel');
            const statusValue = project.status ?? 1;
            statusToggle.checked = (statusValue == 1);
            if (statusValue == 1) {
                statusLabel.textContent = '启用';
                statusLabel.style.color = 'var(--success)';
            } else {
                statusLabel.textContent = '禁用';
                statusLabel.style.color = 'var(--danger)';
            }

            showModal('editProjectModal');
        }

        function updateProject() {
            const id = document.getElementById('editProjectId').value;
            const name = document.getElementById('editProjectName').value;
            const price = document.getElementById('editProjectPrice').value;
            const source_project_id = document.getElementById('editProjectSourceId').value;
            const redirect_url = document.getElementById('editProjectRedirectUrl').value;
            const allow_charge = document.getElementById('editProjectAllowCharge').checked ? 1 : 0;
            const status = document.getElementById('editProjectStatus').checked ? 1 : 0;

            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=updateProject&project_id=${id}&name=${encodeURIComponent(name)}&source_project_id=${source_project_id}&redirect_url=${encodeURIComponent(redirect_url)}&price=${price}&allow_charge=${allow_charge}&status=${status}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('更新成功');
                    closeModal('editProjectModal');
                    loadProjects();
                } else {
                    showToast(data.message, 'error');
                }
            });
        }

        function deleteProject(id) {
            if (!confirm('确定删除此项目吗？此操作不可撤销！')) return;

            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=deleteProject&project_id=${id}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('删除成功');
                    loadProjects();
                } else {
                    showToast(data.message, 'error');
                }
            });
        }

        function syncProjects() {
            if (!confirm('确定同步项目吗？这将花费一些时间。')) return;

            showLoader('projectsLoader');
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=syncProjects'
            })
            .then(r => r.json())
            .then(data => {
                hideLoader('projectsLoader');
                if (data.status === 'success') {
                    showToast(data.message);
                    loadProjects();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => hideLoader('projectsLoader'));
        }

        function loadConfig() {
            fetch('api.php?action=getConfig')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const apiUrlConfig = data.configs.find(c => c.config_key === 'source_api_url');
                        document.getElementById('sourceApiUrl').value = apiUrlConfig ? apiUrlConfig.config_value : 'https://syyrun.com/api.php';
                        const apiKeyConfig = data.configs.find(c => c.config_key === 'source_api_key');
                        if (apiKeyConfig) {
                            document.getElementById('sourceApiKey').value = apiKeyConfig.config_value;
                        }
                        const nestedConfig = data.configs.find(c => c.config_key === 'nested_mode_enabled');
                        document.getElementById('nestedModeEnabled').checked = nestedConfig && nestedConfig.config_value == '1';
                        updateNestedModeLabels();
                        applyAuditTabVisibility(nestedConfig && nestedConfig.config_value == '1');
                        const baseRateConfig = data.configs.find(c => c.config_key === 'base_rate');
                        if (baseRateConfig) {
                            document.getElementById('baseRate').value = baseRateConfig.config_value;
                        }
                    }
                });
        }

        function updateNestedModeLabels() {
            const enabled = document.getElementById('nestedModeEnabled')?.checked;
            const modeLabel = document.getElementById('nestedModeLabel');
            const urlLabel = document.getElementById('sourceApiUrlLabel');
            const keyLabel = document.getElementById('sourceApiKeyLabel');
            const urlHint = document.getElementById('sourceApiUrlHint');
            if (!modeLabel || !urlLabel || !keyLabel || !urlHint) return;

            if (enabled) {
                modeLabel.textContent = '开启';
                modeLabel.style.color = 'var(--success)';
                urlLabel.textContent = '同系统对接 API 地址';
                keyLabel.textContent = '对接密钥';
                urlHint.textContent = '填写另一套 syytianheban「我的上游对接信息」里提供的 openapi.php 地址。';
            } else {
                modeLabel.textContent = '关闭';
                modeLabel.style.color = 'var(--text-secondary)';
                urlLabel.textContent = '源平台API地址';
                keyLabel.textContent = '源平台API密钥';
                urlHint.textContent = '用于开户、余额兑换和余额查询，默认 https://syyrun.com/api.php；项目可通过 getProjectInfo 自动同步。';
            }
            applyAuditTabVisibility(enabled);
            applyBusinessFeatureVisibility(enabled);
        }

        // 直连爽歪歪时实际业务在 customer_link 中完成，源台也没有公开工单 API。
        function applyBusinessFeatureVisibility(nestedEnabled) {
            const ticketBtn = document.getElementById('ticketTabBtn');
            if (ticketBtn) ticketBtn.style.display = nestedEnabled ? '' : 'none';
            const ticketTab = document.getElementById('tab-tickets');
            if (!nestedEnabled && ticketTab && ticketTab.classList.contains('active')) {
                switchTab('myKeys');
            }
        }

        // 套娃模式下仍允许「下游套娃客户审计」，仅禁用上游审计
        function applyAuditTabVisibility(nestedEnabled) {
            const btn = document.getElementById('auditTabBtn');
            if (btn) btn.style.display = '';
            const typeFilter = document.getElementById('auditTypeFilter');
            if (!typeFilter) return;
            const upstreamOption = typeFilter.querySelector('option[value="upstream"]');
            if (upstreamOption) upstreamOption.disabled = !!nestedEnabled;
            if (nestedEnabled && (typeFilter.value === 'upstream' || typeFilter.value === 'all')) {
                typeFilter.value = 'downstream';
            }
        }

        function saveConfig() {
            const apiUrl = document.getElementById('sourceApiUrl').value || 'https://syyrun.com/api.php';
            const apiKey = document.getElementById('sourceApiKey').value;
            const nestedMode = document.getElementById('nestedModeEnabled').checked ? '1' : '0';
            const baseRate = document.getElementById('baseRate').value || '0.2';

            const p1 = fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=saveConfig&config_key=source_api_url&config_value=${encodeURIComponent(apiUrl)}`
            }).then(r => r.json());

            const p2 = fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=saveConfig&config_key=source_api_key&config_value=${encodeURIComponent(apiKey)}`
            }).then(r => r.json());

            const p3 = fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=saveConfig&config_key=base_rate&config_value=${encodeURIComponent(baseRate)}`
            }).then(r => r.json());

            const p4 = fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=saveConfig&config_key=nested_mode_enabled&config_value=${encodeURIComponent(nestedMode)}`
            }).then(r => r.json());

            Promise.all([p1, p2, p3, p4]).then(([d1, d2, d3, d4]) => {
                if (d1.status === 'success' && d2.status === 'success' && d3.status === 'success' && d4.status === 'success') {
                    showToast('配置保存成功');
                } else {
                    showToast(d1.message || d2.message || d3.message || d4.message, 'error');
                }
            });
        }

        // ==================== 系统管理 ====================
        let systemLedgerPage = 1;
        let systemLedgerTotalPages = 1;

        function loadSystemManagement() {
            loadSystemOverview();
            loadAgentList();
            loadSystemLedger(1);
        }

        function loadSystemOverview() {
            fetch('api.php?action=getSystemOverview')
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;
                    const d = data.data;
                    document.getElementById('sysAgentCount').textContent = d.agent_count || 0;
                    document.getElementById('sysBindingCount').textContent = d.project_binding_count || 0;
                    document.getElementById('sysExchangeCharge').textContent = parseFloat(d.exchange?.total_charge || 0).toFixed(2);
                    document.getElementById('sysOpenapiCharge').textContent = parseFloat(d.openapi?.total_charge || 0).toFixed(2);
                });
        }

        function loadAgentList() {
            console.log('[系统管理] 开始加载代理列表...');
            showLoader('agentsLoader');
            const keyword = encodeURIComponent(document.getElementById('agentSearchInput')?.value || '');
            fetch(`api.php?action=getAgentList&page=1&page_size=50&keyword=${keyword}`)
                .then(r => {
                    console.log('[代理管理] API响应状态:', r.status);
                    return r.json();
                })
                .then(data => {
                    console.log('[代理管理] API返回数据:', data);
                    hideLoader('agentsLoader');
                    if (data.status === 'success') {
                        const list = data.data.list || data.data || [];
                        console.log('[系统管理] 代理数量:', list.length);
                        renderAgentList(list);
                    } else {
                        console.error('[代理管理] API返回错误:', data.message);
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error('[代理管理] 请求失败:', err);
                    hideLoader('agentsLoader');
                    showToast('加载代理列表失败', 'error');
                });
        }

        function renderAgentList(agents) {
            console.log('[代理管理] 渲染代理列表，agents:', agents);
            const tbody = document.getElementById('agentsTableBody');
            console.log('[代理管理] tbody元素:', tbody);

            if (agents.length === 0) {
                console.log('[代理管理] 代理列表为空');
                tbody.innerHTML = `
                    <tr><td colspan="8">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-users"></i></div>
                            <div class="empty-title">暂无代理数据</div>
                        </div>
                    </td></tr>
                `;
                return;
            }

            tbody.innerHTML = agents.map(agent => `
                <tr>
                    <td data-label="UID">${agent.uid}</td>
                    <td data-label="用户名">${escapeHtml(agent.username)}</td>
                    <td data-label="项目数">${agent.project_count}</td>
                    <td data-label="普通充值">${parseFloat(agent.total_charge || 0).toFixed(2)}元</td>
                    <td data-label="普通提现">${parseFloat(agent.total_withdraw || 0).toFixed(2)}元</td>
                    <td data-label="对接扣费">${parseFloat(agent.openapi_charge || 0).toFixed(2)}元</td>
                    <td data-label="今日调用">${agent.today_calls || 0}</td>
                    <td data-label="">
                        <div class="btn-group">
                            <button class="btn btn-primary btn-sm" onclick="showAgentDetail(${agent.uid}, '${escapeHtml(agent.username)}')">
                                <i class="fas fa-eye"></i> 查看详情
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
            console.log('[代理管理] 已渲染', agents.length, '个代理');
        }

        function showAgentDetail(uid, username) {
            document.getElementById('currentAgentUid').value = uid;
            document.getElementById('agentDetailUsername').textContent = username;

            // 加载代理的项目秘钥和账面统计
            fetch(`api.php?action=getSystemAgentDetail&agent_uid=${uid}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderAgentProjects(data.data.projects || data.data);
                        renderAgentOpenApiCustomers(data.data.openapi_customers || []);
                        showModal('agentDetailModal');
                    } else {
                        showToast(data.message, 'error');
                    }
                });
        }

        function filterAgentList() {
            const searchInput = document.getElementById('agentSearchInput');
            const filter = searchInput.value.toLowerCase().trim();
            const tbody = document.getElementById('agentsTableBody');
            const rows = tbody.getElementsByTagName('tr');

            let visibleCount = 0;

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');

                // 跳过空状态行
                if (cells.length < 2) continue;

                const uid = cells[0].textContent.toLowerCase();
                const username = cells[1].textContent.toLowerCase();

                if (uid.includes(filter) || username.includes(filter)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }

            // 如果没有匹配结果，显示提示
            if (visibleCount === 0 && filter !== '') {
                // 检查是否已有提示行
                let noResultRow = document.getElementById('agentNoResultRow');
                if (!noResultRow) {
                    noResultRow = tbody.insertRow(0);
                    noResultRow.id = 'agentNoResultRow';
                    noResultRow.innerHTML = `
                        <td colspan="8" style="text-align: center; padding: 20px; color: #999;">
                            <i class="fas fa-search"></i> 没有找到匹配的代理
                        </td>
                    `;
                }
                noResultRow.style.display = '';
            } else {
                const noResultRow = document.getElementById('agentNoResultRow');
                if (noResultRow) {
                    noResultRow.style.display = 'none';
                }
            }
        }

        function renderAgentProjects(projects) {
            const tbody = document.getElementById('agentProjectsBody');

            if (projects.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-key"></i></div>
                            <div class="empty-title">暂无源台客户 Key</div>
                        </div>
                    </td></tr>
                `;
                return;
            }

            tbody.innerHTML = projects.map(proj => `
                <tr>
                    <td data-label="项目名称">${escapeHtml(proj.project_name)}</td>
                    <td data-label="API密钥">
                        <code style="font-size: 11px; word-break: break-all;">${proj.source_api_key}</code>
                    </td>
                    <td data-label="充值/提现">充 ${parseFloat(proj.total_charge || 0).toFixed(2)} / 提 ${parseFloat(proj.total_withdraw || 0).toFixed(2)}</td>
                    <td data-label="创建时间">${proj.created_at}</td>
                    <td data-label="">
                        <button class="btn btn-info btn-sm" onclick="showExchangeLogs(${proj.agent_uid}, ${proj.project_id}, '${escapeHtml(proj.project_name)}')">
                            <i class="fas fa-list-alt"></i> 流水
                        </button>
                        <button class="btn btn-outline btn-sm" onclick="filterLedgerByAgentProject(${proj.agent_uid}, ${proj.project_id})">
                            <i class="fas fa-book"></i> 查账本
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function renderAgentOpenApiCustomers(customers) {
            const tbody = document.getElementById('agentOpenApiCustomersBody');
            if (!customers.length) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-title">暂无下游客户</div></div></td></tr>';
                return;
            }
            tbody.innerHTML = customers.map(c => `
                <tr>
                    <td>${c.id}</td>
                    <td>${escapeHtml(c.project_name || String(c.project_id || '-'))}</td>
                    <td><code style="font-size:11px;word-break:break-all;">${c.api_key}</code></td>
                    <td>${parseFloat(c.balance || 0).toFixed(4)} km</td>
                    <td>扣 ${parseFloat(c.total_charge || 0).toFixed(2)} / 返 ${parseFloat(c.total_withdraw || 0).toFixed(2)}</td>
                    <td>${c.created_at || '-'}</td>
                    <td><button class="btn btn-outline btn-sm" onclick="filterLedgerByCustomer(${c.owner_uid}, ${c.project_id}, ${c.id})"><i class="fas fa-book"></i> 查账</button></td>
                </tr>
            `).join('');
        }

        function filterLedgerByCustomer(agentUid, projectId, customerId) {
            closeModal('agentDetailModal');
            document.getElementById('ledgerAgentUid').value = agentUid;
            document.getElementById('ledgerProjectId').value = projectId;
            document.getElementById('ledgerSource').value = 'openapi';
            document.getElementById('ledgerKeyword').value = customerId;
            switchTab('agents');
            loadSystemLedger(1);
        }

        function filterLedgerByAgentProject(agentUid, projectId) {
            closeModal('agentDetailModal');
            document.getElementById('ledgerAgentUid').value = agentUid;
            document.getElementById('ledgerProjectId').value = projectId;
            switchTab('agents');
            loadSystemLedger(1);
        }

        function loadSystemLedger(page = 1) {
            page = Math.max(1, page);
            if (systemLedgerTotalPages && page > systemLedgerTotalPages) page = systemLedgerTotalPages;
            systemLedgerPage = page;
            const params = new URLSearchParams({
                action: 'getSystemLedger',
                page: page,
                page_size: 20,
                agent_uid: document.getElementById('ledgerAgentUid')?.value || '',
                project_id: document.getElementById('ledgerProjectId')?.value || '',
                source: document.getElementById('ledgerSource')?.value || '',
                direction: document.getElementById('ledgerDirection')?.value || '',
                date_start: document.getElementById('ledgerDateStart')?.value || '',
                date_end: document.getElementById('ledgerDateEnd')?.value || '',
                keyword: document.getElementById('ledgerKeyword')?.value || ''
            });
            fetch('api.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') { showToast(data.message || '加载账本失败', 'error'); return; }
                    renderSystemLedger(data.data);
                });
        }

        function renderSystemLedger(data) {
            const summary = data.summary || {};
            document.getElementById('ledgerPlatformCharge').textContent = parseFloat(summary.platform_charge_total || 0).toFixed(2);
            document.getElementById('ledgerPlatformWithdraw').textContent = parseFloat(summary.platform_withdraw_total || 0).toFixed(2);
            document.getElementById('ledgerNetPlatform').textContent = parseFloat(summary.net_platform_amount || 0).toFixed(2);
            document.getElementById('ledgerRecordCount').textContent = summary.record_count || 0;
            const p = data.pagination || {page:1,total_pages:1};
            systemLedgerPage = p.page || 1; systemLedgerTotalPages = p.total_pages || 1;
            document.getElementById('systemLedgerPager').textContent = `第 ${systemLedgerPage} / ${systemLedgerTotalPages} 页`;
            const tbody = document.getElementById('systemLedgerBody');
            const list = data.list || [];
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="empty-title">暂无账本</div></div></td></tr>';
                return;
            }
            tbody.innerHTML = list.map(row => `
                <tr>
                    <td>${row.created_at || '-'}</td>
                    <td><span class="badge ${row.ledger_source === 'openapi' ? 'badge-info' : 'badge-success'}">${row.ledger_source === 'openapi' ? '下游套娃' : '当前系统'}</span></td>
                    <td>${row.owner_uid}</td>
                    <td>${escapeHtml(row.username || '')}</td>
                    <td>${escapeHtml(row.project_name || String(row.project_id || '-'))}</td>
                    <td>${row.customer_id || '-'}</td>
                    <td>${row.direction === 'charge' ? '充值扣费' : '提现返款'}</td>
                    <td>${parseFloat(row.project_amount || 0).toFixed(4)} km</td>
                    <td>${parseFloat(row.platform_amount || 0).toFixed(2)} 元</td>
                    <td>${parseFloat(row.actual_price || 0).toFixed(2)}<br><small>${escapeHtml(row.formula || '')}</small></td>
                </tr>
            `).join('');
        }

        function showExchangeLogs(agentUid, projectId, projectName) {
            document.getElementById('logsProjectName').textContent = projectName;

            // 加载充值日志
            fetch(`api.php?action=getAgentExchangeLogs&agent_uid=${agentUid}&project_id=${projectId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderExchangeLogs(data.data);
                        showModal('exchangeLogsModal');
                    } else {
                        showToast(data.message, 'error');
                    }
                });
        }

        function renderExchangeLogs(logs) {
            const tbody = document.getElementById('exchangeLogsBody');

            if (logs.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="8">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div class="empty-title">暂无充值记录</div>
                        </div>
                    </td></tr>
                `;
                return;
            }

            tbody.innerHTML = logs.map(log => {
                const typeText = log.exchange_type === 'charge' ? '充值' : '提现';
                const typeClass = log.exchange_type === 'charge' ? 'success' : 'warning';

                return `
                    <tr>
                        <td data-label="时间">${log.created_at}</td>
                        <td data-label="类型">
                            <span class="badge badge-${typeClass}">${typeText}</span>
                        </td>
                        <td data-label="平台金额">${parseFloat(log.platform_amount).toFixed(2)}元</td>
                        <td data-label="项目金额">${parseFloat(log.project_amount).toFixed(2)}元</td>
                        <td data-label="比例">${parseFloat(log.exchange_rate).toFixed(2)}</td>
                        <td data-label="余额变化">${parseFloat(log.before_balance).toFixed(2)} → ${parseFloat(log.after_balance).toFixed(2)}</td>
                    </tr>
                `;
            }).join('');
        }
        <?php endif; ?>

        // ==================== 系统检测 ====================
        <?php if ($is_admin): ?>
        function diagShowResult(elementId, result) {
            const el = document.getElementById(elementId);
            el.textContent = JSON.stringify(result, null, 2);
            const ok = result.code === 200 || result.status === 'success';
            el.style.color = ok ? 'var(--success)' : 'var(--danger)';

            // 失败时显示复制按钮和提示
            const wrapperId = elementId + '_actions';
            let wrapper = document.getElementById(wrapperId);
            if (!wrapper) {
                wrapper = document.createElement('div');
                wrapper.id = wrapperId;
                wrapper.style.cssText = 'margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;';
                el.parentNode.insertBefore(wrapper, el.nextSibling);
            }
            if (!ok) {
                wrapper.innerHTML = `
                    <button class="btn btn-outline btn-sm" onclick="diagCopyLog('${elementId}')">
                        <i class="fas fa-copy"></i> 复制错误日志
                    </button>
                    <span style="font-size:0.75rem;color:var(--text-secondary);">您可以复制错误日志给相关开发者适配</span>
                `;
            } else {
                wrapper.innerHTML = '';
            }
        }

        function diagCopyLog(elementId) {
            const text = document.getElementById(elementId).textContent;
            navigator.clipboard.writeText(text).then(() => {
                showToast('错误日志已复制');
            }).catch(() => {
                showToast('复制失败，请手动选择复制', 'error');
            });
        }

        async function diagRequest(url, options = {}) {
            try {
                const r = await fetch(url, { credentials: 'same-origin', ...options });
                return await r.json();
            } catch(e) {
                return { code: 500, msg: '请求失败: ' + e.message };
            }
        }

        async function diagCheckUserStatus() {
            const result = await diagRequest('api.php?action=getBalance');
            const el = document.getElementById('diagUserStatus');
            if (result.code === 200) {
                el.innerHTML = `<span style="color:var(--success)">已登录 - 余额: ${result.data.balance}元</span>`;
            } else {
                el.innerHTML = `<span style="color:var(--danger)">${result.msg}</span>`;
            }
        }

        async function diagGetBalance() {
            const result = await diagRequest('api.php?action=getBalance');
            diagShowResult('diagBalanceResult', result);
        }

        async function diagCheckBalance() {
            const required = document.getElementById('diagRequiredAmount').value;
            const fd = new FormData();
            fd.append('action', 'checkBalance');
            fd.append('required', required);
            const result = await diagRequest('api.php', { method: 'POST', body: fd });
            diagShowResult('diagCheckBalanceResult', result);
        }

        async function diagUpdateBalance() {
            const fd = new FormData();
            fd.append('action', 'updateBalance');
            fd.append('type', document.getElementById('diagBalanceType').value);
            fd.append('amount', document.getElementById('diagBalanceAmount').value);
            fd.append('remark', document.getElementById('diagBalanceRemark').value);
            const result = await diagRequest('api.php', { method: 'POST', body: fd });
            diagShowResult('diagUpdateBalanceResult', result);
            if (result.code === 200) diagCheckUserStatus();
        }

        async function diagGetRate() {
            const cid = document.getElementById('diagCourseId').value;
            const url = cid ? `api.php?action=getRate&cid=${cid}` : 'api.php?action=getRate';
            const result = await diagRequest(url);
            diagShowResult('diagRateResult', result);
        }

        async function diagWriteLog() {
            const fd = new FormData();
            fd.append('action', 'writeLog');
            fd.append('type', document.getElementById('diagLogType').value);
            fd.append('text', document.getElementById('diagLogText').value);
            fd.append('money', document.getElementById('diagLogMoney').value);
            const result = await diagRequest('api.php', { method: 'POST', body: fd });
            diagShowResult('diagLogResult', result);
        }

        async function runAllDiagnostics() {
            diagCheckUserStatus();
            await diagGetBalance();
            await diagCheckBalance();
            await diagGetRate();
            await diagWriteLog();
            showToast('一键检测完成');
        }

        function clearDiagnostics() {
            ['diagBalanceResult','diagCheckBalanceResult','diagUpdateBalanceResult','diagRateResult','diagLogResult'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.textContent = ''; el.style.color = ''; }
            });
        }
        <?php endif; ?>

        // ==================== 公告弹窗功能 ====================

        /**
         * 加载公告 —— 根据弹出模式决定是否显示
         * popup_mode:
         *   "always"      每次都弹出
         *   "once"        仅新公告（更新后）第一次阅读后不再提醒
         */
        function loadAnnouncement() {
            fetch('api.php?action=getAnnouncement')
                .then(r => r.json())
                .then(data => {
                    if (!data.has_content) return;

                    const content    = data.content    || '';
                    const updatedAt  = data.updated_at || '';
                    const popupMode  = data.popup_mode || 'once';   // 默认仅一次

                    // 同步更新翻转卡片里的文字
                    document.getElementById('announcementText').textContent = content;

                    // 判断是否需要弹出
                    let shouldShow = false;
                    if (popupMode === 'always') {
                        shouldShow = true;
                        // always 模式不显示"不再提醒"勾选框
                        document.getElementById('annModalCheckLabel').style.display = 'none';
                    } else {
                        // once 模式：用 localStorage 记录已读的公告版本（以更新时间为标识）
                        const readKey  = 'ann_read_' + '<?php echo isset($agent_uid) ? $agent_uid : "0"; ?>';
                        const lastRead = localStorage.getItem(readKey) || '';
                        shouldShow = (lastRead !== updatedAt);
                        document.getElementById('annModalCheckLabel').style.display = 'flex';
                        document.getElementById('annModalCheckText').textContent = '不再提醒此条公告';
                        // 存储更新时间供关闭时使用
                        document.getElementById('announcementModal').dataset.updatedAt = updatedAt;
                        document.getElementById('announcementModal').dataset.readKey   = readKey;
                    }

                    if (!shouldShow) return;

                    // 填充弹窗内容
                    document.getElementById('annModalContent').textContent = content;
                    const timeEl = document.getElementById('annModalTime');
                    if (timeEl) timeEl.textContent = updatedAt ? '更新于 ' + updatedAt : '';
                    document.getElementById('annModalNoMore').checked = false;
                    const checkBox = document.getElementById('annModalCheckBox');
                    if (checkBox) checkBox.classList.remove('checked');

                    // 延迟 800ms 再弹出，等页面渲染稳定
                    setTimeout(openAnnouncementModal, 800);
                })
                .catch(() => {});
        }

        /** 铃铛按钮手动触发公告弹窗 */
        function showAnnouncementBell() {
            fetch('api.php?action=getAnnouncement')
                .then(r => r.json())
                .then(data => {
                    if (!data.has_content) {
                        showToast('暂无公告', 'info');
                        return;
                    }
                    const content   = data.content    || '';
                    const updatedAt = data.updated_at || '';
                    const popupMode = data.popup_mode || 'once';

                    document.getElementById('annModalContent').textContent = content;
                    const timeEl = document.getElementById('annModalTime');
                    if (timeEl) timeEl.textContent = updatedAt ? '更新于 ' + updatedAt : '';
                    document.getElementById('annModalNoMore').checked = false;
                    const checkBox = document.getElementById('annModalCheckBox');
                    if (checkBox) checkBox.classList.remove('checked');

                    if (popupMode === 'always') {
                        document.getElementById('annModalCheckLabel').style.display = 'none';
                    } else {
                        const readKey = 'ann_read_' + '<?php echo isset($agent_uid) ? $agent_uid : "0"; ?>';
                        document.getElementById('annModalCheckLabel').style.display = 'flex';
                        document.getElementById('annModalCheckText').textContent = '不再提醒此条公告';
                        document.getElementById('announcementModal').dataset.updatedAt = updatedAt;
                        document.getElementById('announcementModal').dataset.readKey   = readKey;
                    }

                    openAnnouncementModal();
                })
                .catch(() => showToast('公告加载失败', 'error'));
        }

        function openAnnouncementModal() {
            const modal = document.getElementById('announcementModal');
            modal.style.display = 'flex';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => modal.classList.add('show'));
            });
            document.body.style.overflow = 'hidden';
        }

        function closeAnnouncementModal() {
            const modal   = document.getElementById('announcementModal');
            const noMore  = document.getElementById('annModalNoMore').checked;
            const readKey = modal.dataset.readKey;
            const updAt   = modal.dataset.updatedAt;

            if (noMore && readKey && updAt) {
                localStorage.setItem(readKey, updAt);
            }

            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }, 350);
        }

        /** 自定义 checkbox 切换 */
        function toggleAnnCheck() {
            const cb  = document.getElementById('annModalNoMore');
            const box = document.getElementById('annModalCheckBox');
            cb.checked = !cb.checked;
            cb.checked ? box.classList.add('checked') : box.classList.remove('checked');
        }

        /** 点击遮罩层关闭 */
        function handleAnnModalBgClick(e) {
            if (e.target === document.getElementById('announcementModal')) {
                closeAnnouncementModal();
            }
        }

        <?php if ($is_admin): ?>
        /** 保存公告（管理员） */
        function saveAnnouncement() {
            const content   = document.getElementById('announcementContent').value;
            const popupMode = document.getElementById('announcementPopupMode').value;
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=saveAnnouncement'
                    + '&content='    + encodeURIComponent(content)
                    + '&popup_mode=' + encodeURIComponent(popupMode)
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('公告保存成功');
                    document.getElementById('announcementText').textContent = content;
                } else {
                    showToast(data.message, 'error');
                }
            });
        }

        /** 加载公告到编辑框（管理员） */
        function loadAnnouncementForEdit() {
            fetch('api.php?action=getAnnouncement')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('announcementContent').value = data.content || '';
                        const modeEl = document.getElementById('announcementPopupMode');
                        if (modeEl) modeEl.value = data.popup_mode || 'once';
                        const ts = document.getElementById('announcementUpdatedAt');
                        if (ts) ts.textContent = data.updated_at ? ('最后更新：' + data.updated_at) : '';
                    }
                });
        }
        <?php endif; ?>

        // ==================== 客户工单管理 ====================

        /**
         * 加载工单列表
         */
        function loadTickets() {
            // 先加载项目列表到筛选器
            loadProjectsToFilter();

            const tbody = document.getElementById('ticketsTableBody');
            const loader = document.getElementById('ticketsLoader');

            // 显示加载状态
            if (loader) loader.style.display = 'flex';
            if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;"><div class="spinner"></div></td></tr>';

            const projectId = document.getElementById('ticketProjectFilter')?.value || '';
            const status = document.getElementById('ticketStatusFilter')?.value || '';
            const type = document.getElementById('ticketTypeFilter')?.value || '';

            const params = new URLSearchParams({
                action: 'getCustomerTickets',
                project_id: projectId,
                status: status,
                type: type,
                page: 1,
                limit: 50
            });

            fetch('api.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    if (loader) loader.style.display = 'none';

                    if (data.status === 'success') {
                        renderTickets(data.data.list || data.data.tickets || []);
                    } else {
                        if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#ef4444;">' + escapeHtml(data.message || '加载失败') + '</td></tr>';
                    }
                })
                .catch(err => {
                    console.error('加载工单失败:', err);
                    if (loader) loader.style.display = 'none';
                    if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#ef4444;">网络错误</td></tr>';
                });
        }

        /**
         * 加载项目列表到筛选器
         */
        function loadProjectsToFilter() {
            fetch('api.php?action=getProjects')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success' && data.data) {
                        const select = document.getElementById('ticketProjectFilter');
                        if (select) {
                            // 保留当前选中的值
                            const currentValue = select.value;
                            // 清空并重新填充
                            select.innerHTML = '<option value="">全部项目</option>';
                            data.data.forEach(project => {
                                const option = document.createElement('option');
                                option.value = project.id;
                                option.textContent = project.name;
                                select.appendChild(option);
                            });
                            // 恢复选中值
                            select.value = currentValue;
                        }
                    }
                })
                .catch(err => {
                    console.error('加载项目列表失败:', err);
                });
        }

        /**
         * 渲染工单列表
         */
        function renderTickets(tickets) {
            const tbody = document.getElementById('ticketsTableBody');

            if (!tickets || tickets.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                <div class="empty-title">暂无工单</div>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            const typeLabels = {
                'suggestion': '<span class="badge bg-info">开发建议</span>',
                'bug': '<span class="badge bg-danger">Bug反馈</span>',
                'compensation': '<span class="badge bg-warning text-dark">余额补偿</span>'
            };

            const statusLabels = {
                'pending': '<span class="badge bg-secondary">待处理</span>',
                'processing': '<span class="badge bg-primary">处理中</span>',
                'resolved': '<span class="badge bg-success">已处理</span>',
                'closed': '<span class="badge bg-dark">已关闭</span>'
            };

            tbody.innerHTML = tickets.map(ticket => {
                return `
                <tr>
                    <td>${escapeHtml(ticket.ticket_no || '')}</td>
                    <td>${escapeHtml(ticket.project_name || '')}</td>
                    <td>${typeLabels[ticket.type] || ticket.type}</td>
                    <td>${escapeHtml(ticket.title || '')}</td>
                    <td>${statusLabels[ticket.status] || ticket.status}</td>
                    <td style="color:#6b7280;font-size:13px;">${escapeHtml(ticket.created_at || '')}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewTicketDetail(${ticket.id}, ${ticket.project_id})">
                            <i class="fas fa-eye"></i> 查看
                        </button>
                    </td>
                </tr>
                `;
            }).join('');
        }

        /**
         * 查看工单详情
         */
        function viewTicketDetail(ticketId, projectId) {
            console.log('viewTicketDetail 调用', { ticketId, projectId });
            
            // 先弹出模态框显示加载状态
            showTicketDetailModal(null, null, ticketId, projectId);
            
            const params = new URLSearchParams({
                action: 'getCustomerTicketDetail',
                ticket_id: ticketId,
                project_id: projectId
            });

            fetch('api.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    console.log('API响应:', data);
                    if (data.status === 'success') {
                        updateTicketDetailModal(data.data.ticket, data.data.replies);
                    } else {
                        const modalBody = document.getElementById('ticketDetailModalBody');
                        if (modalBody) {
                            modalBody.innerHTML = '<div style="text-align:center;padding:40px;color:#ef4444;">' + (data.message || '加载失败') + '</div>';
                        }
                    }
                })
                .catch(err => {
                    console.error('请求失败:', err);
                    const modalBody = document.getElementById('ticketDetailModalBody');
                    if (modalBody) {
                        modalBody.innerHTML = '<div style="text-align:center;padding:40px;color:#ef4444;">网络错误</div>';
                    }
                });
        }

        function showTicketDetailModal(ticket, replies, ticketId, projectId) {
            const typeLabels = {
                'suggestion': '开发建议',
                'bug': 'Bug反馈',
                'compensation': '余额补偿'
            };

            const statusLabels = {
                'pending': '待处理',
                'processing': '处理中',
                'resolved': '已处理',
                'closed': '已关闭'
            };

            const isLoading = ticket === null;

            let modalBodyContent = '';
            
            if (isLoading) {
                modalBodyContent = `
                    <div style="text-align:center;padding:60px 20px;">
                        <div class="ticket-spinner"></div>
                        <div style="margin-top:16px;color:#6b7280;font-size:14px;">加载中...</div>
                    </div>
                `;
            } else {
                modalBodyContent = `
                    <table class="ticket-detail-table">
                        <tr>
                            <td>工单编号</td>
                            <td>${escapeHtml(ticket.ticket_no || '')}</td>
                        </tr>
                        <tr>
                            <td>类型</td>
                            <td><span class="ticket-badge ticket-badge-${ticket.type}">${typeLabels[ticket.type] || ticket.type}</span></td>
                        </tr>
                        <tr>
                            <td>状态</td>
                            <td><span class="ticket-badge ticket-badge-${ticket.status}">${statusLabels[ticket.status] || ticket.status}</span></td>
                        </tr>
                        <tr>
                            <td>标题</td>
                            <td>${escapeHtml(ticket.title || '')}</td>
                        </tr>
                        ${ticket.agent_name ? `
                        <tr>
                            <td>所属代理</td>
                            <td>${escapeHtml(ticket.agent_name)}</td>
                        </tr>
                        ` : ''}
                        ${ticket.customer_link ? `
                        <tr>
                            <td>客户链接</td>
                            <td><a href="${escapeHtml(ticket.customer_link)}" target="_blank" class="link-primary"><i class="fas fa-external-link-alt"></i> ${escapeHtml(ticket.customer_link)}</a></td>
                        </tr>
                        ` : ''}
                    </table>

                    <div class="ticket-section">
                        <div class="ticket-section-title">
                            <i class="fas fa-align-left"></i> 详细描述
                        </div>
                        <div class="ticket-section-content">${escapeHtml(ticket.description || '').replace(/\n/g, '<br>')}</div>
                    </div>

                    ${ticket.image_data ? `
                    <div class="ticket-section">
                        <div class="ticket-section-title">
                            <i class="fas fa-image"></i> 附件图片
                        </div>
                        <div class="ticket-image-box">
                            <img src="${ticket.image_data}" alt="工单图片">
                        </div>
                    </div>
                    ` : ''}

                    ${ticket.compensation_amount ? `
                    <div class="ticket-compensation-box">
                        <i class="fas fa-yen-sign"></i>
                        申请补偿金额：<strong>¥${parseFloat(ticket.compensation_amount).toFixed(2)}</strong>
                    </div>
                    ` : ''}

                    ${ticket.review_result ? `
                    <div class="ticket-compensation-box" style="background: ${ticket.review_result === 'approved' ? '#e8f5e9' : '#ffebee'}; border-color: ${ticket.review_result === 'approved' ? '#c8e6c9' : '#ffcdd2'}; color: ${ticket.review_result === 'approved' ? '#2e7d32' : '#c62828'};">
                        <i class="fas ${ticket.review_result === 'approved' ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                        审核结果：${ticket.review_result === 'approved' ? '已通过' : '已拒绝'}
                    </div>
                    ` : ''}

                    <div class="ticket-section">
                        <div class="ticket-section-title">
                            <i class="fas fa-comments"></i> 回复记录
                        </div>
                        <div id="ticketReplies" class="ticket-replies-list">
                            ${renderTicketReplies(replies || [])}
                        </div>
                    </div>

                    <div class="ticket-section">
                        <textarea id="replyContent" class="form-control" rows="3" placeholder="输入回复内容..."></textarea>
                        <div class="ticket-reply-actions">
                            <button class="btn btn-primary" onclick="submitReply(${ticket.id}, ${ticket.project_id})">
                                <i class="fas fa-paper-plane"></i>
                                <span>发送回复</span>
                            </button>
                            <button class="btn btn-outline" onclick="document.getElementById('replyImageInput').click()">
                                <i class="fas fa-image"></i>
                                <span>图片</span>
                            </button>
                            <input type="file" id="replyImageInput" accept="image/*" style="display:none;" onchange="handleReplyImage(this)">
                        </div>
                    </div>
                `;
            }

            const modalHtml = `
                <div id="ticketDetailModal" class="modal show" style="display:flex;">
                    <div class="modal-dialog" style="max-width:90vw !important;width:90vw;">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-ticket-alt"></i>
                                    工单详情
                                </h5>
                                <button type="button" class="btn-close" onclick="closeModal('ticketDetailModal')"></button>
                            </div>
                            <div class="modal-body" id="ticketDetailModalBody">
                                ${modalBodyContent}
                            </div>
                        </div>
                    </div>
                </div>
            `;

            const oldModal = document.getElementById('ticketDetailModal');
            if (oldModal) oldModal.remove();

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            if (!isLoading && ticket) {
                const modal = document.getElementById('ticketDetailModal');
                if (modal) {
                    modal.dataset.ticketId = ticket.id;
                    modal.dataset.projectId = ticket.project_id;
                }
            }
        }

        function updateTicketDetailModal(ticket, replies) {
            const modalBody = document.getElementById('ticketDetailModalBody');
            const modal = document.getElementById('ticketDetailModal');
            
            if (!modalBody || !modal) {
                console.error('模态框元素未找到');
                return;
            }
            
            modal.dataset.ticketId = ticket.id;
            modal.dataset.projectId = ticket.project_id;
            
            const typeLabels = {
                'suggestion': '开发建议',
                'bug': 'Bug反馈',
                'compensation': '余额补偿'
            };

            const statusLabels = {
                'pending': '待处理',
                'processing': '处理中',
                'resolved': '已处理',
                'closed': '已关闭'
            };

            const modalBodyContent = `
                <table class="ticket-detail-table">
                    <tr>
                        <td>工单编号</td>
                        <td>${escapeHtml(ticket.ticket_no || '')}</td>
                    </tr>
                    <tr>
                        <td>类型</td>
                        <td><span class="ticket-badge ticket-badge-${ticket.type}">${typeLabels[ticket.type] || ticket.type}</span></td>
                    </tr>
                    <tr>
                        <td>状态</td>
                        <td><span class="ticket-badge ticket-badge-${ticket.status}">${statusLabels[ticket.status] || ticket.status}</span></td>
                    </tr>
                    <tr>
                        <td>标题</td>
                        <td>${escapeHtml(ticket.title || '')}</td>
                    </tr>
                    ${ticket.customer_link ? `
                    <tr>
                        <td>专属链接</td>
                        <td><a href="${ticket.customer_link}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt"></i> 打开链接</a></td>
                    </tr>
                    ` : ''}
                </table>

                <div class="ticket-section">
                    <div class="ticket-section-title">
                        <i class="fas fa-align-left"></i> 详细描述
                    </div>
                    <div class="ticket-section-content">${escapeHtml(ticket.description || '').replace(/\n/g, '<br>')}</div>
                </div>

                ${ticket.image_data ? `
                <div class="ticket-section">
                    <div class="ticket-section-title">
                        <i class="fas fa-image"></i> 附件图片
                    </div>
                    <div class="ticket-image-box">
                        <img src="${ticket.image_data}" alt="工单图片">
                    </div>
                </div>
                ` : ''}

                ${ticket.compensation_amount ? `
                <div class="ticket-compensation-box">
                    <i class="fas fa-yen-sign"></i>
                    申请补偿金额：<strong>¥${parseFloat(ticket.compensation_amount).toFixed(2)}</strong>
                </div>
                ` : ''}

                ${ticket.review_result ? `
                <div class="ticket-compensation-box" style="background: ${ticket.review_result === 'approved' ? '#e8f5e9' : '#ffebee'}; border-color: ${ticket.review_result === 'approved' ? '#c8e6c9' : '#ffcdd2'}; color: ${ticket.review_result === 'approved' ? '#2e7d32' : '#c62828'};">
                    <i class="fas ${ticket.review_result === 'approved' ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                    审核结果：${ticket.review_result === 'approved' ? '已通过' : '已拒绝'}
                </div>
                ` : ''}

                <div class="ticket-section">
                    <div class="ticket-section-title">
                        <i class="fas fa-comments"></i> 回复记录
                    </div>
                    <div id="ticketReplies" class="ticket-replies-list">
                        ${renderTicketReplies(replies || [])}
                    </div>
                </div>

                <div class="ticket-section">
                    <textarea id="replyContent" class="form-control" rows="3" placeholder="输入回复内容..."></textarea>
                    <div class="ticket-reply-actions">
                        <button class="btn btn-primary" onclick="submitReply(${ticket.id}, ${ticket.project_id})">
                            <i class="fas fa-paper-plane"></i>
                            <span>发送回复</span>
                        </button>
                        <button class="btn btn-outline" onclick="document.getElementById('replyImageInput').click()">
                            <i class="fas fa-image"></i>
                            <span>图片</span>
                        </button>
                        <input type="file" id="replyImageInput" accept="image/*" style="display:none;" onchange="handleReplyImage(this)">
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = modalBodyContent;
            console.log('工单详情已更新');
        }
        function renderTicketReplies(replies) {
            if (!replies || replies.length === 0) {
                return '<div class="text-center text-muted py-3">暂无回复</div>';
            }

            return replies.map(reply => {
                const isCustomer = reply.user_type === 'customer';
                const isAgent = reply.user_type === 'agent';
                const isAdmin = reply.user_type === 'admin';

                // 根据用户类型设置标签样式和名称
                let badgeClass, userName, icon;
                if (isCustomer) {
                    badgeClass = 'bg-info';
                    userName = reply.reply_username || '客户';
                    icon = 'fa-user';
                } else if (isAgent) {
                    badgeClass = 'bg-warning text-dark';
                    userName = '站长';
                    icon = 'fa-crown';
                } else if (isAdmin) {
                    badgeClass = 'bg-danger';
                    userName = reply.reply_username || '管理员';
                    icon = 'fa-shield-alt';
                } else {
                    badgeClass = 'bg-primary';
                    userName = reply.reply_username || '客服';
                    icon = 'fa-headset';
                }

                let content = '';
                if (reply.image_data) {
                    content = `<img src="${reply.image_data}" style="max-width:100%;max-height:200px;border-radius:8px;">`;
                } else {
                    content = escapeHtml(reply.content || '').replace(/\n/g, '<br>');
                }

                return `
                    <div class="card mb-2 ${isCustomer ? 'bg-light' : ''}">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge ${badgeClass}"><i class="fas ${icon}"></i> ${userName}</span>
                                <small class="text-muted">${reply.created_at || ''}</small>
                            </div>
                            <div style="font-size:14px;">${content}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        /**
         * 提交回复
         */
        let replyImageData = '';
        function submitReply(ticketId, projectId) {
            const content = document.getElementById('replyContent')?.value || '';

            if (!content && !replyImageData) {
                showToast('请输入回复内容或上传图片', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'replyCustomerTicket');
            formData.append('ticket_id', ticketId);
            formData.append('project_id', projectId);
            if (content) formData.append('content', content);
            if (replyImageData) formData.append('image_data', replyImageData);

            fetch('api.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('回复成功');
                    closeModal('ticketDetailModal');
                    loadTickets();
                    replyImageData = '';
                } else {
                    showToast(data.message || '回复失败', 'error');
                }
            })
            .catch(err => {
                console.error('回复失败:', err);
                showToast('网络错误', 'error');
            });
        }

        /**
         * 处理回复图片上传
         */
        function handleReplyImage(input) {
            const file = input.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                replyImageData = e.target.result;
                showToast('图片已选择，请点击发送');
            };
            reader.readAsDataURL(file);
        }

        /**
         * 显示创建工单弹窗
         */
        function showCreateTicketModal() {
            // 先加载项目列表
            fetch('api.php?action=getMyProjectKeys')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        const projects = data.data || [];
                        showCreateTicketModalWithProjects(projects);
                    } else {
                        showToast(data.message || '加载项目失败', 'error');
                    }
                })
                .catch(err => {
                    console.error('加载项目失败:', err);
                    showToast('网络错误', 'error');
                });
        }

        /**
         * 显示创建工单弹窗（带项目列表）
         */
        function showCreateTicketModalWithProjects(projects) {
            if (!projects || projects.length === 0) {
                showToast('请先绑定项目', 'error');
                return;
            }

            const projectOptions = projects.map(p =>
                `<option value="${p.project_id}">${escapeHtml(p.project_name || '')}</option>`
            ).join('');

            // 同时更新筛选器的项目列表
            const filterSelect = document.getElementById('ticketProjectFilter');
            if (filterSelect) {
                filterSelect.innerHTML = '<option value="">全部项目</option>' + projectOptions;
            }

            const modalHtml = `
                <div id="createTicketModal" class="modal show" style="display:flex;">
                    <div class="modal-dialog" style="max-width:500px;">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-plus"></i>
                                    提交工单
                                </h5>
                                <button type="button" class="btn-close" onclick="closeModal('createTicketModal')"></button>
                            </div>
                            <div class="modal-body">
                                <div class="ticket-form-group">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-project-diagram"></i> 项目 <span class="text-danger">*</span>
                                    </label>
                                    <select id="ticketProject" class="form-control">
                                        ${projectOptions}
                                    </select>
                                </div>
                                <div class="ticket-form-group">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-tag"></i> 工单类型 <span class="text-danger">*</span>
                                    </label>
                                    <select id="ticketType" class="form-control" onchange="toggleCompensationField()">
                                        <option value="suggestion">💡 开发建议</option>
                                        <option value="bug">🐛 Bug反馈</option>
                                        <option value="compensation">💰 余额补偿</option>
                                    </select>
                                </div>
                                <div class="ticket-form-group" id="compensationAmountField" style="display:none;">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-yen-sign"></i> 补偿金额 <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" id="ticketCompensationAmount" class="form-control" step="0.01" min="0.01" placeholder="请输入补偿金额">
                                </div>
                                <div class="ticket-form-group">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-heading"></i> 标题 <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="ticketTitle" class="form-control" maxlength="200" placeholder="简要描述问题">
                                </div>
                                <div class="ticket-form-group">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-align-left"></i> 详细描述 <span class="text-danger">*</span>
                                    </label>
                                    <textarea id="ticketDescription" class="form-control" rows="4" placeholder="请详细描述问题或建议内容"></textarea>
                                </div>
                                <div class="ticket-form-group">
                                    <label class="ticket-form-label">
                                        <i class="fas fa-image"></i> 图片（可选）
                                    </label>
                                    <div class="ticket-file-upload">
                                        <input type="file" id="ticketImage" accept="image/*" onchange="handleTicketImage(this)" style="display:none;">
                                        <button type="button" class="ticket-upload-btn" onclick="document.getElementById('ticketImage').click()">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <span>点击上传图片</span>
                                        </button>
                                        <div id="ticketImagePreview" class="ticket-image-preview"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline" onclick="closeModal('createTicketModal')">取消</button>
                                <button type="button" class="btn btn-primary" onclick="submitTicket()">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>提交工单</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // 移除旧弹窗
            const oldModal = document.getElementById('createTicketModal');
            if (oldModal) oldModal.remove();

            // 添加新弹窗
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            document.body.style.overflow = 'hidden';
        }

        /**
         * 切换补偿金额字段显示
         */
        function toggleCompensationField() {
            const type = document.getElementById('ticketType')?.value;
            const field = document.getElementById('compensationAmountField');
            if (field) {
                field.style.display = (type === 'compensation') ? 'block' : 'none';
            }
        }

        /**
         * 处理工单图片上传
         */
        let ticketImageData = '';
        function handleTicketImage(input) {
            const file = input.files[0];
            if (!file) return;

            // 限制图片大小（2MB）
            if (file.size > 2 * 1024 * 1024) {
                showToast('图片大小不能超过2MB', 'error');
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                ticketImageData = e.target.result;
                document.getElementById('ticketImagePreview').innerHTML =
                    `<img src="${ticketImageData}" style="max-width:100%;max-height:150px;border-radius:8px;">`;
            };
            reader.readAsDataURL(file);
        }

        /**
         * 提交工单
         */
        function submitTicket() {
            const projectId = document.getElementById('ticketProject')?.value;
            const type = document.getElementById('ticketType')?.value;
            const title = document.getElementById('ticketTitle')?.value.trim();
            const description = document.getElementById('ticketDescription')?.value.trim();
            const compensationAmount = document.getElementById('ticketCompensationAmount')?.value;

            if (!projectId) {
                showToast('请选择项目', 'error');
                return;
            }
            if (!type) {
                showToast('请选择工单类型', 'error');
                return;
            }
            if (!title) {
                showToast('请输入标题', 'error');
                return;
            }
            if (!description) {
                showToast('请输入描述', 'error');
                return;
            }
            if (type === 'compensation' && !compensationAmount) {
                showToast('请输入补偿金额', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'submitCustomerTicket');
            formData.append('project_id', projectId);
            formData.append('type', type);
            formData.append('title', title);
            formData.append('description', description);
            if (ticketImageData) {
                formData.append('image_data', ticketImageData);
            }
            if (compensationAmount) {
                formData.append('compensation_amount', compensationAmount);
            }

            fetch('api.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('工单提交成功');
                    closeModal('createTicketModal');
                    loadTickets();
                    ticketImageData = '';
                } else {
                    showToast(data.message || '提交失败', 'error');
                }
            })
            .catch(err => {
                console.error('提交工单失败:', err);
                showToast('网络错误', 'error');
            });
        }

        // ==================== 审计功能 ====================

        let auditCurrentPage = 1;
        let auditTotalPages = 1;
        let auditDetailsCache = {}; // 缓存审计详情

        // 一键审计：触发 + 分批处理，进度实时更新
        let auditRunning = false;

        async function runAudit() {
            if (auditRunning) return;
            const auditType = getAuditType();
            const auditText = auditType === 'downstream' ? '下游套娃客户的账本与当前余额' : auditType === 'all' ? '上游客户与下游套娃客户' : '上游客户的源台与本地净流入';
            if (!confirm(`确定要开始审计吗？将比对${auditText}。`)) return;

            const btn = document.getElementById('auditRunBtn');
            const progressEl = document.getElementById('auditProgressText');
            const setProgress = (txt) => { if (progressEl) progressEl.textContent = txt; };

            auditRunning = true;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 审计中';
            }

            try {
                // 第一步：触发审计，把所有客户标记为 pending
                setProgress('正在触发...');
                const trigRes = await fetch('api.php?action=triggerAudit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `agent_uid=0&audit_type=${encodeURIComponent(auditType)}`
                }).then(r => r.json());

                if (trigRes.status !== 'success') {
                    showToast(trigRes.message || '审计触发失败', 'error');
                    return;
                }

                refreshAuditStats();

                // 第二步：分批处理 pending，每批后实时刷新进度
                const BATCH = 5;
                let totalProcessed = 0;
                let totalSuccess = 0;
                let totalFailed = 0;

                while (true) {
                    const data = await fetch('api.php?action=processAudit', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `limit=${BATCH}&audit_type=${encodeURIComponent(auditType)}`
                    }).then(r => r.json());

                    if (data.status !== 'success') {
                        showToast(data.message || '审计处理失败', 'error');
                        break;
                    }

                    const r = data.data || {};
                    const processed = r.processed_count || 0;
                    totalProcessed += processed;
                    totalSuccess += (r.success_count || 0);
                    totalFailed += (r.failed_count || 0);

                    // 实时更新统计卡片与结果列表
                    refreshAuditStats();
                    refreshAuditResults(auditCurrentPage);

                    setProgress(`已处理 ${totalProcessed}（成功 ${totalSuccess}，失败 ${totalFailed}）`);

                    // 没有更多待处理客户，结束
                    if (processed < BATCH) break;
                }

                setProgress(`审计完成：共 ${totalProcessed}，成功 ${totalSuccess}，失败 ${totalFailed}`);
                showToast(`审计完成：成功 ${totalSuccess} 个，失败 ${totalFailed} 个`, totalFailed > 0 ? 'warning' : 'success');
                refreshAuditStats();
                refreshAuditResults(auditCurrentPage);
            } catch (err) {
                console.error('审计失败:', err);
                showToast('网络错误', 'error');
                setProgress('审计中断');
            } finally {
                auditRunning = false;
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play"></i> 审计';
                }
            }
        }

        function getAuditType() {
            const el = document.getElementById('auditTypeFilter');
            return el ? el.value : 'upstream';
        }

        // 刷新审计统计
        function refreshAuditStats() {
            // 检查元素是否存在
            if (!document.getElementById('auditTotal')) {
                return;
            }

            const auditType = getAuditType();
            fetch(`api.php?action=getAuditStats&audit_type=${encodeURIComponent(auditType)}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                if (data.status === 'success' && data.data) {
                    const stats = data.data;
                    document.getElementById('auditTotal').textContent = stats.total || 0;
                    document.getElementById('auditCompleted').textContent = stats.completed || 0;
                    document.getElementById('auditPending').textContent = stats.pending || 0;
                    document.getElementById('auditDiscrepancy').textContent = stats.discrepancy_count || 0;
                } else {
                    console.error('审计统计返回错误:', data);
                }
            })
            .catch(err => {
                console.error('获取审计统计失败:', err);
            });
        }

        // 刷新审计结果
        function refreshAuditResults(page = 1) {
            // 检查元素是否存在
            if (!document.getElementById('auditResultsBody')) {
                return;
            }

            auditCurrentPage = page;
            const hasDiscrepancy = document.getElementById('auditFilter').value;
            const pageSize = document.getElementById('auditPageSize').value;
            const auditType = getAuditType();

            document.getElementById('auditResultsBody').innerHTML = '<tr><td colspan="8" class="text-center">加载中...</td></tr>';

            fetch(`api.php?action=getAuditResults&agent_uid=0&audit_type=${encodeURIComponent(auditType)}&has_discrepancy=${hasDiscrepancy}&page=${page}&page_size=${pageSize}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                if (data.status === 'success' && data.data) {
                    renderAuditResults(data.data);
                    renderAuditPagination(data.data);
                } else {
                    console.error('审计结果返回错误:', data);
                    document.getElementById('auditResultsBody').innerHTML = `<tr><td colspan="8" class="text-center">${data.message || '加载失败'}</td></tr>`;
                }
            })
            .catch(err => {
                console.error('获取审计结果失败:', err);
                document.getElementById('auditResultsBody').innerHTML = '<tr><td colspan="8" class="text-center">网络错误: ' + err.message + '</td></tr>';
            });
        }

        // 渲染审计结果
        function renderAuditResults(data) {
            const tbody = document.getElementById('auditResultsBody');

            if (!data.list || data.list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">暂无数据</td></tr>';
                return;
            }

            // 清空缓存
            auditDetailsCache = {};

            tbody.innerHTML = data.list.map(item => {
                // 缓存审计详情
                if (item.has_discrepancy && item.discrepancy_details && item.discrepancy_details.length > 0) {
                    auditDetailsCache[item.id] = { type: item.audit_type || 'upstream', details: item.discrepancy_details };
                }

                const statusBadge = item.audit_status === 'completed'
                    ? '<span class="badge badge-success">已完成</span>'
                    : item.audit_status === 'pending'
                    ? '<span class="badge badge-warning">待处理</span>'
                    : '<span class="badge badge-danger">失败</span>';

                const discrepancyBadge = item.has_discrepancy
                    ? `<span class="badge badge-danger">有差异 (${parseFloat(item.difference || 0).toFixed(2)})</span>`
                    : '<span class="badge badge-success">无差异</span>';
                const auditType = item.audit_type || 'upstream';
                const typeBadge = auditType === 'downstream'
                    ? '<span class="badge badge-info">下游套娃</span>'
                    : '<span class="badge badge-primary">上游密钥</span>';
                const compareLabel = auditType === 'downstream' ? '账本' : '源台';
                const localLabel = auditType === 'downstream' ? '当前余额' : '本地';

                return `
                    <tr>
                        <td>${item.id}</td>
                        <td>${item.customer_id}<div style="font-size:11px;color:var(--text-secondary);">${escapeHtml(item.customer_api_key || '')}</div></td>
                        <td>${typeBadge}</td>
                        <td>
                            <div style="font-weight:600;">${escapeHtml(item.agent_name || '')}</div>
                            <div style="font-size:11px;color:var(--text-secondary);">UID: ${item.agent_uid}</div>
                        </td>
                        <td>
                            <div style="font-weight:600;">${escapeHtml(item.project_name || '')}</div>
                            <div style="font-size:11px;color:var(--text-secondary);">ID: ${item.project_id}</div>
                        </td>
                        <td style="text-align:right;">
                            <span style="color:${parseFloat(item.difference || 0) > 0 ? 'var(--danger)' : parseFloat(item.difference || 0) < 0 ? 'var(--success)' : 'var(--text-secondary)'};font-weight:600;">
                                ${parseFloat(item.difference || 0) > 0 ? '+' : ''}${parseFloat(item.difference || 0).toFixed(2)}
                            </span>
                            <div style="font-size:11px;color:var(--text-secondary);">
                                ${compareLabel}: ${parseFloat(item.source_net || 0).toFixed(2)} / ${localLabel}: ${parseFloat(item.local_net || 0).toFixed(2)}
                            </div>
                        </td>
                        <td>${statusBadge} ${discrepancyBadge}</td>
                        <td style="text-align:center;">
                            ${item.has_discrepancy && item.discrepancy_details && item.discrepancy_details.length > 0
                                ? `<button class="btn btn-primary btn-sm" onclick="showAuditDetail(${item.id})">
                                    <i class="fas fa-list"></i> 详情
                                   </button>`
                                : '-'
                            }
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // 渲染分页
        function renderAuditPagination(data) {
            auditTotalPages = data.total_pages || 1;
            const container = document.getElementById('auditPagination');

            if (auditTotalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = '';

            // 上一页
            if (auditCurrentPage > 1) {
                html += `<button class="btn btn-outline btn-sm" onclick="refreshAuditResults(${auditCurrentPage - 1})">上一页</button>`;
            }

            // 页码
            const startPage = Math.max(1, auditCurrentPage - 2);
            const endPage = Math.min(auditTotalPages, auditCurrentPage + 2);

            if (startPage > 1) {
                html += `<button class="btn btn-outline btn-sm" onclick="refreshAuditResults(1)">1</button>`;
                if (startPage > 2) {
                    html += `<span style="padding:0 8px;color:var(--text-secondary);">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                const btnClass = i === auditCurrentPage ? 'btn-primary' : 'btn-outline';
                html += `<button class="btn ${btnClass} btn-sm" onclick="refreshAuditResults(${i})">${i}</button>`;
            }

            if (endPage < auditTotalPages) {
                if (endPage < auditTotalPages - 1) {
                    html += `<span style="padding:0 8px;color:var(--text-secondary);">...</span>`;
                }
                html += `<button class="btn btn-outline btn-sm" onclick="refreshAuditResults(${auditTotalPages})">${auditTotalPages}</button>`;
            }

            // 下一页
            if (auditCurrentPage < auditTotalPages) {
                html += `<button class="btn btn-outline btn-sm" onclick="refreshAuditResults(${auditCurrentPage + 1})">下一页</button>`;
            }

            html += `<span style="margin-left:16px;color:var(--text-secondary);font-size:13px;">共 ${data.total} 条</span>`;

            container.innerHTML = html;
        }

        // 显示差异详情
        function showAuditDetail(auditId) {
            try {
                const cached = auditDetailsCache[auditId];
                const auditType = cached && cached.type ? cached.type : 'upstream';
                const details = cached && cached.details ? cached.details : cached;

                if (!details || !Array.isArray(details) || details.length === 0) {
                    document.getElementById('auditDetailContent').innerHTML = '<div class="text-center text-muted">暂无差异详情</div>';
                    showModal('auditDetailModal');
                    return;
                }

                if (auditType === 'downstream') {
                    let html = '<div style="margin-bottom:16px;"><h4 style="margin-bottom:12px;">下游套娃客户余额差异</h4>';
                    details.forEach(item => {
                        if (item.type === 'downstream_balance_mismatch') {
                            html += `<div class="alert alert-warning">
                                <div>账本充值：${parseFloat(item.ledger_charge_total || 0).toFixed(2)}</div>
                                <div>账本提现：${parseFloat(item.ledger_withdraw_total || 0).toFixed(2)}</div>
                                <div>账本净额：${parseFloat(item.ledger_net || 0).toFixed(2)}</div>
                                <div>当前余额：${parseFloat(item.customer_balance || 0).toFixed(2)}</div>
                                <div>差额：${parseFloat(item.difference || 0).toFixed(2)}</div>
                            </div>`;
                        } else if (item.items && Array.isArray(item.items)) {
                            html += `<h5>${item.type === 'recent_ledger' ? '最近账本' : '异常账本'}</h5><div style="max-height:260px;overflow:auto;"><table class="table"><thead><tr><th>ID</th><th>方向</th><th>金额</th><th>余额前</th><th>余额后</th><th>时间</th></tr></thead><tbody>`;
                            item.items.forEach(row => {
                                html += `<tr><td>${row.id || ''}</td><td>${escapeHtml(row.direction || '')}</td><td>${parseFloat(row.project_amount || 0).toFixed(2)}</td><td>${parseFloat(row.project_balance_before || 0).toFixed(2)}</td><td>${parseFloat(row.project_balance_after || 0).toFixed(2)}</td><td>${escapeHtml(row.created_at || '')}</td></tr>`;
                            });
                            html += '</tbody></table></div>';
                        }
                    });
                    html += '</div>';
                    document.getElementById('auditDetailContent').innerHTML = html;
                    showModal('auditDetailModal');
                    return;
                }

                let html = `
                    <div style="margin-bottom:16px;">
                        <h4 style="margin-bottom:12px;">源台有但本地没有的记录（${details.length}条）</h4>
                        <div style="max-height:400px;overflow-y:auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>操作类型</th>
                                        <th>金额</th>
                                        <th>时间</th>
                                        <th>说明</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;

                const actionMap = {
                    'balance_add': '增加余额',
                    'balance_subtract': '减少余额',
                    'refund': '工单退款'
                };

                details.forEach(item => {
                    const action = item.action || 'unknown';
                    const amount = parseFloat(item.amount || 0);
                    const createdAt = item.created_at || '';
                    const description = item.description || '';

                    html += `
                        <tr>
                            <td>${actionMap[action] || action}</td>
                            <td style="text-align:right;">${amount.toFixed(2)}</td>
                            <td>${createdAt}</td>
                            <td>${description}</td>
                        </tr>
                    `;
                });

                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                document.getElementById('auditDetailContent').innerHTML = html;
                showModal('auditDetailModal');
            } catch (e) {
                console.error('解析差异详情失败:', e);
                console.error('错误堆栈:', e.stack);
                showToast('解析差异详情失败: ' + e.message, 'error');
            }
        }

        // ==================== 工具函数 ====================

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }
    </script>

</body>
</html>
