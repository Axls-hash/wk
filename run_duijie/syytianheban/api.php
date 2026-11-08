<?php
/**
 * syytianheban（爽歪歪天河版）- 站内 API 接口
 *
 * 主要功能：
 * 1. 项目秘钥管理
 *    - autoInitProjects: 自动初始化所有启用的项目（静默绑定）
 *    - bindProjectKey: 为代理在项目上绑定/获取固定秘钥
 *    - getMyProjectKeys: 获取我的所有项目秘钥
 *    - getProjectKeyBalance: 查询项目秘钥余额
 *
 * 2. 余额兑换
 *    - chargeToProject: 从二开平台余额充值到项目秘钥
 *    - withdrawFromProject: 从项目秘钥提现到二开平台余额
 *    - getExchangeRecords: 获取兑换记录
 *    - getExchangeStats: 获取兑换统计
 *
 * 3. 项目管理（管理员专用）
 *    - getProjects: 获取项目列表
 *    - updateProject: 更新项目信息
 *    - deleteProject: 删除项目
 *    - syncProjects: 从源平台同步项目列表
 *
 * 4. 系统配置（管理员专用）
 *    - saveConfig: 保存系统配置
 *    - getConfig: 获取系统配置
 *
 * 核心改进（对比V2）：
 * - 从临时开卡/退卡改为固定秘钥绑定模式
 * - 支持余额双向兑换（充值/提现）
 * - 可配置每个项目的兑换比例
 * - 完整的消费追踪和统计功能
 *
 * @author 天河skyriver
 * @version 3.3.3
 * @since 2026-09-13
 */

// 清除之前的输出
ob_start();

require_once __DIR__ . '/ct.php';
require_once __DIR__ . '/UniversalAPI.php';

