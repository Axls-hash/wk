<?php
/**
 * syytianheban 同系统套娃对接 API
 *
 * 通过用户对接密钥鉴权，让当前平台作为上游源台被其它 syytianheban 平台对接。
 * 首期支持项目同步、开户、余额调整、余额查询。
 */
ob_start();
require_once __DIR__ . '/ct.php';
require_once __DIR__ . '/UniversalAPI.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    $ct = new CT();
    $api = new UniversalAPI();
    $action = $_REQUEST['action'] ?? '';
    $api_key = $_REQUEST['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');

    if (empty($api_key)) {
        jsonResponse(['status' => 'error', 'message' => '缺少api_key']);
    }

    $ticket_actions = ['submitCustomerTicket', 'getCustomerTickets', 'getCustomerTicketDetail', 'replyCustomerTicket', 'reviewCompensationTicket'];
    $auth_customer = null;
    $openKey = $api->getUserByOpenApiKey($api_key);
    if ($openKey) {
        $owner_uid = intval($openKey['uid']);
    } elseif (in_array($action, $ticket_actions)) {
        $auth_customer = $api->getOpenApiCustomerByApiKey($api_key);
        if (!$auth_customer) {
            jsonResponse(['status' => 'error', 'message' => 'api_key无效']);
        }
        $owner_uid = intval($auth_customer['owner_uid']);
    } else {
        jsonResponse(['status' => 'error', 'message' => 'api_key无效']);
    }

    $api->logOpenApiCall(
        $owner_uid,
        $action ?: 'unknown',
        $auth_customer ? 'customer' : 'owner',
        intval($auth_customer['id'] ?? ($_REQUEST['customer_id'] ?? 0)),
        intval($auth_customer['project_id'] ?? ($_REQUEST['project_id'] ?? 0)),
        'success',
        '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    switch ($action) {
        case 'getUserApiKeys':
            getUserApiKeys($api);
            break;
        case 'generateCustomer':
        case 'generateCustomerApi':
            generateCustomerApiV2($ct, $api, $owner_uid);
            break;
        case 'adjustCustomerBalance':
            adjustCustomerBalanceV2($ct, $api, $owner_uid);
            break;
        case 'getCustomerInfo':
            getCustomerInfoV2($api, $owner_uid);
            break;
        case 'deleteCustomerApi':
            deleteCustomerApiV2($ct, $api, $owner_uid);
            break;
        case 'enableCustomer':
            enableCustomerV2($api, $owner_uid);
            break;
        case 'submitCustomerTicket':
            submitCustomerTicket($api, $owner_uid, $auth_customer);
            break;
        case 'getCustomerTickets':
            getCustomerTickets($api, $owner_uid, $auth_customer);
            break;
        case 'getCustomerTicketDetail':
            getCustomerTicketDetail($api, $owner_uid, $auth_customer);
            break;
        case 'replyCustomerTicket':
            replyCustomerTicket($api, $owner_uid, $auth_customer);
            break;
        case 'reviewCompensationTicket':
            reviewCompensationTicket($api, $owner_uid);
            break;
        default:
            jsonResponse(['status' => 'error', 'message' => '无效的操作类型']);
    }
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/syytianheban_error.log', '[' . date('Y-m-d H:i:s') . '] OPENAPI ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
}

function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getUserApiKeys($api) {
    $projects = $api->getProjects();
    $apiKeys = [];
    foreach ($projects as $project) {
        $apiKeys[] = [
            'project_id' => intval($project['id']),
            'project_name' => $project['name'],
            'description' => $project['description'] ?? '',
            'redirect_url' => $project['redirect_url'] ?? '',
            'docs_url' => $project['docs_url'] ?? '',
            'price' => floatval($project['price'] ?? 1)
        ];
    }

    jsonResponse([
        'status' => 'success',
        'apiKeys' => $apiKeys
    ]);
}

function syyOpenApiProjectPricing($ct, $api, $owner_uid, $project) {
    $owner = getHostUser($ct, $owner_uid);
    if (!$owner) {
        throw new Exception('对接用户不存在');
    }
    $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);
    $user_rate = floatval($owner['addprice'] ?? $base_rate);
    $project_price = floatval($project['price'] ?? 1);
    $actual_price = $base_rate > 0 ? ($project_price * $user_rate / $base_rate) : $project_price;
    if ($actual_price <= 0) {
        throw new Exception('项目计费单价配置不正确');
    }
    return [
        'owner' => $owner,
        'base_rate' => $base_rate,
        'user_rate' => $user_rate,
        'project_price' => $project_price,
        'actual_price' => $actual_price
    ];
}

function syyOpenApiResolveCustomer($api, $owner_uid, $allow_disabled = false) {
    $customer_id = intval($_REQUEST['customer_id'] ?? 0);
    if ($customer_id <= 0 && !empty($_REQUEST['customer_project_api_key'])) {
        $row = $api->getOpenApiCustomerByApiKey(trim($_REQUEST['customer_project_api_key']));
        if ($row && intval($row['owner_uid']) === intval($owner_uid)) {
            return $row;
        }
    }
    if ($customer_id <= 0) {
        throw new Exception('请提供有效的客户ID（customer_id 或 customer_project_api_key）');
    }
    return $allow_disabled
        ? $api->getOpenApiCustomerAnyStatus($customer_id, null, $owner_uid)
        : $api->getOpenApiCustomer($customer_id, null, $owner_uid);
}

/**
 * 与爽歪歪 generateCustomerApi 同协议的套娃开户。
 * 每个本地客户都会在上游创建独立客户，避免多个下游共用同一上游余额。
 */
