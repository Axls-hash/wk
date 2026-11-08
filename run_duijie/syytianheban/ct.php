<?php
/**
 * CT类 - 核心业务逻辑类
 *
 * 主要功能：
 * 1. 用户认证管理 - 检查登录状态、获取用户信息
 * 2. 余额管理 - 查询余额、检查余额、增减余额
 * 3. 费率管理 - 查询课程费率、计算用户实际费率
 * 4. 订单管理 - 创建订单、查询订单列表
 * 5. 日志管理 - 记录操作日志、查询日志列表
 *
 * 设计特点：
 * - 使用全局变量初始化数据库连接和用户信息
 * - 支持回退机制，当全局变量不可用时自动加载配置
 * - 所有余额操作都会自动记录日志
 * - 提供完整的异常处理机制
 *
 * @author 天河skyriver
 * @version 3.3.3
 * @since 2026-09-13
 */

$config_loaded = false;
if (!isset($GLOBALS['DB']) || !isset($GLOBALS['islogin'])) {
    $common_file = dirname(__FILE__) . '/../confing/common.php';
    if (file_exists($common_file)) {
        include_once($common_file);
        $config_loaded = true;
    }
}
if (!defined('IN_CRONLITE')) {
    define('IN_CRONLITE', true);
}

class CT {
    private $DB;
    private $userrow;
    private $clientip;
    private $date;
    private $table_prefix = 'qingka_wangke';
    //爱学习等其他主题用户在此处修改表名前缀与数据库中对应表名前缀对应

    /**
     * 构造函数
     */
    public function __construct() {
        $this->date = date("Y-m-d H:i:s");
        $this->init_from_globals();
    }

    /**
     * 获取底层数据库连接对象
     */
    public function getDB() {
        return $this->DB;
    }
    
    /**
     * 从全局变量初始化（参考示例代码的做法）
     */
    private function init_from_globals() {
        global $DB, $islogin, $userrow, $clientip;
        
        // 使用全局数据库连接
        if (isset($DB) && $DB !== null) {
            $this->DB = $DB;
        } else {
            // 回退到手动加载config.php
            $this->init_db_fallback();
        }
        
        // 使用全局用户认证信息
        if (isset($islogin) && $islogin == 1 && isset($userrow)) {
            $this->userrow = $userrow;
        }
        
        // 使用全局客户端IP
        $this->clientip = isset($clientip) ? $clientip : (function_exists('real_ip') ? real_ip() : $this->real_ip_fallback());
    }
    