// 清除include产生的空行
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    // 初始化CT类和UniversalAPI类
    $ct = new CT();
    $api = new UniversalAPI();

    // 检查用户登录
    if (!$ct->isLogin()) {
        echo json_encode([
            'status' => 'error',
            'message' => '用户未登录'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取用户信息
    $userInfo = $ct->getUserInfo();
    $agent_uid = $userInfo['uid'];
    $is_admin = ($agent_uid == 1); // uid为1的是管理员

    // 获取操作类型
    $action = $_REQUEST['action'] ?? '';

    // 审计类型：upstream=审上游项目密钥，downstream=审下游套娃客户，all=全部
    $resolveAuditType = function ($default = 'upstream') {
        $audit_type = $_REQUEST['audit_type'] ?? $default;
        return in_array($audit_type, ['upstream', 'downstream', 'all'], true) ? $audit_type : $default;
    };

    // 套娃模式下当前平台自身即为源台：只禁止上游审计，允许下游套娃客户审计
    $assertAuditAvailable = function ($audit_type = 'upstream') use ($api) {
        if ($api->getConfig('nested_mode_enabled') != '1') {
            return $audit_type;
        }
        if ($audit_type === 'upstream') {
            throw new Exception('套娃模式下不支持上游客户审计，请使用下游套娃客户审计');
        }
        if ($audit_type === 'all') {
            return 'downstream';
        }
        return $audit_type;
    };

    switch ($action) {
        // ==================== 用户信息 ====================
        case 'getUserInfo':
            getUserInfo($ct, $agent_uid, $api);
            break;

        case 'get_balance':
        case 'getBalance':
            getCtBalance($ct);
            break;

        case 'check_balance':
        case 'checkBalance':
            checkCtBalance($ct);
            break;

        case 'update_balance':
        case 'updateBalance':
            if (!$is_admin) throw new Exception('无权限访问');
            updateCtBalance($ct);
            break;

        case 'get_rate':
        case 'getRate':
            getCtRate($ct);
            break;

        case 'create_order':
        case 'createOrder':
            throw new Exception('爽歪歪业务订单必须在源台客户专属链接内操作，本站不创建业务订单');
            break;

        case 'write_log':
        case 'writeLog':
            if (!$is_admin) throw new Exception('无权限访问');
            writeCtLog($ct);
            break;

        // ==================== 项目秘钥管理 ====================
        case 'autoInitProjects':
            autoInitProjects($ct, $api, $agent_uid);
            break;

        case 'bindProjectKey':
            bindProjectKey($ct, $api, $agent_uid);
            break;

        case 'getMyProjectKeys':
            getMyProjectKeys($api, $agent_uid);
            break;

        case 'getProjectKeyBalance':
            getProjectKeyBalance($api, $agent_uid);
            break;

        case 'getMyOpenApiKey':
            getMyOpenApiKey($api, $agent_uid);
            break;

        case 'resetMyOpenApiKey':
            resetMyOpenApiKey($api, $agent_uid);
            break;

        case 'getOpenApiUsageStats':
            getOpenApiUsageStats($api, $agent_uid);
            break;

        // ==================== 余额兑换 ====================
        case 'chargeToProject':
            chargeToProject($ct, $api, $agent_uid);
            break;

        case 'withdrawFromProject':
            withdrawFromProject($ct, $api, $agent_uid);
            break;

        case 'getExchangeRecords':
            getExchangeRecords($api, $agent_uid, $is_admin);
            break;

        case 'getMyLedger':
            getMyLedger($api, $agent_uid);
            break;

        case 'getExchangeStats':
            getExchangeStats($api, $agent_uid, $is_admin);
            break;

        // ==================== 项目管理（管理员） ====================
        case 'getProjects':
            getProjects($api, $is_admin);
            break;

        case 'addProject':
            if (!$is_admin) throw new Exception('无权限访问');
            addProjectManually($api);
            break;

        case 'updateProject':
            if (!$is_admin) throw new Exception('无权限访问');
            updateProject($api);
            break;

        case 'deleteProject':
            if (!$is_admin) throw new Exception('无权限访问');
            deleteProject($api);
            break;

        case 'syncProjects':
            if (!$is_admin) throw new Exception('无权限访问');
            syncProjectsFromSource($api);
            break;

        // ==================== 系统管理（管理员） ====================
        case 'getSystemOverview':
            if (!$is_admin) throw new Exception('无权限访问');
            getSystemOverview($api);
            break;

        case 'getAgentList':
            if (!$is_admin) throw new Exception('无权限访问');
            getAgentList($ct, $api);
            break;

        case 'getSystemAgentDetail':
            if (!$is_admin) throw new Exception('无权限访问');
            getSystemAgentDetail($ct, $api);
            break;

        case 'getSystemLedger':
            if (!$is_admin) throw new Exception('无权限访问');
            getSystemLedger($ct, $api);
            break;

        case 'getAgentDetail':
            if (!$is_admin) throw new Exception('无权限访问');
            getAgentDetail($api);
            break;

        case 'getAgentExchangeLogs':
            if (!$is_admin) throw new Exception('无权限访问');
            getAgentExchangeLogs($api);
            break;

        case 'reconcileTransactions':
            if (!$is_admin) throw new Exception('无权限访问');
            reconcileTransactions($api);
            break;

        case 'getTransactionStats':
            if (!$is_admin) throw new Exception('无权限访问');
            getTransactionStats($api);
            break;

        case 'reconcileFromSourceLogs':
            if (!$is_admin) throw new Exception('无权限访问');
            reconcileFromSourceLogs($api);
            break;

        case 'auditCustomerBalance':
            if (!$is_admin) throw new Exception('无权限访问');
            $assertAuditAvailable('upstream');
            auditCustomerBalance($api);
            break;

        case 'triggerAudit':
            if (!$is_admin) throw new Exception('无权限访问');
            $_REQUEST['audit_type'] = $assertAuditAvailable($resolveAuditType('upstream'));
            triggerAudit($api);
            break;

        case 'processAudit':
            if (!$is_admin) throw new Exception('无权限访问');
            $_REQUEST['audit_type'] = $assertAuditAvailable($resolveAuditType(''));
            processAudit($api);
            break;

        case 'getAuditResults':
            if (!$is_admin) throw new Exception('无权限访问');
            $_REQUEST['audit_type'] = $assertAuditAvailable($resolveAuditType(''));
            getAuditResults($api);
            break;

        case 'getAuditStats':
            if (!$is_admin) throw new Exception('无权限访问');
            $_REQUEST['audit_type'] = $assertAuditAvailable($resolveAuditType(''));
            getAuditStats($api);
            break;

        // ==================== 系统配置（管理员） ====================
        case 'saveConfig':
            if (!$is_admin) throw new Exception('无权限访问');
            saveConfig($api);
            break;

        case 'getConfig':
            if (!$is_admin) throw new Exception('无权限访问');
            getConfig($api);
            break;

        case 'getAnnouncement':
            getAnnouncement($api);
            break;

        case 'saveAnnouncement':
            if (!$is_admin) throw new Exception('无权限访问');
            saveAnnouncement($api);
            break;

        // ==================== 客户工单管理 ====================
        case 'submitCustomerTicket':
            assertTicketApiAvailable($api);
            submitCustomerTicket($ct, $api, $agent_uid);
            break;

        case 'getCustomerTickets':
            assertTicketApiAvailable($api);
            getCustomerTickets($ct, $api, $agent_uid, $is_admin);
            break;

        case 'getCustomerTicketDetail':
            assertTicketApiAvailable($api);
            getCustomerTicketDetail($ct, $api, $agent_uid, $is_admin);
            break;

        case 'replyCustomerTicket':
            assertTicketApiAvailable($api);
            replyCustomerTicket($ct, $api, $agent_uid, $is_admin);
            break;

        case 'reviewCompensationTicket':
            if (!$is_admin) throw new Exception('无权限访问');
            assertTicketApiAvailable($api);
            reviewCompensationTicket($ct, $api);
            break;

        default:
            echo json_encode([
                'status' => 'error',
                'message' => '无效的操作类型'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }

} catch (Throwable $e) {
    $err_msg = '[' . date('Y-m-d H:i:s') . '] GLOBAL ERROR: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents(__DIR__ . '/syytianheban_error.log', $err_msg, FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 用户信息函数 ====================

/**
 * 获取源平台API地址
 */
function getSourceApiUrl($api) {
    $api_url = $api->getConfig('source_api_url') ?: 'https://syyrun.com/api.php';
    if (strpos($api_url, 'http://') !== 0 && strpos($api_url, 'https://') !== 0) {
        $api_url = 'https://' . $api_url;
    }
    return $api_url;
}

function getOpenApiUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $path . '/openapi.php';
}

/**
 * 获取用户信息和余额
 */
function getUserInfo($ct, $agent_uid, $api) {
    try {
        $userInfo = $ct->getUserInfo();
        $balance = $ct->getBalance();

        // 获取用户费率（兑换费率）
        $exchange_rate = isset($userInfo['addprice']) ? floatval($userInfo['addprice']) : 1.0;
        $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);

        echo json_encode([
            'status' => 'success',
            'uid' => $agent_uid,
            'username' => $userInfo['username'] ?? '',
            'balance' => $balance,
            'role' => $userInfo['role'] ?? 'agent',
            'exchange_rate' => $exchange_rate,
            'base_rate' => $base_rate,
            'nested_mode_enabled' => $api->getConfig('nested_mode_enabled') === '1' ? 1 : 0
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取用户信息失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

function ctJsonResponse($code, $msg, $data = null) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

function requirePostRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('请使用POST请求');
    }
}

function getCtBalance($ct) {
    ctJsonResponse(200, '获取成功', ['balance' => $ct->getBalance()]);
}

function checkCtBalance($ct) {
    $required = floatval($_REQUEST['required'] ?? 0);
    if ($required < 0) {
        throw new Exception('所需金额不能小于0');
    }

    ctJsonResponse(200, '检查完成', $ct->checkBalance($required));
}

function updateCtBalance($ct) {
    requirePostRequest();

    $amount = floatval($_POST['amount'] ?? 0);
    $type = $_POST['type'] ?? 'add';
    $remark = trim($_POST['remark'] ?? '管理员操作');

    ctJsonResponse(200, '修改成功', $ct->updateBalance($amount, $type, $remark));
}

function getCtRate($ct) {
    $cid = intval($_REQUEST['cid'] ?? 0);
    ctJsonResponse(200, '获取成功', $ct->getRate($cid));
}

function createCtOrder($ct) {
    requirePostRequest();

    $cid = intval($_POST['cid'] ?? 0);
    if ($cid <= 0) {
        throw new Exception('课程ID不能为空');
    }

    $rate_info = $ct->getRate($cid);
    $order_data = [
        'cid' => $cid,
        'school' => $_POST['school'] ?? '',
        'name' => $_POST['name'] ?? '',
        'user' => $_POST['user'] ?? '',
        'pass' => $_POST['pass'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'kcname' => $_POST['kcname'] ?? '',
        'kcid' => $_POST['kcid'] ?? '',
        'remarks' => $_POST['remarks'] ?? '',
        'fees' => $rate_info['actual_price'] ?? 0
    ];

    ctJsonResponse(200, '创建成功', $ct->createOrder($order_data));
}

function writeCtLog($ct) {
    requirePostRequest();

    ctJsonResponse(200, '写入成功', ['success' => $ct->writeLog(
        $_POST['type'] ?? '管理员操作',
        $_POST['text'] ?? '',
        $_POST['money'] ?? '0'
    )]);
}

// ==================== 项目秘钥管理函数 ====================

/**
 * 自动初始化项目 - 为用户自动绑定所有启用的项目
 */
function autoInitProjects($ct, $api, $agent_uid) {
    try {
        // 获取源平台API密钥
        $source_api_key = $api->getConfig('source_api_key');
        if (empty($source_api_key)) {
            echo json_encode([
                'status' => 'error',
                'message' => '请先配置源平台API密钥'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取所有启用的项目
        $all_projects = $api->getProjects();
        $enabled_projects = array_filter($all_projects, function($project) {
            return $project['status'] == 1; // 只获取启用的项目
        });

        // 获取用户已绑定的项目
        $bound_keys = $api->getAgentProjectKeys($agent_uid);
        $bound_project_ids = array_column($bound_keys, 'project_id');

        // 找出未绑定的启用项目
        $unbind_projects = [];
        foreach ($enabled_projects as $project) {
            if (!in_array($project['id'], $bound_project_ids)) {
                $unbind_projects[] = $project;
            }
        }

        // 自动绑定未绑定的项目 - 增强容错机制
        $success_count = 0;
        $failed_count = 0;
        $results = [];

        foreach ($unbind_projects as $project) {
            // 每个项目独立处理，确保一个失败不影响其他项目
            try {
                error_log("[自动初始化] 开始处理项目: {$project['name']} (ID:{$project['id']})");

                // 使用独立的try-catch包裹bindProjectApiKey调用
                try {
                    $result = $api->bindProjectApiKey($agent_uid, $project['id'], $source_api_key, 0);

                    $success_count++;
                    $results[] = [
                        'project_id' => $project['id'],
                        'project_name' => $project['name'],
                        'status' => 'success',
                        'api_key' => $result['source_api_key']
                    ];

                    // 写入日志
                    $log_text = '自动初始化项目: ' . $project['name'] . ' (ID:' . $project['id'] . ') - 成功';
                    $ct->writeLog('自动初始化项目', $log_text, '0');

                    error_log("[自动初始化] 项目 {$project['name']} 绑定成功");

                } catch (Exception $bind_error) {
                    // 绑定失败，记录但继续处理下一个
                    $failed_count++;
                    $error_msg = $bind_error->getMessage();

                    $results[] = [
                        'project_id' => $project['id'],
                        'project_name' => $project['name'],
                        'status' => 'failed',
                        'error' => $error_msg
                    ];

                    // 写入错误日志
                    $log_text = '自动初始化项目: ' . $project['name'] . ' (ID:' . $project['id'] . ') - 失败: ' . $error_msg;
                    $ct->writeLog('自动初始化项目失败', $log_text, '1');

                    error_log("[自动初始化] 项目 {$project['name']} 绑定失败: {$error_msg}");
                }

            } catch (Exception $outer_error) {
                // 外层异常捕获，确保循环继续
                $failed_count++;
                $error_msg = $outer_error->getMessage();

                $results[] = [
                    'project_id' => $project['id'],
                    'project_name' => $project['name'],
                    'status' => 'failed',
                    'error' => '外层错误: ' . $error_msg
                ];

                error_log("[自动初始化] 项目 {$project['name']} 处理异常: {$error_msg}");
            }

            // 每处理一个项目后，短暂休眠避免API请求过快
            usleep(100000); // 0.1秒
        }

        echo json_encode([
            'status' => 'success',
            'message' => "自动初始化完成：成功 {$success_count} 个，失败 {$failed_count} 个",
            'data' => [
                'total_enabled' => count($enabled_projects),
                'already_bound' => count($bound_project_ids),
                'newly_bound' => $success_count,
                'failed' => $failed_count,
                'results' => $results
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '自动初始化失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 为代理在项目上绑定/获取固定秘钥
 */
function bindProjectKey($ct, $api, $agent_uid) {
    $project_id = intval($_POST['project_id'] ?? 0);
    // 支持设置初始余额，默认为0（允许设置为0）
    $initial_balance = isset($_POST['initial_balance']) ? floatval($_POST['initial_balance']) : 0;

    if ($project_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目ID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 验证初始余额
    if ($initial_balance < 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '初始余额不能为负数'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取源平台API密钥
    $source_api_key = $api->getConfig('source_api_key');
    if (empty($source_api_key)) {
        echo json_encode([
            'status' => 'error',
            'message' => '请先配置源平台API密钥'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 绑定项目秘钥，允许设置初始余额为0
    $result = $api->bindProjectApiKey($agent_uid, $project_id, $source_api_key, $initial_balance);

    echo json_encode([
        'status' => 'success',
        'message' => '项目秘钥绑定成功',
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);

    // 写入日志
    $log_text = '项目ID:' . $project_id . ',秘钥:' . $result['source_api_key'] . ',初始余额:' . $initial_balance . '元';
    $ct->writeLog('绑定项目秘钥', $log_text, '0');
}

/**
 * 获取我的所有项目秘钥
 */
function getMyProjectKeys($api, $agent_uid) {
    $keys = $api->getAgentProjectKeys($agent_uid);

    echo json_encode([
        'status' => 'success',
        'data' => $keys
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取当前用户的套娃对接密钥
 */
function getMyOpenApiKey($api, $agent_uid) {
    $key = $api->getOrCreateOpenApiKey($agent_uid);
    echo json_encode([
        'status' => 'success',
        'data' => [
            'api_url' => getOpenApiUrl(),
            'api_key' => $key['api_key'],
            'status' => intval($key['status'] ?? 1),
            'updated_at' => $key['updated_at'] ?? ''
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 重置当前用户的套娃对接密钥
 */
function resetMyOpenApiKey($api, $agent_uid) {
    $key = $api->resetOpenApiKey($agent_uid);
    echo json_encode([
        'status' => 'success',
        'message' => '对接密钥已重置',
        'data' => [
            'api_url' => getOpenApiUrl(),
            'api_key' => $key['api_key'],
            'status' => intval($key['status'] ?? 1),
            'updated_at' => $key['updated_at'] ?? ''
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取套娃对接调用统计
 */
function getOpenApiUsageStats($api, $agent_uid) {
    echo json_encode([
        'status' => 'success',
        'data' => $api->getOpenApiUsageStats($agent_uid, intval($_GET['limit'] ?? 20))
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 批量查询所有项目余额（使用 getCustomerInfo 的 customer_ids 批量接口）
 */
function getProjectKeyBalance($api, $agent_uid) {
    $admin_api_key = $api->getConfig('source_api_key');
    if (empty($admin_api_key)) {
        echo json_encode(['status' => 'error', 'message' => '未配置管理员API密钥'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取该用户所有项目秘钥
    $keys = $api->getAgentProjectKeys($agent_uid);
    if (empty($keys)) {
        echo json_encode(['status' => 'success', 'balances' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 收集所有 customer_id，建立 customer_id => local_project_id 映射
    $customer_ids = [];
    $cid_to_pid = [];
    foreach ($keys as $k) {
        $cid = intval($k['customer_id'] ?? 0);
        if ($cid > 0) {
            $customer_ids[] = $cid;
            $cid_to_pid[$cid] = intval($k['project_id']);
        }
    }

    if (empty($customer_ids)) {
        echo json_encode(['status' => 'success', 'balances' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 套娃模式调用上游 openapi.php；直连模式调用真实源台 api.php。
    if ($api->getConfig('nested_mode_enabled') === '1') {
        $data = callSourceAPI('getCustomerInfo', [
            'api_key' => $admin_api_key,
            'customer_ids' => implode(',', $customer_ids)
        ]);
    } else {
        $source_api_url = getSourceApiUrl($api);
        $url = $source_api_url . '?action=getCustomerInfo'
            . '&api_key=' . urlencode($admin_api_key)
            . '&customer_ids=' . implode(',', $customer_ids);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!$resp) {
            echo json_encode(['status' => 'error', 'message' => '查询余额失败: ' . $curl_error], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = json_decode($resp, true);
    }

    if (!isset($data['status']) || $data['status'] !== 'success') {
        echo json_encode(['status' => 'error', 'message' => $data['message'] ?? '查询余额失败'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 解析结果：单个返回 customer 对象，多个返回 customers 数组
    $balance_map = []; // local project_id => balance
    if (isset($data['customer'])) {
        // 单个结果
        $cid = intval($data['customer']['id'] ?? 0);
        if (isset($cid_to_pid[$cid])) {
            $balance_map[$cid_to_pid[$cid]] = floatval($data['customer']['balance'] ?? 0);
        }
    } elseif (isset($data['customers']) && is_array($data['customers'])) {
        // 批量结果
        foreach ($data['customers'] as $c) {
            $cid = intval($c['id'] ?? 0);
            if (isset($cid_to_pid[$cid])) {
                $balance_map[$cid_to_pid[$cid]] = floatval($c['balance'] ?? 0);
            }
        }
    }

    echo json_encode(['status' => 'success', 'balances' => $balance_map], JSON_UNESCAPED_UNICODE);
}

// ==================== 余额兑换函数 ====================

/**
 * 原子增减当前代理的本站余额。
 * 资金更新成功即视为成功，日志写入失败不能反向影响资金状态。
 */
function updateAgentBalanceAtomic($ct, $agent_uid, $amount, $type, $remark) {
    $amount = round(floatval($amount), 4);
    $agent_uid = intval($agent_uid);
    if ($amount <= 0 || $agent_uid <= 0) {
        throw new Exception('余额变动参数不正确');
    }

    $DB = $ct->getDB();
    $before_row = $DB->get_row("SELECT money FROM qingka_wangke_user WHERE uid='{$agent_uid}' LIMIT 1");
    if (!$before_row) {
        throw new Exception('用户不存在');
    }

    if ($type === 'sub') {
        $ok = $DB->query("UPDATE qingka_wangke_user SET money=money-'{$amount}' WHERE uid='{$agent_uid}' AND money>='{$amount}'");
        $log_money = '-' . $amount;
        $log_type = 'Key充值扣费';
    } elseif ($type === 'add') {
        $ok = $DB->query("UPDATE qingka_wangke_user SET money=money+'{$amount}' WHERE uid='{$agent_uid}'");
        $log_money = '+' . $amount;
        $log_type = 'Key余额转回';
    } else {
        throw new Exception('余额操作类型不正确');
    }

    $affected = $DB->get_row('SELECT ROW_COUNT() AS affected_rows');
    if (!$ok || intval($affected['affected_rows'] ?? 0) !== 1) {
        $latest = $DB->get_row("SELECT money FROM qingka_wangke_user WHERE uid='{$agent_uid}' LIMIT 1");
        throw new Exception($type === 'sub'
            ? '本站余额不足，当前：' . number_format(floatval($latest['money'] ?? 0), 4, '.', '')
            : '本站余额返还失败');
    }

    $after_row = $DB->get_row("SELECT money FROM qingka_wangke_user WHERE uid='{$agent_uid}' LIMIT 1");
    $after = floatval($after_row['money'] ?? 0);
    $before = $type === 'sub' ? $after + $amount : $after - $amount;
    try {
        if (function_exists('wlog')) {
            wlog($agent_uid, $log_type, $remark, $log_money);
        }
    } catch (Throwable $log_error) {
        error_log('[Key余额日志] ' . $log_error->getMessage());
    }
    return ['old_balance' => $before, 'new_balance' => $after];
}

/**
 * 从二开平台余额充值到项目秘钥
 */
function chargeToProject($ct, $api, $agent_uid) {
    $project_id = intval($_POST['project_id'] ?? 0);
    // 新版前端直接提交要充值的公里数；amount 仅保留兼容旧前端。
    $project_amount = round(floatval($_POST['project_amount'] ?? 0), 4);
    $legacy_platform_amount = round(floatval($_POST['amount'] ?? 0), 4);

    // 参数验证
    if ($project_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目ID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取项目信息
    $project = $api->getProjectById($project_id);
    if (!$project) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目不存在'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 检查项目是否启用
    if ($project['status'] != 1) {
        echo json_encode([
            'status' => 'error',
            'message' => '该项目暂不可用'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 检查是否允许充值
    if (isset($project['allow_charge']) && $project['allow_charge'] != 1) {
        echo json_encode([
            'status' => 'error',
            'message' => '该项目已禁止充值'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取项目单价和系统基准费率
    $project_price = floatval($project['price'] ?? 1);
    $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);

    // 获取用户费率
    $userInfo = $ct->getUserInfo();
    $user_addprice = floatval($userInfo['addprice'] ?? $base_rate);

    // 实际单价 = 项目单价 × 用户费率 / 基准费率
    if ($base_rate <= 0) {
        echo json_encode(['status' => 'error', 'message' => '基准费率配置不正确'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $actual_price = round($project_price * $user_addprice / $base_rate, 4);
    if ($actual_price <= 0) {
        echo json_encode(['status' => 'error', 'message' => '项目计费单价不正确'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($project_amount > 0) {
        $amount = round($project_amount * $actual_price, 4);
    } else {
        // 旧版请求的 amount 表示本站扣款，反算项目到账公里数。
        $amount = $legacy_platform_amount;
        $project_amount = $amount > 0 ? round($amount / $actual_price, 4) : 0;
    }
    if ($project_amount <= 0 || $amount <= 0) {
        echo json_encode(['status' => 'error', 'message' => '充值公里数必须大于0'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 用精确计算后的本站扣款检查代理余额。
    $agent_balance = $ct->getBalance();
    if ($agent_balance + 0.00001 < $amount) {
        echo json_encode([
            'status' => 'error',
            'message' => '余额不足，需要: ' . number_format($amount, 4, '.', '') . '元，当前余额: ' . number_format($agent_balance, 4, '.', '') . '元'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 检查是否已绑定项目秘钥
    $projectKey = $api->getProjectKey($agent_uid, $project_id);
    if (!$projectKey) {
        // 自动绑定项目秘钥
        $source_api_key = $api->getConfig('source_api_key');
        if (empty($source_api_key)) {
            echo json_encode([
                'status' => 'error',
                'message' => '请先配置源平台API密钥'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $projectKey = $api->bindProjectApiKey($agent_uid, $project_id, $source_api_key);
    }

    // 先调用源台充值（成功后才扣除本地余额，防止源台失败导致用户余额损失）
    $exchange_result = $api->chargeToProject($agent_uid, $project_id, $amount, $actual_price, null, $project_amount);
    $transaction_id = $exchange_result['transaction_id'] ?? '';
    $project_amount = $exchange_result['project_amount'] ?? 0;

    // 检查是否是重放请求（幂等性返回）
    $is_replay = $exchange_result['is_replay'] ?? false;

    // 源台充值成功，再扣除代理余额
    try {
        $result = updateAgentBalanceAtomic($ct, $agent_uid, $amount, 'sub', '充值项目公里数-' . $project['name']);
    } catch (Exception $balance_error) {
        // 本地余额扣除失败，需要回滚源台操作
        error_log("[充值回滚] 本地余额扣除失败，尝试回滚源台: " . $balance_error->getMessage());

        // 获取项目秘钥用于回滚
        $projectKey = $api->getProjectKey($agent_uid, $project_id);
        $rollback_ok = false;
        if ($projectKey && isset($projectKey['customer_id']) && !empty($transaction_id)) {
            $source_api_key = $api->getConfig('source_api_key');
            $rollback_result = callSourceAPI('adjustCustomerBalance', [
                'api_key' => $source_api_key,
                'customer_id' => $projectKey['customer_id'],
                'project_id' => $projectKey['source_project_id'],
                'amount' => -$project_amount, // 撤回已增加的项目公里数
                'remark' => "V3充值回滚：本地余额扣除失败，回滚交易ID: {$transaction_id}",
                'transaction_id' => $transaction_id . '_rollback'
            ]);

            if (isset($rollback_result['status']) && $rollback_result['status'] === 'success') {
                $rollback_ok = true;
                error_log("[充值回滚] 源台回滚成功");
            } else {
                error_log("[充值回滚] 源台回滚失败: " . ($rollback_result['message'] ?? '未知错误'));
            }
        }

        // 返回错误给用户
        echo json_encode([
            'status' => 'error',
            'message' => $rollback_ok
                ? '本站扣款失败，已自动撤销源台 Key 充值：' . $balance_error->getMessage()
                : '本站扣款失败且源台 Key 回滚失败，请立即按交易ID对账：' . $transaction_id
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'message' => $is_replay ? '充值成功（重放请求）' : '充值成功',
        'data' => [
            'platform_amount' => $amount,
            'project_amount' => $exchange_result['project_amount'],
            'actual_price' => $actual_price,
            'agent_balance' => $result['new_balance'],
            'project_balance' => $exchange_result['after_balance'],
            'transaction_id' => $transaction_id,
            'is_replay' => $is_replay
        ]
    ], JSON_UNESCAPED_UNICODE);

}

/**
 * 从项目秘钥提现到二开平台余额
 */
function withdrawFromProject($ct, $api, $agent_uid) {
    $project_id = intval($_POST['project_id'] ?? 0);
    $amount = round(floatval($_POST['amount'] ?? 0), 4);

    // 参数验证
    if ($project_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目ID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($amount <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '提现公里数必须大于0'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取项目信息
    $project = $api->getProjectById($project_id);
    if (!$project) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目不存在'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取项目秘钥
    $projectKey = $api->getProjectKey($agent_uid, $project_id);
    if (!$projectKey) {
        echo json_encode([
            'status' => 'error',
            'message' => '未找到项目秘钥'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 余额检查由源平台 API 负责验证，本地不再查询（避免多余 HTTP 请求）

    // 获取项目单价和系统基准费率
    $project_price = floatval($project['price'] ?? 1);
    $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);

    // 获取用户费率
    $userInfo = $ct->getUserInfo();
    $user_addprice = floatval($userInfo['addprice'] ?? $base_rate);

    // 实际单价 = 项目单价 × 用户费率 / 基准费率
    if ($base_rate <= 0) {
        echo json_encode(['status' => 'error', 'message' => '基准费率配置不正确'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $actual_price = round($project_price * $user_addprice / $base_rate, 4);
    if ($actual_price <= 0) {
        echo json_encode(['status' => 'error', 'message' => '项目计费单价不正确'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 记录兑换（注意：实际从源平台提现需要调用源平台提现接口）
    $exchange_result = $api->withdrawFromProject($agent_uid, $project_id, $amount, $actual_price);
    $transaction_id = $exchange_result['transaction_id'] ?? '';
    $platform_amount = $exchange_result['platform_amount'] ?? 0;

    // 检查是否是重放请求（幂等性返回）
    $is_replay = $exchange_result['is_replay'] ?? false;

    // 增加代理余额
    try {
        $result = updateAgentBalanceAtomic($ct, $agent_uid, $platform_amount, 'add', '项目公里数提现-' . $project['name']);
    } catch (Exception $balance_error) {
        // 本地余额增加失败，需要回滚源台操作（把扣除的项目公里数加回去）
        error_log("[提现回滚] 本地余额增加失败，尝试回滚源台: " . $balance_error->getMessage());

        // 获取项目秘钥用于回滚
        $projectKey = $api->getProjectKey($agent_uid, $project_id);
        $rollback_ok = false;
        if ($projectKey && isset($projectKey['customer_id']) && !empty($transaction_id)) {
            $source_api_key = $api->getConfig('source_api_key');
            $rollback_result = callSourceAPI('adjustCustomerBalance', [
                'api_key' => $source_api_key,
                'customer_id' => $projectKey['customer_id'],
                'project_id' => $projectKey['source_project_id'],
                'amount' => $amount, // 恢复刚才扣除的项目公里数
                'remark' => "V3提现回滚：本地余额增加失败，回滚交易ID: {$transaction_id}",
                'transaction_id' => $transaction_id . '_rollback'
            ]);

            if (isset($rollback_result['status']) && $rollback_result['status'] === 'success') {
                $rollback_ok = true;
                error_log("[提现回滚] 源台回滚成功");
            } else {
                error_log("[提现回滚] 源台回滚失败: " . ($rollback_result['message'] ?? '未知错误'));
            }
        }

        // 返回错误给用户
        echo json_encode([
            'status' => 'error',
            'message' => $rollback_ok
                ? '本站返款失败，已自动将余额恢复到源台 Key：' . $balance_error->getMessage()
                : '本站返款失败且源台 Key 回滚失败，请立即按交易ID对账：' . $transaction_id
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'message' => $is_replay ? '提现成功（重放请求）' : '提现成功',
        'data' => [
            'project_amount' => $amount,
            'platform_amount' => $platform_amount,
            'actual_price' => $actual_price,
            'agent_balance' => $result['new_balance'],
            'project_balance' => $exchange_result['after_balance'],
            'transaction_id' => $transaction_id,
            'is_replay' => $is_replay
        ]
    ], JSON_UNESCAPED_UNICODE);

}

/**
 * 获取兑换记录
 */
function getMyLedger($api, $agent_uid) {
    $data = $api->getSystemLedger([
        'page' => $_GET['page'] ?? 1,
        'page_size' => $_GET['limit'] ?? 50,
        'agent_uid' => $agent_uid,
        'project_id' => $_GET['project_id'] ?? 0,
        'source' => $_GET['source'] ?? '',
        'direction' => $_GET['direction'] ?? '',
        'date_start' => $_GET['date_start'] ?? '',
        'date_end' => $_GET['date_end'] ?? '',
        'keyword' => $_GET['keyword'] ?? ''
    ]);
    echo json_encode(['status' => 'success', 'data' => $data['list'], 'summary' => $data['summary'], 'pagination' => $data['pagination']], JSON_UNESCAPED_UNICODE);
}

function getExchangeRecords($api, $agent_uid, $is_admin) {
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
    $limit = intval($_GET['limit'] ?? 50);

    // 管理员可以查看所有记录，普通用户只能查看自己的
    $uid = $is_admin ? null : $agent_uid;

    $records = $api->getExchangeRecords($uid, $project_id, $limit);

    echo json_encode([
        'status' => 'success',
        'data' => $records
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取兑换统计
 */
function getExchangeStats($api, $agent_uid, $is_admin) {
    // 管理员可以查看全局统计，普通用户只能查看自己的
    $uid = $is_admin ? null : $agent_uid;

    $stats = $api->getExchangeStatistics($uid);

    echo json_encode([
        'status' => 'success',
        'data' => $stats
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 项目管理函数（管理员） ====================

/**
 * 获取项目列表
 */
function getProjects($api, $is_admin = false) {
    if ($is_admin) {
        $projects = $api->getAllProjects();
    } else {
        $projects = $api->getProjects();
    }

    echo json_encode([
        'status' => 'success',
        'projects' => $projects
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 手动补充项目。正常情况优先使用 getProjectInfo 自动同步。
 */
function addProjectManually($api) {
    $name = trim($_POST['name'] ?? '');
    $source_project_id = intval($_POST['source_project_id'] ?? 0);
    $redirect_url = trim($_POST['redirect_url'] ?? '');
    $price = floatval($_POST['price'] ?? 1);
    if ($name === '' || $source_project_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '项目名称和源项目ID不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($price <= 0) {
        echo json_encode(['status' => 'error', 'message' => '项目单价必须大于0'], JSON_UNESCAPED_UNICODE);
        return;
    }
    foreach ($api->getAllProjects() as $row) {
        if (intval($row['source_project_id'] ?? 0) === $source_project_id) {
            echo json_encode(['status' => 'error', 'message' => '该源项目ID已经存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    $id = $api->addProject($name, getSourceApiUrl($api), $source_project_id, 1.0, '手动添加爽歪歪项目', $redirect_url);
    $api->updateProject($id, ['price' => $price, 'status' => 1, 'allow_charge' => 1]);
    echo json_encode(['status' => 'success', 'message' => '项目添加成功', 'project_id' => $id], JSON_UNESCAPED_UNICODE);
}

/**
 * 更新项目
 */
function updateProject($api) {
    $project_id = intval($_POST['project_id'] ?? 0);

    if ($project_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目ID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $data = [];
    if (isset($_POST['name'])) $data['name'] = $_POST['name'];
    if (isset($_POST['source_api_url'])) $data['source_api_url'] = $_POST['source_api_url'];
    if (isset($_POST['source_project_id'])) $data['source_project_id'] = intval($_POST['source_project_id']);
    if (isset($_POST['redirect_url'])) $data['redirect_url'] = trim($_POST['redirect_url']);
    if (isset($_POST['docs_url'])) $data['docs_url'] = trim($_POST['docs_url']);
    if (isset($_POST['price'])) $data['price'] = floatval($_POST['price']);
    if (isset($_POST['allow_charge'])) $data['allow_charge'] = intval($_POST['allow_charge']);
    if (isset($_POST['description'])) $data['description'] = $_POST['description'];
    if (isset($_POST['status'])) $data['status'] = intval($_POST['status']);

    $result = $api->updateProject($project_id, $data);

    if ($result) {
        echo json_encode([
            'status' => 'success',
            'message' => '项目更新成功'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => '项目更新失败'
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 删除项目
 */
function deleteProject($api) {
    $project_id = intval($_POST['project_id'] ?? 0);

    if (empty($project_id)) {
        echo json_encode([
            'status' => 'error',
            'message' => '项目ID不能为空'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $api->deleteProject($project_id);
        echo json_encode([
            'status' => 'success',
            'message' => '项目删除成功'
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '删除失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 从源平台同步项目列表
 */
function syncProjectsFromSource($api) {
    $source_api_url = getSourceApiUrl($api);
    $nested_mode = $api->getConfig('nested_mode_enabled') === '1';
    $source_api_key = trim(strval($api->getConfig('source_api_key') ?: ''));
    $source_projects = [];

    if ($nested_mode) {
        if ($source_api_key === '') {
            echo json_encode(['status' => 'error', 'message' => '请先配置上游同系统对接密钥'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // 套娃模式需要同步上一层已设置的计费价格，所以使用同系统扩展接口。
        $data = callSourceAPI('getUserApiKeys', ['api_key' => $source_api_key]);
        if (($data['status'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => '从上游同系统获取项目失败：' . ($data['message'] ?? '未知错误')], JSON_UNESCAPED_UNICODE);
            return;
        }
        foreach (($data['apiKeys'] ?? []) as $row) {
            $source_projects[] = [
                'project_id' => intval($row['project_id'] ?? 0),
                'project_name' => trim(strval($row['project_name'] ?? '')),
                'description' => trim(strval($row['description'] ?? '')),
                'redirect_url' => trim(strval($row['redirect_url'] ?? '')),
                'docs_url' => trim(strval($row['docs_url'] ?? '')),
                'price' => floatval($row['price'] ?? 1),
                'has_price' => true
            ];
        }
    } else {
        // 爽歪歪第二份文档新增的公开接口，无需 API Key。
        $data = callSourceAPI('getProjectInfo');
        if (($data['status'] ?? '') !== 'success') {
            echo json_encode(['status' => 'error', 'message' => '从爽歪歪获取项目列表失败：' . ($data['message'] ?? '未知错误')], JSON_UNESCAPED_UNICODE);
            return;
        }
        foreach (($data['projects'] ?? []) as $summary) {
            $source_project_id = intval($summary['id'] ?? 0);
            if ($source_project_id <= 0) continue;
            // 列表响应只保证有基础信息，再按 ID 取详情获得 redirect_url/docs_url。
            $detail_response = callSourceAPI('getProjectInfo', ['project_id' => $source_project_id]);
            $detail = (($detail_response['status'] ?? '') === 'success' && !empty($detail_response['project']))
                ? $detail_response['project'] : $summary;
            $source_projects[] = [
                'project_id' => $source_project_id,
                'project_name' => trim(strval($detail['name'] ?? ($summary['name'] ?? ''))),
                'description' => trim(strval($detail['description'] ?? ($summary['description'] ?? ''))),
                'redirect_url' => trim(strval($detail['redirect_url'] ?? '')),
                'docs_url' => trim(strval($detail['docs_url'] ?? '')),
                // 公开项目接口没有价格，不允许同步时覆盖本站定价。
                'price' => 1,
                'has_price' => false
            ];
        }
    }

    $added = 0;
    $updated = 0;
    $errors = [];

    // 提前获取所有项目，避免在循环中重复查询
    $allProjects = $api->getAllProjects();

    foreach ($source_projects as $apiKeyInfo) {
        try {
            $source_project_id = intval($apiKeyInfo['project_id'] ?? 0);
            $project_name = $apiKeyInfo['project_name'] ?? '';
            $description = $apiKeyInfo['description'] ?? '';
            $redirect_url = $apiKeyInfo['redirect_url'] ?? '';
            $docs_url = $apiKeyInfo['docs_url'] ?? '';
            $project_price = isset($apiKeyInfo['price']) ? floatval($apiKeyInfo['price']) : 1;

            if (empty($project_name) || $source_project_id <= 0) {
                continue;
            }

            // source_api_url 保持为配置的源平台API地址
            $source_api_url = getSourceApiUrl($api);

            // 检查项目是否已存在
            $existing = null;

            foreach ($allProjects as $proj) {
                if (isset($proj['source_project_id']) && $proj['source_project_id'] == $source_project_id) {
                    $existing = $proj;
                    break;
                }
            }

            if ($existing) {
                // 更新现有项目（同时更新访问地址）
                $update_data = [
                    'name' => $project_name,
                    'source_api_url' => $source_api_url,
                    'redirect_url' => $redirect_url,
                    'docs_url' => $docs_url,
                    'description' => $description
                ];
                if (!empty($apiKeyInfo['has_price'])) {
                    $update_data['price'] = $project_price;
                }
                $api->updateProject($existing['id'], $update_data);
                $updated++;
            } else {
                // 新项目先禁用：管理员核对本站单价后再开启，避免以1元默认价误放单。
                $new_project_id = $api->addProject(
                    $project_name,
                    $source_api_url,
                    $source_project_id,
                    1.0,
                    $description,
                    $redirect_url,
                    $docs_url
                );
                $api->updateProject($new_project_id, [
                    'status' => 0,
                    'price' => !empty($apiKeyInfo['has_price']) ? $project_price : 1
                ]);
                $added++;
            }
        } catch (Exception $e) {
            $errors[] = $project_name . ': ' . $e->getMessage();
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => "同步完成！新增: {$added}个, 更新: {$updated}个。新项目默认禁用，请设置本站单价后开启",
        'added' => $added,
        'updated' => $updated,
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 配置管理函数（管理员） ====================

/**
 * 保存配置
 */
function saveConfig($api) {
    $config_key = $_POST['config_key'] ?? '';
    $config_value = $_POST['config_value'] ?? '';
    $description = $_POST['description'] ?? '';

    if (empty($config_key)) {
        echo json_encode([
            'status' => 'error',
            'message' => '配置键不能为空'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $api->setConfig($config_key, $config_value, $description);

    echo json_encode([
        'status' => 'success',
        'message' => '配置保存成功'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取配置
 */
function getConfig($api) {
    $config_key = $_GET['config_key'] ?? '';

    if (empty($config_key)) {
        // 返回所有配置
        $configs = $api->getAllConfig();
        echo json_encode([
            'status' => 'success',
            'configs' => $configs
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 返回指定配置
        $value = $api->getConfig($config_key);
        echo json_encode([
            'status' => 'success',
            'config_key' => $config_key,
            'config_value' => $value
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 系统管理函数 ====================

function getUsernameByUid($ct, $uid) {
    $ctDB = $ct->getDB();
    $uid = intval($uid);
    try {
        $row = $ctDB->get_row("SELECT username FROM qingka_wangke_users WHERE id = {$uid}");
        if ($row && isset($row['username'])) return $row['username'];
    } catch (Exception $e) {}
    try {
        $row = $ctDB->get_row("SELECT username FROM qingka_wangke_user WHERE uid = {$uid}");
        if ($row && isset($row['username'])) return $row['username'];
    } catch (Exception $e) {}
    return "用户{$uid}";
}

function getSystemOverview($api) {
    echo json_encode(['status' => 'success', 'data' => $api->getSystemOverview()], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取系统代理列表（管理员专用）
 */
function getAgentList($ct, $api) {
    try {
        $page = intval($_GET['page'] ?? 1);
        $page_size = intval($_GET['page_size'] ?? 20);
        $keyword = trim($_GET['keyword'] ?? '');
        $matchedUserIds = ($keyword !== '' && !ctype_digit($keyword)) ? findUserIdsByKeyword($ct, $keyword) : [];
        $result = $api->getSystemAgentStats($page, $page_size, $keyword, $matchedUserIds);
        foreach ($result['list'] as &$agent) {
            $agent['username'] = getUsernameByUid($ct, $agent['uid']);
        }
        unset($agent);
        echo json_encode(['status' => 'success', 'data' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => '获取系统管理列表失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function getSystemAgentDetail($ct, $api) {
    $agent_uid = intval($_GET['agent_uid'] ?? 0);
    if ($agent_uid <= 0) {
        echo json_encode(['status' => 'error', 'message' => '代理UID无效'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $data = $api->getSystemAgentDetail($agent_uid);
    $data['agent'] = ['uid' => $agent_uid, 'username' => getUsernameByUid($ct, $agent_uid)];
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
}

function findUserIdsByKeyword($ct, $keyword) {
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $DB = $ct->getDB();
    $ids = [];
    foreach ([['qingka_wangke_users', 'id'], ['qingka_wangke_user', 'uid']] as $meta) {
        try {
            $safe = addslashes($keyword);
            $res = $DB->query("SELECT {$meta[1]} AS uid FROM {$meta[0]} WHERE username LIKE '%{$safe}%' LIMIT 100");
            while ($row = $DB->fetch($res)) {
                $ids[] = intval($row['uid']);
            }
        } catch (Exception $e) {}
    }
    return array_values(array_unique(array_filter($ids)));
}

function getSystemLedger($ct, $api) {
    $filters = [
        'page' => $_GET['page'] ?? 1,
        'page_size' => $_GET['page_size'] ?? 20,
        'agent_uid' => $_GET['agent_uid'] ?? 0,
        'project_id' => $_GET['project_id'] ?? 0,
        'source' => $_GET['source'] ?? '',
        'direction' => $_GET['direction'] ?? '',
        'date_start' => $_GET['date_start'] ?? '',
        'date_end' => $_GET['date_end'] ?? '',
        'keyword' => $_GET['keyword'] ?? ''
    ];
    $keyword = trim($filters['keyword']);
    if ($keyword !== '' && !ctype_digit($keyword)) {
        $matchedUserIds = findUserIdsByKeyword($ct, $keyword);
        if (!empty($matchedUserIds)) {
            $filters['matched_user_ids'] = $matchedUserIds;
        }
    }
    $data = $api->getSystemLedger($filters);
    foreach ($data['list'] as &$row) {
        $row['username'] = getUsernameByUid($ct, $row['owner_uid']);
    }
    unset($row);
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
}
function getAgentDetail($api) {
    $agent_uid = intval($_GET['agent_uid'] ?? 0);

    if ($agent_uid <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '代理UID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        // 获取该代理的所有项目秘钥（内部已查询每个秘钥的余额）
        $keys = $api->getAgentProjectKeys($agent_uid);

        echo json_encode([
            'status' => 'success',
            'data' => $keys
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取代理详情失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 公告管理函数 ====================

/**
 * 获取公告内容（所有已登录用户可访问）
 */
function getAnnouncement($api) {
    $content    = $api->getConfig('announcement')            ?? '';
    $updated_at = $api->getConfig('announcement_updated_at') ?? '';
    $popup_mode = $api->getConfig('announcement_popup_mode') ?? 'once';
    echo json_encode([
        'status'      => 'success',
        'content'     => $content,
        'updated_at'  => $updated_at,
        'popup_mode'  => $popup_mode,
        'has_content' => !empty(trim($content))
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存公告内容（管理员专用）
 */
function saveAnnouncement($api) {
    $content    = $_POST['content']    ?? '';
    $popup_mode = $_POST['popup_mode'] ?? 'once';
    if (!in_array($popup_mode, ['once', 'always'])) {
        $popup_mode = 'once';
    }
    $api->setConfig('announcement',            $content,    '系统公告内容');
    $api->setConfig('announcement_updated_at', date('Y-m-d H:i:s'), '公告最后更新时间');
    $api->setConfig('announcement_popup_mode', $popup_mode, '公告弹出模式：once/always');
    echo json_encode([
        'status'  => 'success',
        'message' => '公告保存成功'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取代理的充值提现日志（管理员专用）
 */
function getAgentExchangeLogs($api) {
    $agent_uid = intval($_GET['agent_uid'] ?? 0);
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
    $limit = intval($_GET['limit'] ?? 100);

    if ($agent_uid <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => '代理UID无效'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $records = $api->getExchangeRecords($agent_uid, $project_id, $limit);

        echo json_encode([
            'status' => 'success',
            'data' => $records
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取充值日志失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 客户工单管理函数 ====================

/**
 * 爽歪歪本次公开文档仅提供客户和余额管理，没有工单接口。
 * 套娃模式下上游是同系统 openapi.php，才能继续使用原有工单功能。
 */
function assertTicketApiAvailable($api) {
    if ($api->getConfig('nested_mode_enabled') !== '1') {
        throw new Exception('爽歪歪公开文档暂未提供工单接口；直连模式下无法提交或查询源台工单');
    }
}

/**
 * 调用源平台API的辅助函数
 */
function callSourceAPI($action, $params = []) {
    global $api;
    $api_url = getSourceApiUrl($api);

    $params['action'] = $action;

    // 余额调整与工单写操作使用 POST。
    $post_actions = ['adjustCustomerBalance', 'submitCustomerTicket', 'replyCustomerTicket', 'reviewCompensationTicket'];
    $use_post = in_array($action, $post_actions);

    $ch = curl_init();

    if ($use_post) {
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } else {
        $url_with_params = $api_url . '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url_with_params);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $header_key = strval($params['api_key'] ?? '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: UniversalAPI-V3/1.0',
        'Accept: application/json',
        'X-API-Key: ' . $header_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[callSourceAPI] 网络错误: $error");
        return ['status' => 'error', 'message' => '网络错误: ' . $error];
    }

    // 调试日志不得记录完整管理密钥。
    $safe_params = $params;
    if (isset($safe_params['api_key'])) $safe_params['api_key'] = '***';
    if (isset($safe_params['customer_project_api_key'])) $safe_params['customer_project_api_key'] = '***';
    error_log("[callSourceAPI] 请求: $action, 参数: " . json_encode($safe_params));
    error_log("[callSourceAPI] 响应: " . substr($response, 0, 500));

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => '响应解析错误'];
    }

    return $data;
}

/**
 * 提交客户工单
 * 客户通过项目秘钥提交工单
 */
function submitCustomerTicket($ct, $api, $agent_uid) {
    try {
        $project_id = intval($_POST['project_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $image_data = $_POST['image_data'] ?? '';
        $compensation_amount = $_POST['compensation_amount'] ?? '';

        if ($project_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '请选择项目'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($type) || !in_array($type, ['suggestion', 'bug', 'compensation'])) {
            echo json_encode(['status' => 'error', 'message' => '工单类型无效'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => '请输入工单标题'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($description)) {
            echo json_encode(['status' => 'error', 'message' => '请输入工单描述'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($type === 'compensation' && empty($compensation_amount)) {
            echo json_encode(['status' => 'error', 'message' => '补偿类型工单必须填写补偿金额'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取项目秘钥
        $projectKey = $api->getProjectKey($agent_uid, $project_id);
        if (!$projectKey) {
            echo json_encode(['status' => 'error', 'message' => '未绑定该项目秘钥，请先绑定'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 调用源平台API提交工单
        $params = [
            'project_id' => $projectKey['source_project_id'],
            'api_key' => $projectKey['source_api_key'],
            'type' => $type,
            'title' => $title,
            'description' => $description
        ];

        if (!empty($image_data)) {
            $params['image_data'] = $image_data;
        }

        if (!empty($compensation_amount)) {
            $params['compensation_amount'] = $compensation_amount;
        }

        $result = callSourceAPI('submitCustomerTicket', $params);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '提交失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取客户工单列表
 */
/**
 * 获取客户工单列表
 * @param bool $is_admin 是否为管理员
 */
/**
 * 获取客户工单列表
 * @param bool $is_admin 是否为管理员
 */
function getCustomerTickets($ct, $api, $agent_uid, $is_admin = false) {
    try {
        $project_id = intval($_GET['project_id'] ?? 0);
        $status = $_GET['status'] ?? '';
        $type = $_GET['type'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));

        // ==================== 获取当前用户绑定的所有项目工单 ====================
        // 管理员和普通用户都使用项目秘钥认证，只查看自己绑定项目的工单
        // 如果传入了project_id，只获取该项目的工单
        if ($project_id > 0) {
            $projectKey = $api->getProjectKey($agent_uid, $project_id);
            if (!$projectKey) {
                echo json_encode(['status' => 'error', 'message' => '未绑定该项目秘钥'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $params = [
                'project_id' => $projectKey['source_project_id'],
                'api_key' => $projectKey['source_api_key'],
                'page' => $page,
                'limit' => $limit
            ];

            if (!empty($status)) {
                $params['status'] = $status;
            }
            if (!empty($type)) {
                $params['type'] = $type;
            }

            $result = callSourceAPI('getCustomerTickets', $params);
            // 统一返回格式，将 list 转换为 tickets，并添加本平台的 project_id
            if (isset($result['status']) && $result['status'] === 'success' && isset($result['data']['list'])) {
                foreach ($result['data']['list'] as $index => $ticket) {
                    $result['data']['list'][$index]['project_id'] = $project_id;
                    $result['data']['list'][$index]['project_name'] = $projectKey['project_name'];
                }
                $result['data']['tickets'] = $result['data']['list'];
                unset($result['data']['list']);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            return;
        }

        // ==================== 用户模式：获取所有绑定项目的工单并合并（并发请求） ====================
        // 获取用户所有绑定的项目
        $projectKeys = $api->getAgentProjectKeys($agent_uid);
        
        if (empty($projectKeys)) {
            echo json_encode([
                'status' => 'success',
                'message' => '暂无绑定项目',
                'data' => [
                    'tickets' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit
                ]
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 使用 multi_curl 并发请求所有项目的工单
        $api_url = getSourceApiUrl($api);
        $mh = curl_multi_init();
        $curl_handles = [];
        $project_map = []; // 存储 curl 对应的项目信息

        foreach ($projectKeys as $index => $projectKey) {
            $params = [
                'action' => 'getCustomerTickets',
                'project_id' => $projectKey['source_project_id'],
                'api_key' => $projectKey['source_api_key'],
                'page' => 1,
                'limit' => 100
            ];

            if (!empty($status)) {
                $params['status'] = $status;
            }
            if (!empty($type)) {
                $params['type'] = $type;
            }

            $ch = curl_init();
            $url_with_params = $api_url . '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url_with_params);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: UniversalAPI-V3/1.0',
                'Accept: application/json'
            ]);

            curl_multi_add_handle($mh, $ch);
            $curl_handles[$index] = $ch;
            $project_map[$index] = $projectKey;
        }

        // 并发执行所有请求
        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        // 收集结果
        $all_tickets = [];

        // 获取代理用户名
        $ctDB = $ct->getDB();
        $user_row = $ctDB->get_row("SELECT username FROM qingka_wangke_users WHERE id = " . intval($agent_uid));
        $agent_name = $user_row['username'] ?? '';

        foreach ($curl_handles as $index => $ch) {
            $response = curl_multi_getcontent($ch);
            $projectKey = $project_map[$index];

            $result = json_decode($response, true);

            if (isset($result['status']) && $result['status'] === 'success' && isset($result['data']['list'])) {
                // 为每个工单添加项目信息和代理信息
                foreach ($result['data']['list'] as $t_index => $ticket) {
                    $result['data']['list'][$t_index]['project_name'] = $projectKey['project_name'];
                    $result['data']['list'][$t_index]['project_id'] = $projectKey['project_id'];
                    // 添加代理信息（当前用户就是代理）
                    $result['data']['list'][$t_index]['agent_uid'] = $agent_uid;
                    $result['data']['list'][$t_index]['agent_name'] = $agent_name;
                }

                $all_tickets = array_merge($all_tickets, $result['data']['list']);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // 按创建时间倒序排序
        usort($all_tickets, function($a, $b) {
            $time_a = strtotime($a['created_at'] ?? '0');
            $time_b = strtotime($b['created_at'] ?? '0');
            return $time_b - $time_a;
        });

        // 分页处理
        $total_count = count($all_tickets);
        $offset = ($page - 1) * $limit;
        $paged_tickets = array_slice($all_tickets, $offset, $limit);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'tickets' => $paged_tickets,
                'total' => $total_count,
                'page' => $page,
                'limit' => $limit
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
/**
 * 获取客户工单详情
 * @param bool $is_admin 是否为代理（上级）
 */
function getCustomerTicketDetail($ct, $api, $agent_uid, $is_admin = false) {
    try {
        $ticket_id = intval($_GET['ticket_id'] ?? 0);
        $project_id = intval($_GET['project_id'] ?? 0);

        if ($ticket_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '工单ID无效'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ==================== 使用项目秘钥查看工单详情 ====================
        // 管理员和普通用户都使用项目秘钥认证
        if ($project_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '请选择项目'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取项目秘钥
        $projectKey = $api->getProjectKey($agent_uid, $project_id);
        if (!$projectKey) {
            echo json_encode(['status' => 'error', 'message' => '未绑定该项目秘钥'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 调用源平台API
        $params = [
            'ticket_id' => $ticket_id,
            'project_id' => $projectKey['source_project_id'],
            'api_key' => $projectKey['source_api_key']
        ];

        $result = callSourceAPI('getCustomerTicketDetail', $params);

        // 在返回的工单数据中添加本平台的 project_id
        if (isset($result['status']) && $result['status'] === 'success' && isset($result['data']['ticket'])) {
            $result['data']['ticket']['project_id'] = $project_id;
            $result['data']['ticket']['project_name'] = $projectKey['project_name'];
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
/**
 * 回复工单
 * @param bool $is_admin 是否为代理（上级）
 */
function replyCustomerTicket($ct, $api, $agent_uid, $is_admin = false) {
    try {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $project_id = intval($_POST['project_id'] ?? 0);
        $content = $_POST['content'] ?? '';
        $image_data = $_POST['image_data'] ?? '';

        if ($ticket_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '工单ID无效'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($content) && empty($image_data)) {
            echo json_encode(['status' => 'error', 'message' => '请输入回复内容或上传图片'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ==================== 使用项目秘钥回复工单 ====================
        // 管理员和普通用户都使用项目秘钥认证
        error_log("[回复工单] agent_uid=$agent_uid, project_id=$project_id, ticket_id=$ticket_id");

        if ($project_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '请选择项目'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取项目秘钥
        $projectKey = $api->getProjectKey($agent_uid, $project_id);
        error_log("[回复工单] projectKey查询结果: " . ($projectKey ? '找到' : '未找到'));
        if ($projectKey) {
            error_log("[回复工单] source_project_id=" . ($projectKey['source_project_id'] ?? 'null') . ", source_api_key=" . substr($projectKey['source_api_key'] ?? '', 0, 20) . "...");
        }

        if (!$projectKey) {
            echo json_encode(['status' => 'error', 'message' => '未绑定该项目秘钥'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 调用源平台API - 使用项目秘钥
        $params = [
            'ticket_id' => $ticket_id,
            'project_id' => $projectKey['source_project_id'],
            'api_key' => $projectKey['source_api_key']
        ];

        if (!empty($content)) {
            $params['content'] = $content;
        }
        if (!empty($image_data)) {
            $params['image_data'] = $image_data;
        }

        $result = callSourceAPI('replyCustomerTicket', $params);

        // 调试日志
        error_log("[回复工单] 请求参数: " . json_encode($params));
        error_log("[回复工单] 源平台响应: " . json_encode($result));

        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '回复失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
function reviewCompensationTicket($ct, $api) {
    try {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $review_result = $_POST['review_result'] ?? '';
        $review_note = $_POST['review_note'] ?? '';

        if ($ticket_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '工单ID无效'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!in_array($review_result, ['approved', 'rejected'])) {
            echo json_encode(['status' => 'error', 'message' => '审核结果无效'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取管理员的客户管理秘钥
        // 这里需要通过CT类获取当前管理员的api_key
        // 暂时使用Cookie方式，通过源平台API验证

        // 调用源平台API（使用Cookie方式）
        $params = [
            'ticket_id' => $ticket_id,
            'review_result' => $review_result
        ];

        if (!empty($review_note)) {
            $params['review_note'] = $review_note;
        }

        // 使用cookie转发
        $api_url = getSourceApiUrl($api);

        $params['action'] = 'reviewCompensationTicket';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // 转发Cookie
        $cookie_header = '';
        foreach ($_COOKIE as $name => $value) {
            $cookie_header .= $name . '=' . $value . '; ';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: UniversalAPI-V3/1.0',
            'Accept: application/json',
            'Cookie: ' . trim($cookie_header)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode([
                'status' => 'error',
                'message' => '响应解析错误'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '审核失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 交易对账函数（管理员专用） ====================

/**
 * 对账：查询并处理pending状态的交易
 */
function reconcileTransactions($api) {
    try {
        $limit = intval($_GET['limit'] ?? 10);
        $result = $api->reconcilePendingTransactions($limit);

        echo json_encode([
            'status' => 'success',
            'message' => "对账完成：共处理 {$result['total']} 条，成功 {$result['success']} 条，失败 {$result['failed']} 条",
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '对账失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取交易状态统计
 */
function getTransactionStats($api) {
    try {
        $stats = $api->getTransactionStatusStats();

        echo json_encode([
            'status' => 'success',
            'data' => $stats
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取统计失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 从源台日志对账（找出白嫖记录）
 */
function reconcileFromSourceLogs($api) {
    try {
        // 获取源台API密钥
        $source_api_key = $api->getConfig('source_api_key');
        if (empty($source_api_key)) {
            echo json_encode([
                'status' => 'error',
                'message' => '请先配置源平台API密钥'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取参数
        $date_start = $_GET['date_start'] ?? ''; // 格式: 2026-01-01
        $date_end = $_GET['date_end'] ?? '';     // 格式: 2026-12-31
        $agent_uid = intval($_GET['agent_uid'] ?? 0); // 指定代理，0表示所有
        $page = intval($_GET['page'] ?? 1);     // 页码
        $per_page = intval($_GET['per_page'] ?? 100); // 每页数量

        // 调用对账方法
        $result = $api->reconcileFromSourceLogs($source_api_key, $date_start, $date_end, $agent_uid, $page, $per_page);

        echo json_encode([
            'status' => 'success',
            'message' => $result['message'] ?? '对账完成',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '对账失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 审计客户：对比源台净流入和本地净充值
 */
function auditCustomerBalance($api) {
    try {
        // 获取源台API密钥
        $source_api_key = $api->getConfig('source_api_key');
        if (empty($source_api_key)) {
            echo json_encode([
                'status' => 'error',
                'message' => '请先配置源平台API密钥'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 获取参数
        $agent_uid = intval($_GET['agent_uid'] ?? 0); // 指定代理，0表示所有

        // 调用审计方法
        $result = $api->auditCustomerBalance($source_api_key, $agent_uid);

        echo json_encode([
            'status' => 'success',
            'message' => $result['message'] ?? '审计完成',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '审计失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 客户审计函数（管理员专用） ====================

/**
 * 触发审计：将指定代理的所有客户标记为待审计状态
 * 如果不指定代理，则标记所有客户
 */
function triggerAudit($api) {
    try {
        $agent_uid = intval($_POST['agent_uid'] ?? $_REQUEST['agent_uid'] ?? 0);
        $audit_type = $_REQUEST['audit_type'] ?? 'upstream';

        $result = $api->triggerAudit($agent_uid, $audit_type);

        echo json_encode([
            'status' => 'success',
            'message' => $result['message'] ?? '已触发审计',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '触发审计失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 处理审计：批量处理待审计的客户
 * 每次处理指定数量的客户
 */
function processAudit($api) {
    try {
        $limit = intval($_POST['limit'] ?? $_REQUEST['limit'] ?? 10);
        $audit_type = $_REQUEST['audit_type'] ?? '';

        $result = $api->processAudit($limit, $audit_type);

        echo json_encode([
            'status' => 'success',
            'message' => $result['message'] ?? '审计处理完成',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '处理审计失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取审计结果：分页获取审计记录
 * 可以按代理、是否有差异筛选
 */
function getAuditResults($api, $ct = null) {
    try {
        global $ct;
        $agent_uid = intval($_GET['agent_uid'] ?? 0);
        $has_discrepancy = isset($_GET['has_discrepancy']) ? intval($_GET['has_discrepancy']) : -1; // -1=全部, 0=无差异, 1=有差异
        $page = intval($_GET['page'] ?? 1);
        $page_size = intval($_GET['page_size'] ?? 20);
        $audit_type = $_REQUEST['audit_type'] ?? '';

        $result = $api->getAuditResults($agent_uid, $has_discrepancy, $page, $page_size, $audit_type);

        // 为每个审计记录添加代理用户名
        if (isset($result['list']) && is_array($result['list'])) {
            // 收集所有代理UID
            $agent_uids = array_unique(array_column($result['list'], 'agent_uid'));
            $agent_names = [];

            // 批量获取用户名
            if (!empty($agent_uids)) {
                $ctDB = $ct->getDB();
                foreach ($agent_uids as $uid) {
                    $uid = intval($uid);
                    try {
                        $row = $ctDB->get_row("SELECT username FROM qingka_wangke_users WHERE id = {$uid}");
                        if ($row && isset($row['username'])) {
                            $agent_names[$uid] = $row['username'];
                        } else {
                            $row = $ctDB->get_row("SELECT username FROM qingka_wangke_user WHERE uid = {$uid}");
                            if ($row && isset($row['username'])) {
                                $agent_names[$uid] = $row['username'];
                            } else {
                                $agent_names[$uid] = "用户{$uid}";
                            }
                        }
                    } catch (Exception $e) {
                        $agent_names[$uid] = "用户{$uid}";
                    }
                }
            }

            // 添加用户名到每个记录
            foreach ($result['list'] as &$item) {
                $item['agent_name'] = $agent_names[$item['agent_uid']] ?? "用户{$item['agent_uid']}";
            }
            unset($item);
        }

        echo json_encode([
            'status' => 'success',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取审计结果失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取审计统计：返回审计相关的统计数据
 */
function getAuditStats($api) {
    try {
        $audit_type = $_REQUEST['audit_type'] ?? '';
        $result = $api->getAuditStats($audit_type);

        echo json_encode([
            'status' => 'success',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => '获取审计统计失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