function generateCustomerApiV2($ct, $api, $owner_uid) {
    $project_id = intval($_REQUEST['project_id'] ?? 0);
    $initial_balance = round(floatval($_REQUEST['balance'] ?? 0), 4);
    if ($project_id <= 0 || $initial_balance < 0) {
        jsonResponse(['status' => 'error', 'message' => '项目ID或初始余额不正确']);
    }

    $project = $api->getProjectById($project_id);
    if (!$project || intval($project['status']) !== 1) {
        jsonResponse(['status' => 'error', 'message' => '项目不存在或已禁用']);
    }

    try {
        $pricing = syyOpenApiProjectPricing($ct, $api, $owner_uid, $project);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $platform_amount = round($initial_balance * $pricing['actual_price'], 4);
    if ($initial_balance > 0 && floatval($pricing['owner']['money'] ?? 0) < $platform_amount) {
        jsonResponse(['status' => 'error', 'message' => '项目余额不足']);
    }

    $transaction_id = trim($_REQUEST['transaction_id'] ?? ($_REQUEST['request_id'] ?? ''));
    if ($transaction_id === '') {
        $transaction_id = 'open_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }
    $existing = $api->getOpenApiLedgerByTransactionId($owner_uid, $transaction_id);
    if ($existing && intval($existing['customer_id'] ?? 0) > 0) {
        $customer = $api->getOpenApiCustomerAnyStatus(intval($existing['customer_id']), $project_id, $owner_uid);
        if ($customer) {
            jsonResponse(['status' => 'success', 'message' => '客户生成成功（重放请求）', 'customer' => syyOpenApiFormatCustomer($customer, $project), 'is_replay' => true]);
        }
    }

    $source_project_id = intval($project['source_project_id'] ?? $project_id);
    $upstream = callConfiguredUpstream($api, 'generateCustomerApi', [
        'project_id' => $source_project_id,
        'balance' => $initial_balance,
        'transaction_id' => $transaction_id
    ]);
    if (($upstream['status'] ?? '') !== 'success' || empty($upstream['customer']['id']) || empty($upstream['customer']['api_key'])) {
        jsonResponse(['status' => 'error', 'message' => $upstream['message'] ?? '上游开户失败或未返回客户信息']);
    }

    $source_customer = $upstream['customer'];
    $platform_balance = ['old_balance' => floatval($pricing['owner']['money']), 'new_balance' => floatval($pricing['owner']['money'])];
    $platform_debited = false;
    try {
        if ($platform_amount > 0) {
            $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_amount, 'sub', '爽歪歪套娃开户初始余额-' . ($project['name'] ?? '项目'));
            $platform_debited = true;
        }
        $customer = $api->createOpenApiCustomer($owner_uid, $project_id, $initial_balance, [
            'customer_id' => intval($source_customer['id']),
            'project_id' => $source_project_id,
            'api_key' => strval($source_customer['api_key']),
            'real_access_key' => strval($source_customer['real_access_key'] ?? $source_customer['api_key']),
            'customer_link' => strval($source_customer['customer_link'] ?? '')
        ]);
    } catch (Exception $e) {
        if ($platform_debited) {
            try { updateHostUserBalance($ct, $owner_uid, $platform_amount, 'add', '爽歪歪套娃开户失败回滚'); } catch (Exception $ignore) {}
        }
        callConfiguredUpstream($api, 'deleteCustomerApi', ['customer_id' => intval($source_customer['id'])]);
        jsonResponse(['status' => 'error', 'message' => '本地开户失败，已尝试回滚上游：' . $e->getMessage()]);
    }

    $api->addOpenApiLedger([
        'owner_uid' => $owner_uid, 'action' => 'generateCustomerApi', 'customer_id' => $customer['id'],
        'project_id' => $project_id, 'project_name' => $project['name'] ?? '', 'direction' => 'charge',
        'project_amount' => $initial_balance, 'project_balance_before' => 0, 'project_balance_after' => $initial_balance,
        'platform_amount' => -$platform_amount, 'platform_balance_before' => $platform_balance['old_balance'],
        'platform_balance_after' => $platform_balance['new_balance'], 'project_price' => $pricing['project_price'],
        'user_rate' => $pricing['user_rate'], 'base_rate' => $pricing['base_rate'],
        'actual_price' => $pricing['actual_price'], 'formula' => "{$initial_balance} × {$pricing['actual_price']} = {$platform_amount}",
        'remark' => '套娃开户初始余额', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'transaction_id' => $transaction_id
    ]);

    jsonResponse(['status' => 'success', 'message' => '客户生成成功', 'customer' => syyOpenApiFormatCustomer($customer, $project), 'transaction_id' => $transaction_id]);
}

function adjustCustomerBalanceV2($ct, $api, $owner_uid) {
    try {
        $customer = syyOpenApiResolveCustomer($api, $owner_uid, false);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    if (!$customer) {
        jsonResponse(['status' => 'error', 'message' => '客户不存在、已禁用或无权限']);
    }
    $project_id = intval($customer['project_id']);
    if (!empty($_REQUEST['project_id']) && intval($_REQUEST['project_id']) !== $project_id) {
        jsonResponse(['status' => 'error', 'message' => '客户与项目不匹配']);
    }
    $amount = round(floatval($_REQUEST['amount'] ?? 0), 4);
    if ($amount == 0) {
        jsonResponse(['status' => 'error', 'message' => '调整金额不能为0']);
    }

    $project = $api->getProjectById($project_id);
    try {
        $pricing = syyOpenApiProjectPricing($ct, $api, $owner_uid, $project);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $platform_amount = round(abs($amount) * $pricing['actual_price'], 4);
    if ($amount > 0 && floatval($pricing['owner']['money'] ?? 0) < $platform_amount) {
        jsonResponse(['status' => 'error', 'message' => '余额不足，无法完成本层扣款']);
    }
    if ($amount < 0 && floatval($customer['balance']) + $amount < -0.0001) {
        jsonResponse(['status' => 'error', 'message' => '客户项目余额不足']);
    }

    $transaction_id = trim($_REQUEST['transaction_id'] ?? ($_REQUEST['request_id'] ?? ''));
    if ($transaction_id === '') {
        $transaction_id = 'adj_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }
    $existing = $api->getOpenApiLedgerByTransactionId($owner_uid, $transaction_id);
    if ($existing) {
        $current = $api->getOpenApiCustomer($customer['id'], $project_id, $owner_uid);
        jsonResponse(['status' => 'success', 'message' => '余额调整成功（重放请求）', 'balance_before' => floatval($current['balance']), 'balance_after' => floatval($current['balance']), 'transaction_id' => $transaction_id, 'is_replay' => true]);
    }

    $upstream_customer_id = intval($customer['upstream_customer_id'] ?? 0);
    $upstream_project_id = intval($customer['upstream_project_id'] ?? ($project['source_project_id'] ?? 0));
    if ($upstream_customer_id <= 0 || $upstream_project_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '该客户是旧版数据，缺少独立上游映射，请重新开户']);
    }

    $upstream = callConfiguredUpstream($api, 'adjustCustomerBalance', [
        'customer_id' => $upstream_customer_id,
        'project_id' => $upstream_project_id,
        'amount' => $amount,
        'remark' => trim($_REQUEST['remark'] ?? '套娃余额调整'),
        'transaction_id' => $transaction_id
    ]);
    if (($upstream['status'] ?? '') !== 'success') {
        jsonResponse(['status' => 'error', 'message' => $upstream['message'] ?? '上游余额调整失败']);
    }

    $platform_changed = false;
    try {
        $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_amount, $amount > 0 ? 'sub' : 'add', '爽歪歪套娃余额调整-' . ($project['name'] ?? '项目'));
        $platform_changed = true;
        $before = floatval($customer['balance']);
        $after = isset($upstream['balance_after']) ? floatval($upstream['balance_after']) : round($before + $amount, 4);
        $api->updateOpenApiCustomerBalance($owner_uid, $customer['id'], $project_id, $after);
    } catch (Exception $e) {
        callConfiguredUpstream($api, 'adjustCustomerBalance', [
            'customer_id' => $upstream_customer_id, 'project_id' => $upstream_project_id,
            'amount' => -$amount, 'remark' => '本层失败自动回滚', 'transaction_id' => $transaction_id . '_rollback'
        ]);
        if ($platform_changed) {
            try {
                updateHostUserBalance($ct, $owner_uid, $platform_amount, $amount > 0 ? 'add' : 'sub', '爽歪歪套娃本层记账失败回滚');
            } catch (Exception $ignore) {}
        }
        jsonResponse(['status' => 'error', 'message' => '本层记账失败，已尝试回滚上游：' . $e->getMessage()]);
    }

    $api->addOpenApiLedger([
        'owner_uid' => $owner_uid, 'action' => 'adjustCustomerBalance', 'customer_id' => $customer['id'],
        'project_id' => $project_id, 'project_name' => $project['name'] ?? '',
        'direction' => $amount > 0 ? 'charge' : 'withdraw', 'project_amount' => $amount,
        'project_balance_before' => $before, 'project_balance_after' => $after,
        'platform_amount' => $amount > 0 ? -$platform_amount : $platform_amount,
        'platform_balance_before' => $platform_balance['old_balance'], 'platform_balance_after' => $platform_balance['new_balance'],
        'project_price' => $pricing['project_price'], 'user_rate' => $pricing['user_rate'], 'base_rate' => $pricing['base_rate'],
        'actual_price' => $pricing['actual_price'], 'formula' => abs($amount) . " × {$pricing['actual_price']} = {$platform_amount}",
        'remark' => trim($_REQUEST['remark'] ?? '套娃余额调整'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'transaction_id' => $transaction_id
    ]);
    jsonResponse(['status' => 'success', 'message' => '余额调整成功', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after, 'action' => $amount > 0 ? '增加' : '减少', 'transaction_id' => $transaction_id]);
}

function getCustomerInfoV2($api, $owner_uid) {
    $ids = [];
    if (!empty($_REQUEST['customer_ids'])) {
        $ids = explode(',', $_REQUEST['customer_ids']);
    } elseif (!empty($_REQUEST['customer_id'])) {
        $ids = [$_REQUEST['customer_id']];
    }
    $customers = $api->getOpenApiCustomersByIds($owner_uid, $ids);
    if (empty($customers)) {
        jsonResponse(['status' => 'error', 'message' => '请提供有效的客户ID（customer_id 或 customer_ids）']);
    }

    $upstream_ids = [];
    foreach ($customers as $customer) {
        if (intval($customer['upstream_customer_id'] ?? 0) > 0) {
            $upstream_ids[] = intval($customer['upstream_customer_id']);
        }
    }
    $balances = [];
    if (!empty($upstream_ids)) {
        $upstream = callConfiguredUpstream($api, 'getCustomerInfo', ['customer_ids' => implode(',', array_unique($upstream_ids))]);
        if (($upstream['status'] ?? '') !== 'success') {
            jsonResponse(['status' => 'error', 'message' => $upstream['message'] ?? '上游余额查询失败']);
        }
        $source_rows = isset($upstream['customers']) ? $upstream['customers'] : (isset($upstream['customer']) ? [$upstream['customer']] : []);
        foreach ($source_rows as $row) {
            $balances[intval($row['id'] ?? 0)] = floatval($row['balance'] ?? 0);
        }
    }

    $result = [];
    foreach ($customers as $customer) {
        $upstream_id = intval($customer['upstream_customer_id'] ?? 0);
        $balance = array_key_exists($upstream_id, $balances) ? $balances[$upstream_id] : floatval($customer['balance']);
        $api->updateOpenApiCustomerBalance($owner_uid, $customer['id'], $customer['project_id'], $balance);
        $project = $api->getProjectById($customer['project_id']);
        $formatted = syyOpenApiFormatCustomer(array_merge($customer, ['balance' => $balance]), $project);
        $formatted['project_balances'] = [[
            'project_id' => intval($customer['project_id']),
            'project_name' => $project['name'] ?? '',
            'balance' => $balance
        ]];
        $result[] = $formatted;
    }
    jsonResponse(count($result) === 1
        ? ['status' => 'success', 'customer' => $result[0]]
        : ['status' => 'success', 'customers' => $result]);
}

function deleteCustomerApiV2($ct, $api, $owner_uid) {
    try {
        $customer = syyOpenApiResolveCustomer($api, $owner_uid, true);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    if (!$customer || intval($customer['status']) !== 1) {
        jsonResponse(['status' => 'error', 'message' => '客户不存在或已经禁用']);
    }
    $project = $api->getProjectById($customer['project_id']);
    try {
        $pricing = syyOpenApiProjectPricing($ct, $api, $owner_uid, $project);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $upstream_customer_id = intval($customer['upstream_customer_id'] ?? 0);
    if ($upstream_customer_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '该客户是旧版数据，缺少独立上游映射，请重新开户']);
    }

    // 先取一次上游实时余额。正式接口会返回 refunded，这个数值仅作为
    // 旧版套娃节点没有透传 refunded 时的可靠兜底，不使用可能过期的本地缓存计算退款。
    $live_balance = null;
    $balance_query = callConfiguredUpstream($api, 'getCustomerInfo', ['customer_id' => $upstream_customer_id]);
    if (($balance_query['status'] ?? '') === 'success') {
        $balance_customer = $balance_query['customer'] ?? ($balance_query['customers'][0] ?? null);
        if (is_array($balance_customer) && array_key_exists('balance', $balance_customer)) {
            $live_balance = round(floatval($balance_customer['balance']), 4);
        }
    }

    $upstream = callConfiguredUpstream($api, 'deleteCustomerApi', ['customer_id' => $upstream_customer_id]);
    if (($upstream['status'] ?? '') !== 'success') {
        jsonResponse(['status' => 'error', 'message' => $upstream['message'] ?? '上游禁用客户失败']);
    }
    if (array_key_exists('refunded', $upstream)) {
        $refunded = round(floatval($upstream['refunded']), 4);
    } elseif (!empty($upstream['refund_details']) && is_array($upstream['refund_details'])) {
        $refunded = 0.0;
        foreach ($upstream['refund_details'] as $detail) {
            $refunded += floatval($detail['amount'] ?? 0);
        }
        $refunded = round($refunded, 4);
    } elseif ($live_balance !== null) {
        $refunded = $live_balance;
    } else {
        // 禁用已在上游成功，但无法确定应返本层的金额。本地同步为禁用且不自动打款，
        // 这样不会凭过期缓存多退钱，也不会让前端误以为客户仍可使用。
        $api->setOpenApiCustomerState($owner_uid, $customer['id'], 0, 0);
        jsonResponse(['status' => 'error', 'message' => '上游客户已禁用，但未返回退回余额；本层未自动返款，请按客户ID手工对账']);
    }
    $platform_refund = round($refunded * $pricing['actual_price'], 4);
    if ($platform_refund > 0) {
        try {
            $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_refund, 'add', '爽歪歪套娃客户禁用退回余额');
        } catch (Exception $e) {
            $enable_result = callConfiguredUpstream($api, 'enableCustomer', ['customer_id' => $upstream_customer_id]);
            $restore_result = ['status' => 'success'];
            if (($enable_result['status'] ?? '') === 'success' && $refunded > 0) {
                $restore_result = callConfiguredUpstream($api, 'adjustCustomerBalance', [
                    'customer_id' => $upstream_customer_id,
                    'project_id' => intval($customer['upstream_project_id'] ?? ($project['source_project_id'] ?? 0)),
                    'amount' => $refunded,
                    'remark' => '本层退款失败自动恢复'
                ]);
            }
            $rollback_ok = (($enable_result['status'] ?? '') === 'success' && ($restore_result['status'] ?? '') === 'success');
            jsonResponse([
                'status' => 'error',
                'message' => $rollback_ok
                    ? '本层退款失败，已恢复上游客户及其余额：' . $e->getMessage()
                    : '本层退款失败且上游回滚未完成，请立即手工对账：' . $e->getMessage()
            ]);
        }
    } else {
        $platform_balance = ['old_balance' => floatval($pricing['owner']['money']), 'new_balance' => floatval($pricing['owner']['money'])];
    }
    $api->setOpenApiCustomerState($owner_uid, $customer['id'], 0, 0);
    $transaction_id = 'delete_' . $customer['id'] . '_' . date('YmdHis');
    $api->addOpenApiLedger([
        'owner_uid' => $owner_uid, 'action' => 'deleteCustomerApi', 'customer_id' => $customer['id'],
        'project_id' => $customer['project_id'], 'project_name' => $project['name'] ?? '', 'direction' => 'withdraw',
        'project_amount' => -$refunded, 'project_balance_before' => floatval($customer['balance']), 'project_balance_after' => 0,
        'platform_amount' => $platform_refund, 'platform_balance_before' => $platform_balance['old_balance'],
        'platform_balance_after' => $platform_balance['new_balance'], 'project_price' => $pricing['project_price'],
        'user_rate' => $pricing['user_rate'], 'base_rate' => $pricing['base_rate'], 'actual_price' => $pricing['actual_price'],
        'formula' => "{$refunded} × {$pricing['actual_price']} = {$platform_refund}", 'remark' => '禁用客户退回余额',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'transaction_id' => $transaction_id
    ]);
    jsonResponse(['status' => 'success', 'message' => '客户禁用成功', 'refunded' => $refunded, 'refund_details' => [['project_id' => intval($customer['project_id']), 'amount' => $refunded]]]);
}

function enableCustomerV2($api, $owner_uid) {
    try {
        $customer = syyOpenApiResolveCustomer($api, $owner_uid, true);
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
    }
    if (!$customer) {
        jsonResponse(['status' => 'error', 'message' => '客户不存在']);
    }
    if (intval($customer['status']) === 1) {
        jsonResponse(['status' => 'success', 'message' => '客户已经是启用状态']);
    }
    $upstream_customer_id = intval($customer['upstream_customer_id'] ?? 0);
    if ($upstream_customer_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '该客户是旧版数据，缺少独立上游映射，请重新开户']);
    }
    $upstream = callConfiguredUpstream($api, 'enableCustomer', ['customer_id' => $upstream_customer_id]);
    if (($upstream['status'] ?? '') !== 'success') {
        jsonResponse(['status' => 'error', 'message' => $upstream['message'] ?? '上游启用客户失败']);
    }
    $api->setOpenApiCustomerState($owner_uid, $customer['id'], 1, 0);
    jsonResponse(['status' => 'success', 'message' => '客户启用成功']);
}

