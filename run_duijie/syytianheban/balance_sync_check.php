<?php
/**
 * 源台余额同步检查
 * 
 * 检测是否存在：源台余额被消费，但本地记录未更新的情况
 */

$db_file = __DIR__ . '/爽歪歪寄生系统数据库-勿删勿清理.log';
$db = new PDO('sqlite:' . $db_file);

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║              源台余额一致性检查工具                        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// 获取所有绑定记录
$sql = "SELECT pak.*, p.name as project_name, p.source_project_id
        FROM project_api_keys pak
        LEFT JOIN projects p ON pak.project_id = p.id
        WHERE pak.status = 1";

$stmt = $db->prepare($sql);
$stmt->execute();
$keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "找到 " . count($keys) . " 个项目绑定\n\n";

// 检查最近的充值记录
$sql = "SELECT er.*, pak.source_api_key, pak.customer_id, pak.source_project_id, p.name as project_name
        FROM exchange_records er
        LEFT JOIN project_api_keys pak ON er.agent_uid = pak.agent_uid AND er.project_id = pak.project_id
        LEFT JOIN projects p ON er.project_id = p.id
        WHERE er.exchange_type = 'charge'
        ORDER BY er.created_at DESC
        LIMIT 10";

$stmt = $db->prepare($sql);
$stmt->execute();
$recent_charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "最近10笔充值记录分析:\n";
echo str_repeat("-", 100) . "\n";
printf("%-4s | %-10s | %-20s | %-10s | %-10s | %-10s | %-15s\n", 
    "ID", "用户", "项目", "充值金额", "记录余额", "当前余额", "时间");
echo str_repeat("-", 100) . "\n";

foreach ($recent_charges as $charge) {
    $recorded_balance = floatval($charge['after_balance']);
    $project_amount = floatval($charge['project_amount']);
    
    // 计算如果没有消费，当前余额应该是多少
    $expected_balance = $recorded_balance;
    
    printf("%-4d | %-10s | %-20s | %-10.2f | %-10.2f | %-10.2f | %-15s\n",
        $charge['id'],
        $charge['agent_uid'],
        mb_substr($charge['project_name'] ?? '', 0, 18),
        $project_amount,
        $recorded_balance,
        $expected_balance, // 这里应该是从源台实时查询
        $charge['created_at']
    );
}

echo "\n";
echo "⚠️  警告:\n";
echo "1. 如果用户在源台直接消费（开卡、充值等），本地记录的余额会过时\n";
echo "2. 本地 '记录余额' 是充值时的快照，不代表当前实际余额\n";
echo "3. '当前余额' 需要实时从源台查询（上面的值是模拟的）\n\n";

echo "建议:\n";
echo "1. 每次充值/提现前，先查询源台最新余额\n";
echo "2. 定期运行余额同步脚本，更新本地记录\n";
echo "3. 在源台增加余额变动回调通知\n\n";

// 显示代码中可能导致问题的位置
echo "=== 当前代码的问题 ===\n\n";
echo "问题1: before_balance 硬编码为0\n";
echo "位置: UniversalAPI.php 第1196行\n";
echo "影响: 无法追踪余额变化历史\n\n";

echo "问题2: after_balance 仅在充值时记录\n";
echo "影响: 如果源台余额被消费，本地记录过时\n\n";

echo "问题3: 没有余额同步机制\n";
echo "影响: 无法检测源台余额异常变动\n\n";

echo "=== 建议的改进方案 ===\n\n";

echo "方案1: 每次操作前查询源台最新余额\n";
echo "- 在 chargeToProject 前，先调用源台 API 查询当前余额\n";
echo "- 将查询结果作为 before_balance\n";
echo "- 这样可以保证余额链完整\n\n";

echo "方案2: 定期同步余额\n";
echo "- 创建定时任务，每小时同步一次源台余额\n";
echo "- 记录到 balance_sync_log 表\n";
echo "- 发现异常时发送告警\n\n";

echo "方案3: 源台回调通知\n";
echo "- 在源台增加余额变动事件\n";
echo "- 通过 Webhook 通知本地\n";
echo "- 本地收到通知后更新记录\n\n";