    /**
     * 数据库连接回退方法
     */
    private function init_db_fallback() {
        $config_file = dirname(__FILE__) . '/../confing/config.php';
        if (file_exists($config_file)) {
            include($config_file);
            $this->DB = new DB($host, $user, $pwd, $dbname, $port);
        } else {
            throw new Exception('数据库配置文件不存在');
        }
    }
    
    
    /**
     * 回退的IP获取函数
     */
    private function real_ip_fallback() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
            foreach ($matches[0] AS $xip) {
                if (!preg_match('#^(10|172\.16|192\.168)\.#', $xip)) {
                    $ip = $xip;
                    break;
                }
            }
        } elseif (isset($_SERVER['HTTP_CLIENT_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        return $ip;
    }
    
    
    
    /**
     * 检查用户是否登录
     */
    public function isLogin() {
        global $islogin;
        // 完全使用全局登录状态
        return isset($islogin) && $islogin == 1;
    }
    
    /**
     * 获取当前用户信息
     */
    public function getUserInfo() {
        global $userrow;
        // 完全使用全局用户信息
        return isset($userrow) ? $userrow : null;
    }
    
    /**
     * 查询用户余额
     * @return float 用户余额
     */
    public function getBalance() {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        return floatval($this->userrow['money']);
    }
    
    /**
     * 检查余额是否足够
     * @param float $required 需要的金额
     * @return array 包含检查结果的数组
     */
    public function checkBalance($required) {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        $balance = $this->getBalance();
        $sufficient = $balance >= $required;
        
        return array(
            'balance' => $balance,
            'required' => $required,
            'sufficient' => $sufficient
        );
    }
    
    /**
     * 修改用户余额
     * @param float $amount 金额
     * @param string $type 操作类型 (add/sub)
     * @param string $remark 备注
     * @return array 修改结果
     */
    public function updateBalance($amount, $type = 'add', $remark = '系统操作') {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        if ($amount <= 0) {
            throw new Exception('金额必须大于0');
        }
        
        $uid = $this->userrow['uid'];
        $current_balance = $this->getBalance();
        
        if ($type == 'add') {
            $new_balance = $current_balance + $amount;
            $log_text = "余额增加：+{$amount}元，{$remark}";
            $log_money = "+{$amount}";
        } elseif ($type == 'sub') {
            if ($current_balance < $amount) {
                throw new Exception('余额不足，无法扣除');
            }
            $new_balance = $current_balance - $amount;
            $log_text = "余额扣除：-{$amount}元，{$remark}";
            $log_money = "-{$amount}";
        } else {
            throw new Exception('操作类型错误，只支持add或sub');
        }
        
        $result = $this->DB->query("UPDATE {$this->table_prefix}_user SET money='$new_balance' WHERE uid='$uid'");
        if ($result) {
            $this->userrow['money'] = $new_balance; // 更新本地缓存
            $this->writeLog('余额变动', $log_text, $log_money);
            return array(
                'old_balance' => $current_balance,
                'new_balance' => $new_balance,
                'amount' => $amount,
                'type' => $type
            );
        } else {
            throw new Exception('余额更新失败');
        }
    }
    
    /**
     * 查询费率
     * @param int $cid 课程ID，0表示查询所有
     * @return array 费率信息
     */
    public function getRate($cid = 0) {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        $user_rate = floatval($this->userrow['addprice']); // 用户费率，如0.4, 0.3等
        
        if ($cid > 0) {
            // 查询指定课程费率
            $course = $this->DB->get_row("SELECT * FROM {$this->table_prefix}_class WHERE cid='$cid' AND status=1");
            if (!$course) {
                throw new Exception('课程不存在或已下架');
            }
            
            $base_price = floatval($course['price']);
            // 计算用户实际费率
            if ($course['yunsuan'] == '*') {
                $actual_price = $base_price * $user_rate;
            } else {
                $actual_price = $base_price + $user_rate;
            }
            
            return array(
                'cid' => $course['cid'],
                'name' => $course['name'],
                'base_price' => $base_price,
                'user_rate' => $user_rate,
                'actual_price' => $actual_price,
                'yunsuan' => $course['yunsuan'],
                'content' => $course['content']
            );
        } else {
            // 查询所有可用课程费率
            $courses = array();
            $result = $this->DB->query("SELECT * FROM {$this->table_prefix}_class WHERE status=1 ORDER BY sort ASC");
            while ($row = $this->DB->fetch($result)) {
                $base_price = floatval($row['price']);
                // 计算用户实际费率
                if ($row['yunsuan'] == '*') {
                    $actual_price = $base_price * $user_rate;
                } else {
                    $actual_price = $base_price + $user_rate;
                }
                
                $courses[] = array(
                    'cid' => $row['cid'],
                    'name' => $row['name'],
                    'base_price' => $base_price,
                    'user_rate' => $user_rate,
                    'actual_price' => $actual_price,
                    'yunsuan' => $row['yunsuan'],
                    'content' => $row['content']
                );
            }
            return array(
                'user_rate' => $user_rate,
                'courses' => $courses
            );
        }
    }
    
    /**
     * 创建订单
     * @param array $order_data 订单数据
     * @return array 创建结果
     */
    public function createOrder($order_data) {
        global $userrow, $DB, $clientip;
        
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        // 必填字段检查
        $required_fields = array('cid', 'school', 'name', 'user', 'pass', 'phone', 'kcname');
        foreach ($required_fields as $field) {
            if (!isset($order_data[$field]) || empty(trim($order_data[$field]))) {
                throw new Exception("缺少必填字段：{$field}");
            }
        }
        
        $cid = intval($order_data['cid']);
        
        // 使用传入的费用，如果没有传入则设为0
        $fees = isset($order_data['fees']) ? floatval($order_data['fees']) : 0;
        $ptname = isset($order_data['ptname']) ? addslashes(trim($order_data['ptname'])) : '默认服务';
        $noun = isset($order_data['noun']) ? addslashes(trim($order_data['noun'])) : 'default';
        
        // 检查余额
        if ($userrow['money'] < $fees) {
            throw new Exception('余额不足，当前余额：' . $userrow['money'] . '，需要：' . $fees);
        }
        
        $uid = $userrow['uid'];
        
        // 使用addslashes处理数据，参考示例
        $school = addslashes(trim($order_data['school']));
        $name = addslashes(trim($order_data['name']));
        $user = addslashes(trim($order_data['user']));
        $pass = addslashes(trim($order_data['pass']));
        $phone = addslashes(trim($order_data['phone']));
        $kcid = isset($order_data['kcid']) ? addslashes(trim($order_data['kcid'])) : '';
        $kcname = addslashes(trim($order_data['kcname']));
        $courseStartTime = isset($order_data['courseStartTime']) ? addslashes(trim($order_data['courseStartTime'])) : '';
        $courseEndTime = isset($order_data['courseEndTime']) ? addslashes(trim($order_data['courseEndTime'])) : '';
        $examStartTime = isset($order_data['examStartTime']) ? addslashes(trim($order_data['examStartTime'])) : '';
        $examEndTime = isset($order_data['examEndTime']) ? addslashes(trim($order_data['examEndTime'])) : '';
        $chapterCount = isset($order_data['chapterCount']) ? addslashes(trim($order_data['chapterCount'])) : '';
        $unfinishedChapterCount = isset($order_data['unfinishedChapterCount']) ? addslashes(trim($order_data['unfinishedChapterCount'])) : '';
        $cookie = isset($order_data['cookie']) ? addslashes(trim($order_data['cookie'])) : '';
        $remarks = isset($order_data['remarks']) ? addslashes(trim($order_data['remarks'])) : '';
        $time = isset($order_data['time']) ? addslashes(trim($order_data['time'])) : '30';
        $current_time = $this->date;
        $ip = $this->clientip;
        
        // 参考示例，使用原生SQL插入，提供更好的错误处理
        $insert_sql = "INSERT INTO {$this->table_prefix}_order (
            uid, cid, hid, yid, ptname, school, name, user, pass, phone,
            kcid, kcname, courseStartTime, courseEndTime, examStartTime, examEndTime,
            chapterCount, unfinishedChapterCount, cookie, fees, noun, miaoshua,
            addtime, ip, dockstatus, loginstatus, status, process, bsnum, 
            remarks, time, updateTime
        ) VALUES (
            '$uid', 
            '$cid', 
            '0', 
            '', 
            '$ptname', 
            '$school', 
            '$name', 
            '$user', 
            '$pass', 
            '$phone',
            '$kcid', 
            '$kcname', 
            '$courseStartTime', 
            '$courseEndTime', 
            '$examStartTime', 
            '$examEndTime',
            '$chapterCount', 
            '$unfinishedChapterCount', 
            '$cookie', 
            '$fees', 
            '$noun', 
            '0',
            '$current_time', 
            '$ip', 
            '0', 
            '', 
            '待处理', 
            '', 
            '0', 
            '$remarks', 
            '$time', 
            '$current_time'
        )";
        
        $result = $DB->query($insert_sql);
        
        if (!$result) {
            // 提供更详细的错误信息，参考示例
            $error_msg = "订单记录插入失败: " . $DB->error();
            error_log($error_msg . " SQL: " . $insert_sql);
            throw new Exception($error_msg);
        }
        
        // 获取订单ID - 先检查insert方法，如果失败再手动获取
        $oid = null;
        if (method_exists($DB, 'insert')) {
            $oid = $DB->insert($insert_sql);
        }
        
        if (!$oid) {
            // 手动获取最后插入的ID
            $last_id_result = $DB->get_row("SELECT LAST_INSERT_ID() as oid");
            $oid = $last_id_result['oid'];
        }
        
        if (!$oid) {
            throw new Exception('无法获取订单ID');
        }
        
        // 扣除余额
        $new_balance = $userrow['money'] - $fees;
        $update_result = $DB->query("UPDATE {$this->table_prefix}_user SET money='$new_balance' WHERE uid='$uid'");
        
        if (!$update_result) {
            throw new Exception('余额扣除失败');
        }
        
        // 记录日志
        $this->writeLog('下单扣费', "下单成功，订单号：{$oid}，扣费：{$fees}元", "-{$fees}");
        
        // 准备返回数据，参考示例
        $return_data = array(
            'order_id' => $oid,
            'cost' => $fees,
            'user_balance' => $new_balance
        );
        
        return $return_data;
    }
    
    /**
     * 写入日志
     * @param string $type 日志类型
     * @param string $text 日志内容
     * @param string $money 金额变动
     * @return bool 是否成功
     */
    public function writeLog($type = '系统操作', $text = '', $money = '0') {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        if (empty($text)) {
            throw new Exception('日志内容不能为空');
        }
        
        $uid = $this->userrow['uid'];
        
        // 优先使用全局wlog函数，如果不存在则使用原有逻辑
        if (function_exists('wlog')) {
            wlog($uid, $type, $text, $money);
        } else {
            $smoney = $this->getBalance();
            $result = $this->DB->query("INSERT INTO {$this->table_prefix}_log (uid, type, text, money, smoney, ip, addtime) VALUES ('$uid', '$type', '$text', '$money', '$smoney', '{$this->clientip}', NOW())");
            if (!$result) {
                throw new Exception('日志写入失败');
            }
        }
        
        return true;
    }
    
    /**
     * 获取用户订单列表
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array 订单列表
     */
    public function getUserOrders($limit = 20, $offset = 0) {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        $uid = $this->userrow['uid'];
        $orders = array();
        $result = $this->DB->query("SELECT * FROM {$this->table_prefix}_order WHERE uid='$uid' ORDER BY oid DESC LIMIT $offset, $limit");
        
        while ($row = $this->DB->fetch($result)) {
            $orders[] = $row;
        }
        
        return $orders;
    }
    
    /**
     * 获取用户日志列表
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array 日志列表
     */
    public function getUserLogs($limit = 20, $offset = 0) {
        if (!$this->isLogin()) {
            throw new Exception('用户未登录');
        }
        
        $uid = $this->userrow['uid'];
        $logs = array();
        $result = $this->DB->query("SELECT * FROM {$this->table_prefix}_log WHERE uid='$uid' ORDER BY id DESC LIMIT $offset, $limit");
        
        while ($row = $this->DB->fetch($result)) {
            $logs[] = $row;
        }
        
        return $logs;
    }
}