function syyOpenApiFormatCustomer($customer, $project) {
    $real_key = strval($customer['real_access_key'] ?? ($customer['upstream_customer_api_key'] ?? $customer['api_key']));
    $redirect = trim(strval($project['redirect_url'] ?? ''));
    // 优先透传最终源台实际返回的专属链接；只为旧数据才用 redirect_url + key 兜底。
    $link = trim(strval($customer['customer_link'] ?? ''));
    if ($link === '' && $redirect !== '' && $real_key !== '') {
        $link = $redirect;
        $link .= (strpos($redirect, '?') === false ? '?' : '&') . 'key=' . urlencode($real_key);
    }
    return [
        'id' => intval($customer['id']),
        'username' => 'customer' . intval($customer['id']),
        'api_key' => strval($customer['api_key']),
        'real_access_key' => $real_key,
        'project_id' => intval($customer['project_id']),
        'project_name' => $project['name'] ?? '',
        'balance' => floatval($customer['balance']),
        'customer_link' => $link,
        'status' => intval($customer['status'] ?? 1),
        'created_at' => $customer['created_at'] ?? date('Y-m-d H:i:s')
    ];
}

function generateCustomer($ct, $api, $owner_uid) {
    $project_id = intval($_REQUEST['project_id'] ?? 0);
    $initial_balance = floatval($_REQUEST['balance'] ?? 0);

    if ($project_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '项目ID无效']);
    }

    $project = $api->getProjectById($project_id);
    $owner = getHostUser($ct, $owner_uid);
    if (!$owner) {
        jsonResponse(['status' => 'error', 'message' => '对接用户不存在']);
    }
    if (!$project || intval($project['status']) !== 1) {
        jsonResponse(['status' => 'error', 'message' => '项目不存在或已禁用']);
    }

    $transaction_id = trim($_REQUEST['transaction_id'] ?? '');
    if ($transaction_id === '') {
        $transaction_id = 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }
    if ($initial_balance > 0) {
        $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);
        $user_rate = floatval($owner['addprice'] ?? $base_rate);
        $project_price = floatval($project['price'] ?? 1);
        $actual_price = $base_rate > 0 ? ($project_price * $user_rate / $base_rate) : $project_price;
        $platform_amount = $initial_balance * $actual_price;
        if (floatval($owner['money'] ?? 0) < $platform_amount) {
            jsonResponse(['status' => 'error', 'message' => '余额不足，无法开户初始充值']);
        }
    }

    if ($initial_balance > 0) {
        $project_key = $api->getProjectKey($owner_uid, $project_id);
        if (!$project_key || intval($project_key['customer_id'] ?? 0) <= 0) {
            jsonResponse(['status' => 'error', 'message' => '当前对接用户未绑定该项目的源台密钥']);
        }
    }

    $customer = $api->createOpenApiCustomer($owner_uid, $project_id, 0);
    if ($initial_balance > 0) {
        try {
            $source_result = $api->chargeToProject($owner_uid, $project_id, $platform_amount, $actual_price, $transaction_id);
        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => '源台项目密钥初始充值失败: ' . $e->getMessage()]);
        }
        $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_amount, 'sub', '套娃开户初始余额-' . ($project['name'] ?? '项目'));
        $source_before = floatval($source_result['before_balance'] ?? 0);
        $source_after = floatval($source_result['after_balance'] ?? ($source_before + $initial_balance));
        $api->updateOpenApiCustomerBalance($owner_uid, $customer['id'], $project_id, $source_after);
        $api->addOpenApiLedger([
            'owner_uid' => $owner_uid,
            'action' => 'generateCustomer',
            'customer_id' => $customer['id'],
            'project_id' => $project_id,
            'project_name' => $project['name'] ?? '',
            'direction' => 'charge',
            'project_amount' => $initial_balance,
            'project_balance_before' => $source_before,
            'project_balance_after' => $source_after,
            'platform_amount' => -$platform_amount,
            'platform_balance_before' => $platform_balance['old_balance'],
            'platform_balance_after' => $platform_balance['new_balance'],
            'project_price' => $project_price,
            'user_rate' => $user_rate,
            'base_rate' => $base_rate,
            'actual_price' => $actual_price,
            'formula' => "{$initial_balance} km × {$actual_price} 元/km = {$platform_amount} 元",
            'remark' => '开户初始余额',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'transaction_id' => $transaction_id
        ]);
        $customer['balance'] = $source_after;
    } else {
        try {
            $source_balance = $api->getProjectKeySourceBalance($owner_uid, $project_id);
            $customer = $api->updateOpenApiCustomerBalance($owner_uid, $customer['id'], $project_id, $source_balance['balance']);
        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    jsonResponse([
        'status' => 'success',
        'customer' => [
            'id' => intval($customer['id']),
            'api_key' => $customer['api_key'],
            'real_access_key' => $api->getRealAccessKey($project_id) ?: $customer['api_key'],
            'balance' => floatval($customer['balance']),
            'project_id' => intval($customer['project_id']),
            'project_name' => $customer['project_name'] ?? ''
        ],
        'transaction_id' => $transaction_id
    ]);
}

function adjustCustomerBalance($ct, $api, $owner_uid) {
    $customer_id = intval($_REQUEST['customer_id'] ?? 0);
    $project_id = intval($_REQUEST['project_id'] ?? 0);
    $amount = floatval($_REQUEST['amount'] ?? 0);
    $remark = trim($_REQUEST['remark'] ?? '套娃对接余额调整');

    if ($customer_id <= 0 || $project_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '客户ID或项目ID无效']);
    }
    if (abs($amount) <= 0) {
        jsonResponse(['status' => 'error', 'message' => '调整金额不能为0']);
    }

    $project = $api->getProjectById($project_id);
    if (!$project || intval($project['status']) !== 1) {
        jsonResponse(['status' => 'error', 'message' => '项目不存在或已禁用']);
    }

    $owner = getHostUser($ct, $owner_uid);
    if (!$owner) {
        jsonResponse(['status' => 'error', 'message' => '对接用户不存在']);
    }

    $base_rate = floatval($api->getConfig('base_rate') ?: 0.2);
    $user_rate = floatval($owner['addprice'] ?? $base_rate);
    $project_price = floatval($project['price'] ?? 1);
    $actual_price = $base_rate > 0 ? ($project_price * $user_rate / $base_rate) : $project_price;
    $platform_amount = abs($amount) * $actual_price;

    $customer = $api->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
    if (!$customer) {
        jsonResponse(['status' => 'error', 'message' => '客户不存在或无权限']);
    }

    $transaction_id = trim($_REQUEST['transaction_id'] ?? '');
    if ($transaction_id === '') {
        $transaction_id = 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }
    $existing_ledger = $api->getOpenApiLedgerByTransactionId($owner_uid, $transaction_id);
    if ($existing_ledger) {
        $source_balance = $api->getProjectKeySourceBalance($owner_uid, $project_id);
        $api->updateOpenApiProjectBalanceCache($owner_uid, $project_id, $source_balance['balance']);
        jsonResponse([
            'status' => 'success',
            'message' => '余额调整成功（重放请求）',
            // 重放不代表再次发生变动，历史账本快照可能已经过时，不能作为当前余额返回。
            'balance_before' => floatval($source_balance['balance']),
            'balance_after' => floatval($source_balance['balance']),
            'balance' => floatval($source_balance['balance']),
            'transaction_id' => $transaction_id,
            'is_replay' => true,
            'actual_price' => floatval($existing_ledger['actual_price']),
            'platform_amount' => floatval($existing_ledger['platform_amount'])
        ]);
    }
    $upstream_result = null;

    // 本层扣款必须在调用上游前预检，避免上游成功后本层余额不足。
    if ($amount > 0 && floatval($owner['money'] ?? 0) < $platform_amount) {
        jsonResponse(['status' => 'error', 'message' => '余额不足，无法完成本层扣款']);
    }

    $project_key = $api->getProjectKey($owner_uid, $project_id);
    if (!$project_key || intval($project_key['customer_id'] ?? 0) <= 0) {
        jsonResponse(['status' => 'error', 'message' => '当前对接用户未绑定该项目的源台密钥']);
    }

    try {
        if ($amount > 0) {
            $upstream_result = $api->chargeToProject(
                $owner_uid,
                $project_id,
                $platform_amount,
                $actual_price,
                $transaction_id
            );
        } else {
            $upstream_result = $api->withdrawFromProject(
                $owner_uid,
                $project_id,
                abs($amount),
                $actual_price,
                $transaction_id
            );
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => '源台项目密钥余额调整失败: ' . $e->getMessage()]);
    }

    if (!empty($upstream_result['is_replay'])) {
        jsonResponse([
            'status' => 'error',
            'message' => '源台交易已执行但本层没有对应账本，请按交易号对账后处理',
            'transaction_id' => $transaction_id,
            'retryable' => false
        ]);
    }

    try {
        if ($amount > 0) {
            $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_amount, 'sub', '套娃充值到项目-' . $project['name'] . '，' . $remark);
            $direction = 'charge';
            $signed_platform_amount = -$platform_amount;
        } else {
            $platform_balance = updateHostUserBalance($ct, $owner_uid, $platform_amount, 'add', '套娃从项目提现-' . $project['name'] . '，' . $remark);
            $direction = 'withdraw';
            $signed_platform_amount = $platform_amount;
        }
    } catch (Exception $balance_error) {
        if ($upstream_result) {
            try {
                if ($amount > 0) {
                    $api->withdrawFromProject($owner_uid, $project_id, $amount, $actual_price, $transaction_id . '_rollback');
                } else {
                    $api->chargeToProject($owner_uid, $project_id, $platform_amount, $actual_price, $transaction_id . '_rollback');
                }
            } catch (Exception $rollback_error) {
                throw new Exception('本层余额更新失败且源台回滚失败，原交易号: ' . $transaction_id);
            }
        }
        throw new Exception('本层余额更新失败: ' . $balance_error->getMessage());
    }

    $source_before = floatval($upstream_result['before_balance'] ?? 0);
    $source_after = floatval($upstream_result['after_balance'] ?? ($source_before + $amount));
    $api->updateOpenApiCustomerBalance($owner_uid, $customer_id, $project_id, $source_after);
    $balance = ['before_balance' => $source_before, 'after_balance' => $source_after];

    $api->addOpenApiLedger([
        'owner_uid' => $owner_uid,
        'action' => 'adjustCustomerBalance',
        'customer_id' => $customer_id,
        'project_id' => $project_id,
        'project_name' => $project['name'] ?? '',
        'direction' => $direction,
        'project_amount' => $amount,
        'project_balance_before' => $balance['before_balance'],
        'project_balance_after' => $balance['after_balance'],
        'platform_amount' => $signed_platform_amount,
        'platform_balance_before' => $platform_balance['old_balance'],
        'platform_balance_after' => $platform_balance['new_balance'],
        'project_price' => $project_price,
        'user_rate' => $user_rate,
        'base_rate' => $base_rate,
        'actual_price' => $actual_price,
        'formula' => abs($amount) . " km × {$actual_price} 元/km = {$platform_amount} 元",
        'remark' => $remark,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'transaction_id' => $transaction_id
    ]);

    jsonResponse([
        'status' => 'success',
        'message' => '余额调整成功',
        'balance_before' => $balance['before_balance'],
        'balance_after' => $balance['after_balance'],
        'balance' => floatval($balance['after_balance']),
        'transaction_id' => $transaction_id,
        'is_replay' => false,
        'actual_price' => $actual_price,
        'platform_amount' => $amount > 0 ? -$platform_amount : $platform_amount
    ]);
}

function getCustomerInfo($api, $owner_uid) {
    $customer_ids = [];
    if (!empty($_REQUEST['customer_ids'])) {
        $customer_ids = explode(',', $_REQUEST['customer_ids']);
    } elseif (!empty($_REQUEST['customer_id'])) {
        $customer_ids = [$_REQUEST['customer_id']];
    }

    $customers = $api->getOpenApiCustomersByIds($owner_uid, $customer_ids);
    $data = [];
    $project_balances = [];
    foreach ($customers as $customer) {
        $project_id = intval($customer['project_id']);
        if (!isset($project_balances[$project_id])) {
            $source = $api->getProjectKeySourceBalance($owner_uid, $project_id);
            $project_balances[$project_id] = floatval($source['balance']);
        }
        $balance = $project_balances[$project_id];
        $api->updateOpenApiProjectBalanceCache($owner_uid, $project_id, $balance);
        $data[] = [
            'id' => intval($customer['id']),
            'project_id' => $project_id,
            'balance' => $balance,
            'api_key' => $customer['api_key'],
            'real_access_key' => $api->getRealAccessKey($project_id) ?: $customer['api_key'],
            'status' => intval($customer['status'])
        ];
    }

    if (count($data) === 1) {
        jsonResponse(['status' => 'success', 'customer' => $data[0]]);
    }

    jsonResponse(['status' => 'success', 'customers' => $data]);
}

function callConfiguredUpstream($api, $action, $params = []) {
    $api_url = $api->getConfig('source_api_url') ?: '';
    $api_key = $api->getConfig('source_api_key') ?: '';
    if ($api_url === '' || $api_key === '') {
        throw new Exception('未配置上游API地址或对接密钥');
    }
    if (strpos($api_url, 'http://') !== 0 && strpos($api_url, 'https://') !== 0) {
        $api_url = 'https://' . $api_url;
    }
    $params['action'] = $action;
    $params['api_key'] = $api_key;
    // 爽歪歪管理密钥模式：除余额调整外，其余核心客户接口走 GET。
    $use_get = ($action !== 'adjustCustomerBalance');
    $request_url = $use_get ? $api_url . (strpos($api_url, '?') === false ? '?' : '&') . http_build_query($params) : $api_url;
    $ch = curl_init($request_url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-Key: ' . $api_key]
    ];
    if (!$use_get) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($params);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) {
        return ['status' => 'error', 'message' => '上游网络错误: ' . $error];
    }
    if ($http_code !== 200) {
        return ['status' => 'error', 'message' => '上游HTTP错误: ' . $http_code];
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : ['status' => 'error', 'message' => '上游响应不是有效JSON'];
}

function submitCustomerTicket($api, $owner_uid, $auth_customer = null) {
    $project_id = intval($_REQUEST['project_id'] ?? ($auth_customer['project_id'] ?? 0));
    $customer = resolveCustomer($api, $owner_uid, $project_id, $auth_customer);
    $ticket = $api->submitOpenApiTicket(
        $owner_uid,
        intval($customer['id']),
        $project_id,
        $_REQUEST['type'] ?? '',
        $_REQUEST['title'] ?? '',
        $_REQUEST['description'] ?? '',
        $_REQUEST['image_data'] ?? '',
        $_REQUEST['compensation_amount'] ?? ''
    );

    jsonResponse(['status' => 'success', 'message' => '工单提交成功', 'data' => ['ticket' => formatTicket($ticket)]]);
}

function getCustomerTickets($api, $owner_uid, $auth_customer = null) {
    $project_id = intval($_REQUEST['project_id'] ?? ($auth_customer['project_id'] ?? 0));
    if ($auth_customer) {
        $project_id = intval($auth_customer['project_id']);
    } elseif ($project_id > 0) {
        resolveCustomer($api, $owner_uid, $project_id);
    }

    $result = $api->getOpenApiTickets(
        $owner_uid,
        $project_id,
        $_REQUEST['status'] ?? '',
        $_REQUEST['type'] ?? '',
        intval($_REQUEST['page'] ?? 1),
        min(100, max(1, intval($_REQUEST['limit'] ?? 20)))
    );
    $list = array_map('formatTicket', $result['list']);
    jsonResponse(['status' => 'success', 'data' => ['list' => $list, 'tickets' => $list, 'total' => $result['total'], 'page' => $result['page'], 'limit' => $result['limit']]]);
}

function getCustomerTicketDetail($api, $owner_uid, $auth_customer = null) {
    $ticket_id = intval($_REQUEST['ticket_id'] ?? 0);
    $project_id = intval($_REQUEST['project_id'] ?? ($auth_customer['project_id'] ?? 0));
    if ($ticket_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '工单ID无效']);
    }
    if ($auth_customer) {
        $project_id = intval($auth_customer['project_id']);
    } elseif ($project_id > 0) {
        resolveCustomer($api, $owner_uid, $project_id);
    }
    $ticket = $api->getOpenApiTicket($owner_uid, $ticket_id, $project_id);
    if (!$ticket) {
        jsonResponse(['status' => 'error', 'message' => '工单不存在']);
    }
    jsonResponse(['status' => 'success', 'data' => ['ticket' => formatTicket($ticket), 'replies' => array_map('formatReply', $ticket['replies'] ?? [])]]);
}

function replyCustomerTicket($api, $owner_uid, $auth_customer = null) {
    $ticket_id = intval($_REQUEST['ticket_id'] ?? 0);
    $project_id = intval($_REQUEST['project_id'] ?? ($auth_customer['project_id'] ?? 0));
    if ($ticket_id <= 0 || $project_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '工单ID或项目ID无效']);
    }
    $customer = resolveCustomer($api, $owner_uid, $project_id, $auth_customer);
    $ticket = $api->replyOpenApiTicket(
        $owner_uid,
        $ticket_id,
        $project_id,
        intval($customer['id']),
        $_REQUEST['content'] ?? '',
        $_REQUEST['image_data'] ?? '',
        'customer'
    );
    jsonResponse(['status' => 'success', 'message' => '回复成功', 'data' => ['ticket' => formatTicket($ticket)]]);
}

function reviewCompensationTicket($api, $owner_uid) {
    $ticket_id = intval($_REQUEST['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '工单ID无效']);
    }
    $ticket = $api->reviewOpenApiCompensationTicket($owner_uid, $ticket_id, $_REQUEST['review_result'] ?? '', $_REQUEST['review_note'] ?? '');
    jsonResponse(['status' => 'success', 'message' => '审核完成', 'data' => ['ticket' => formatTicket($ticket)]]);
}

function resolveCustomer($api, $owner_uid, $project_id, $auth_customer = null) {
    if ($auth_customer) {
        if (intval($auth_customer['owner_uid']) !== intval($owner_uid) || ($project_id > 0 && intval($auth_customer['project_id']) !== intval($project_id))) {
            jsonResponse(['status' => 'error', 'message' => '客户无权限访问该项目']);
        }
        return $auth_customer;
    }
    $customer_id = intval($_REQUEST['customer_id'] ?? 0);
    if ($customer_id <= 0 && !empty($_REQUEST['customer_api_key'])) {
        $byKey = $api->getOpenApiCustomerByApiKey($_REQUEST['customer_api_key']);
        $customer_id = intval($byKey['id'] ?? 0);
    }
    if ($customer_id <= 0) {
        jsonResponse(['status' => 'error', 'message' => '缺少customer_id']);
    }
    $customer = $api->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
    if (!$customer) {
        jsonResponse(['status' => 'error', 'message' => '客户不存在或无权限']);
    }
    return $customer;
}

function formatTicket($ticket) {
    return [
        'id' => intval($ticket['id']),
        'ticket_id' => intval($ticket['id']),
        'project_id' => intval($ticket['project_id']),
        'project_name' => $ticket['project_name'] ?? '',
        'type' => $ticket['type'],
        'title' => $ticket['title'],
        'description' => $ticket['description'] ?? '',
        'image_data' => $ticket['image_data'] ?? '',
        'compensation_amount' => floatval($ticket['compensation_amount'] ?? 0),
        'review_result' => $ticket['review_result'] ?? '',
        'review_note' => $ticket['review_note'] ?? '',
        'status' => $ticket['status'] ?? 'pending',
        'created_at' => $ticket['created_at'] ?? '',
        'updated_at' => $ticket['updated_at'] ?? '',
        'replies' => array_map('formatReply', $ticket['replies'] ?? [])
    ];
}

function formatReply($reply) {
    return [
        'id' => intval($reply['id']),
        'ticket_id' => intval($reply['ticket_id']),
        'sender_type' => $reply['sender_type'] ?? 'customer',
        'content' => $reply['content'] ?? '',
        'image_data' => $reply['image_data'] ?? '',
        'created_at' => $reply['created_at'] ?? ''
    ];
}

function getHostUser($ct, $uid) {
    $DB = $ct->getDB();
    $uid = intval($uid);
    $tables = [
        ['table' => 'qingka_wangke_user', 'id' => 'uid'],
        ['table' => 'qingka_wangke_users', 'id' => 'id']
    ];

    foreach ($tables as $meta) {
        try {
            $row = $DB->get_row("SELECT * FROM {$meta['table']} WHERE {$meta['id']} = {$uid} LIMIT 1");
            if ($row) {
                $row['_table'] = $meta['table'];
                $row['_id_field'] = $meta['id'];
                return $row;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return null;
}

function updateHostUserBalance($ct, $uid, $amount, $type, $remark) {
    $amount = round(floatval($amount), 4);
    if ($amount <= 0) {
        throw new Exception('金额必须大于0');
    }

    $DB = $ct->getDB();
    $user = getHostUser($ct, $uid);
    if (!$user) {
        throw new Exception('用户不存在');
    }

    $current = floatval($user['money'] ?? 0);
    $table = $user['_table'];
    $id_field = $user['_id_field'];
    $uid = intval($uid);
    if ($type === 'sub') {
        // 条件扣款防止并发请求使用同一份旧余额造成透支。
        $result = $DB->query("UPDATE {$table} SET money=money-'{$amount}' WHERE {$id_field}='{$uid}' AND money>='{$amount}'");
        if (!$result || mysqli_affected_rows($DB->link) < 1) {
            $latest = getHostUser($ct, $uid);
            throw new Exception('余额不足，当前余额：' . floatval($latest['money'] ?? 0) . '，需要：' . $amount);
        }
        $money_log = '-' . $amount;
        $type_text = '套娃扣费';
    } elseif ($type === 'add') {
        $result = $DB->query("UPDATE {$table} SET money=money+'{$amount}' WHERE {$id_field}='{$uid}'");
        if (!$result || mysqli_affected_rows($DB->link) < 1) {
            throw new Exception('用户余额返还失败');
        }
        $money_log = '+' . $amount;
        $type_text = '套娃返款';
    } else {
        throw new Exception('余额操作类型错误');
    }

    $latest = getHostUser($ct, $uid);
    $new = floatval($latest['money'] ?? ($type === 'sub' ? $current - $amount : $current + $amount));
    $current = $type === 'sub' ? $new + $amount : $new - $amount;

    if (function_exists('wlog')) {
        wlog($uid, $type_text, $remark, $money_log);
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $DB->query("INSERT INTO qingka_wangke_log (uid, type, text, money, smoney, ip, addtime) VALUES ('{$uid}', '{$type_text}', '" . addslashes($remark) . "', '{$money_log}', '{$new}', '{$ip}', NOW())");
    }

    return ['old_balance' => $current, 'new_balance' => $new];
}
