<?php
include('../confing/common.php');
include_once(__DIR__ . '/setting.php');
/**
 * TaiShan 套娃上级网关
 *
 * ========== 你要当「套娃上级」时怎么配 ==========
 *
 * 1）本站（上级）
 *    - 确保已配置 /sxdk/setting.php（源台 token、账号、价格、退款）
 *    - 上传本文件到：/sxdk/upstream.php
 *    - 给下级用户开通接口：qingka_wangke_user.key 填非 0 的密匙，并保证有余额
 *
 * 2）子站（下级，同套对接模板）
 *    只改子站 /sxdk/setting.php（不要再改 api.php / cron.php）：
 *      $sxdk_admin   = "该下级在你站的 uid";
 *      $sxdk_token   = "该下级在你站的 key";
 *      $sxdk_urlList = ["https://你的域名/sxdk/upstream.php/"];  // 末尾保留 /
 *    子站不要再直连真正源台（urlList 不要再写源台 /copilot/）。
 *
 * 3）请求形态
 *    子站实际会请求：https://你的域名/sxdk/upstream.php/addOrder
 *    也兼容：https://你的域名/sxdk/upstream.php/?act=addOrder
 *
 * 4）订单 ID 约定
 *    子站订单.sxdkId = 本站 qingka_wangke_sxdk.id
 *    本站订单.sxdkId = 真正源台订单 id
 *
 * 5）扣费
 *    从该下级在你站的 money 扣；单价见 setting.php 的 sxdk_platform_price × 下级 addprice
 *
 * 6）接口策略简述
 *    yunOrder          → 只读本站「该对接 uid」下订单（不打源台，防串其它下级）
 *    xxy/hzj 学校列表  → 优先官方/缓存，失败再回退源台
 *    其它业务接口      → 鉴权后转发源台，写操作会映射 id 并扣费
 *
 * 7）反馈 uid（getCommentList / addComment）
 *    每层拼成：本层对接uid + '_' + 下层传来的 uid，例如 8_50、8_3_50
 *    源台须按字符串存/比 uid，不要 intval（否则 8_50 会变成 8，隔离失效）
 *
 * 价格 / 源台账号 / 退款：统一改 /sxdk/setting.php，不要改本文件。
 */
@header('Content-Type: application/json; charset=UTF-8');

$today = date("Y-m-d H:i:s");

// ---------- 解析请求 ----------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
	$input = $_POST;
}
if (!is_array($input)) {
	$input = array();
}

$act = up_parseAct($_GET, $_SERVER);

if ($act === '') {
	exit(json_encode(array("code" => 1, "msg" => "缺少接口名，例如 /upstream.php/addOrder 或 ?act=addOrder")));
}

// ---------- 鉴权：admin=uid，token=key ----------
list($authUid, $authKey) = up_extractAuth($input);
$authUid = trim(strip_tags(daddslashes($authUid)));
$authKey = trim(strip_tags(daddslashes($authKey)));

if ($authUid === '' || $authKey === '') {
	exit(json_encode(array("code" => 1, "msg" => "鉴权失败：admin/token 不能为空（对应本站 uid/key）")));
}

$child = $DB->get_row("select * from qingka_wangke_user where uid='{$authUid}' limit 1");
$authErr = up_validateChildKey($child, $authKey);
if ($authErr !== null) {
	exit(json_encode(array("code" => 1, "msg" => $authErr)));
}

$childUid = $child['uid'];
// 反馈隔离：鉴权前保留下层 uid，稍后拼成 traceUid（须在 unset uid 之前）
$clientUidForComment = isset($input['uid']) ? $input['uid'] : '';
// 去掉鉴权字段，避免继续传给源台
unset($input['admin'], $input['token'], $input['uid'], $input['key'], $input['ts_version']);
$commentTraceUid = up_traceUid($childUid, $clientUidForComment);

// ---------- 路由 ----------
switch ($act) {

	// ===== 同步：只读本地 =====
	case 'yunOrder': {
		$rows = array();
		$q = $DB->query("select * from qingka_wangke_sxdk where uid='{$childUid}' order by id desc");
		if ($q) {
			while ($row = $DB->fetch($q)) {
				$rows[] = array(
					"id" => intval($row['id']), // 子站 sxdkId 对应本站 id
					"platform" => $row['platform'],
					"code" => $row['code'],
					"wxpush" => $row['wxpush'],
					"end_time" => $row['end_time'],
					"phone" => $row['phone'],
					"password" => $row['password'],
				);
			}
		}
		up_json(array("code" => 0, "msg" => "ok", "data" => $rows));
		break;
	}

	// ===== 余额/账户（返回下级自己的）=====
	case 'get_userrow': {
		up_json(array(
			"code" => 0,
			"data" => array(
				"msg" => "余额：{$child['money']}，倍率：{$child['addprice']}",
				"money" => $child['money'],
				"addprice" => $child['addprice'],
				"uid" => $childUid,
			)
		));
		break;
	}

	case 'getNotice': {
		$result = dockSendPostRequest("getNotice", array(), $childUid);
		up_json($result);
		break;
	}

	// ===== 下单 =====
	case 'addOrder': {
		$form = $input;
		$platform = isset($form['platform']) ? trim(strval($form['platform'])) : '';
		$phone = isset($form['phone']) ? trim(strval($form['phone'])) : '';
		$password = isset($form['password']) ? trim(strval($form['password'])) : '';
		$name = isset($form['name']) ? trim(strval($form['name'])) : '';
		$address = isset($form['address']) ? trim(strval($form['address'])) : '';
		$check_week = isset($form['check_week']) ? trim(strval($form['check_week'])) : '';
		$end_time = isset($form['end_time']) ? trim(strval($form['end_time'])) : '';
		$day_paper = isset($form['day_paper']) ? $form['day_paper'] : 2;
		$week_paper = isset($form['week_paper']) ? $form['week_paper'] : 2;
		$month_paper = isset($form['month_paper']) ? $form['month_paper'] : 2;
		$down_check_time = isset($form['down_check_time']) ? $form['down_check_time'] : '';
		$runType = isset($form['runType']) ? $form['runType'] : 1;

		if ($platform === '' || $phone === '' || $end_time === '' || $check_week === '') {
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 原因：参数不完整", 0);
			up_json(array("code" => 1, "msg" => "参数不完整"));
		}
		$platformSql = up_esc($platform);
		$phoneSql = up_esc($phone);
		$count = $DB->count("select count(1) from qingka_wangke_sxdk where uid='{$childUid}' and phone='{$phoneSql}' and platform='{$platformSql}'");
		if ($count > 0) {
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 原因：本台订单已存在", 0);
			up_json(array("code" => 1, "msg" => "订单已存在"));
		}
		if (strtotime($end_time . " 23:59:59") - time() <= 0) {
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 结束日期：{$end_time} 原因：下单天数不符合规范", 0);
			up_json(array("code" => 1, "msg" => "下单天数不符合规范"));
		}
		$day = up_timeCalcTrueday(time(), $end_time, $check_week);
		$bei = up_xybBei($platform, $runType);
		$unit = up_qg_price($platform, $child);
		$money = round($unit * $day * $bei, 2);
		up_wlog($childUid, "TaiShan-Upstream准备添加", "{$platform} {$phone} 天数：{$day} 单价：{$unit} 倍率：{$bei} 应付：{$money} 余额：{$child['money']} 结束：{$end_time}", 0);
		if ($money < 0) {
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 原因：金额异常 money={$money}", 0);
			up_json(array("code" => 1, "msg" => "金额异常"));
		}
		if (floatval($child['money']) < $money) {
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 原因：余额不足 余额：{$child['money']} 应付：{$money}", 0);
			up_json(array("code" => 1, "msg" => "余额不足"));
		}

		// 打源台用原始参数，不要带 SQL 转义
		$result = dockSendPostRequest("addOrder", $form, $childUid);
		if (!up_ok($result)) {
			$rmsg = is_array($result) && isset($result['msg']) ? $result['msg'] : '源台下单失败';
			up_wlog($childUid, "TaiShan-Upstream添加失败", "{$platform} {$phone} 原因：上游/源台下单失败，{$rmsg}", 0);
			up_json(is_array($result) ? $result : array("code" => 1, "msg" => "源台下单失败"));
		}
		up_wlog($childUid, "TaiShan-Upstream上游下单成功", "{$platform} {$phone} 天数：{$day} 结束：{$end_time}", 0);
		$resultSelect = isset($result["selectOrderById"]) ? $result["selectOrderById"] : null;
		if (!$resultSelect || !up_ok($resultSelect) || !isset($resultSelect['data']) || count($resultSelect['data']) !== 1) {
			up_wlog($childUid, "TaiShan-Upstream添加异常", "{$platform} {$phone} 原因：上游已响应成功但未带回订单 selectOrderById", 0);
			up_json(array("code" => 1, "msg" => "下单失败，源台未找到订单，请联系管理员"));
		}
		$sourceId = up_esc($resultSelect['data'][0]['id']);
		up_wlog($childUid, "TaiShan-Upstream预查订单成功", "{$platform} {$phone} 上游sxdkId/源台id：{$sourceId}", 0);
		if (!isset($form["up_check_time"]) || $form["up_check_time"] == "") {
			$form["up_check_time"] = isset($form["check_time"]) ? $form["check_time"] : '';
		}
		$upSql = up_esc($form["up_check_time"]);
		$passwordSql = up_esc($password);
		$nameSql = up_esc($name);
		$addressSql = up_esc($address);
		$checkWeekSql = up_esc($check_week);
		$endTimeSql = up_esc($end_time);
		$downSql = up_esc($down_check_time);
		$dayPaperSql = up_esc($day_paper);
		$weekPaperSql = up_esc($week_paper);
		$monthPaperSql = up_esc($month_paper);
		$wxpush = array("wxpush" => "");
		if ($platform == "xyb") {
			$wxpush["runType"] = $runType;
		}
		$wxpushJson = up_esc(json_encode($wxpush, JSON_UNESCAPED_UNICODE));
		$DB->query("insert into qingka_wangke_sxdk (sxdkId,uid,platform,phone,password,code,wxpush,name,address,up_check_time,down_check_time,check_week,end_time,day_paper,week_paper,month_paper,createTime) values ('{$sourceId}','{$childUid}','{$platformSql}','{$phoneSql}','{$passwordSql}',1,'{$wxpushJson}','{$nameSql}','{$addressSql}','{$upSql}','{$downSql}','{$checkWeekSql}','{$endTimeSql}','{$dayPaperSql}','{$weekPaperSql}','{$monthPaperSql}','{$today}')");
		$localId = 0;
		if (method_exists($DB, 'insert_id')) {
			$localId = $DB->insert_id();
		}
		if (!$localId) {
			$tmp = $DB->get_row("select id from qingka_wangke_sxdk where uid='{$childUid}' and phone='{$phoneSql}' and platform='{$platformSql}' order by id desc limit 1");
			$localId = $tmp ? $tmp['id'] : 0;
		}
		if (!$localId) {
			up_wlog($childUid, "TaiShan-Upstream添加异常", "{$platform} {$phone} 上游id：{$sourceId} 原因：源台已下单但本站写库失败", 0);
			up_json(array("code" => 1, "msg" => "源台已下单但本站写库失败，请联系管理员核对"));
		}
		if (!up_deduct($DB, $childUid, $money, "下级对接下单 {$platformSql} {$phoneSql} 本台id：{$localId} 上游id：{$sourceId} 天数{$day} 扣除{$money}")) {
			up_wlog($childUid, "TaiShan-Upstream添加异常", "{$platform} {$phone} 本台id：{$localId} 上游id：{$sourceId} 原因：下单成功但扣费失败", 0);
			up_json(array("code" => 1, "msg" => "下单成功但扣费失败（余额变动），请联系管理员核对订单与余额"));
		}

		up_wlog($childUid, "TaiShan-Upstream本台添加成功", "{$platform} {$phone} 本台id：{$localId} 上游id：{$sourceId} 天数：{$day} 结束：{$end_time} 扣除{$money}", -$money);
		$resultSelect['data'][0]['id'] = intval($localId);
		up_json(array(
			"code" => 0,
			"msg" => "订单添加成功，扣除{$money}元！",
			"selectOrderById" => $resultSelect
		));
		break;
	}

	// ===== 查单（必须先命中本对接下本地单，禁止裸 id 打源台串单）=====
	case 'selectOrderById': {
		$form = $input;
		$local = null;
		if (!empty($form['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $form['id']);
		}
		if (!$local && !empty($form['phone']) && !empty($form['platform'])) {
			$phone = up_esc($form['phone']);
			$platform = up_esc($form['platform']);
			$local = $DB->get_row("select * from qingka_wangke_sxdk where uid='{$childUid}' and phone='{$phone}' and platform='{$platform}' order by id desc limit 1");
		}
		if (!$local) {
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$form['id'] = $local['sxdkId'];
		$form['platform'] = $local['platform'];
		$form['phone'] = $local['phone'];
		$result = dockSendPostRequest("selectOrderById", $form, $childUid);
		if (up_ok($result) && isset($result['data'][0])) {
			$result['data'][0]['id'] = intval($local['id']);
		}
		up_json($result);
		break;
	}

	case 'editOrder': {
		$form = $input;
		$localId = isset($form['id']) ? intval($form['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_wlog($childUid, "TaiShan-Upstream改单失败", "本台id：{$localId} 原因：您无此订单", 0);
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$platform = isset($form['platform']) ? trim(strval($form['platform'])) : $local['platform'];
		$phone = isset($form['phone']) ? trim(strval($form['phone'])) : $local['phone'];
		$password = isset($form['password']) ? trim(strval($form['password'])) : $local['password'];
		$name = isset($form['name']) ? trim(strval($form['name'])) : $local['name'];
		$address = isset($form['address']) ? trim(strval($form['address'])) : $local['address'];
		$check_week = isset($form['check_week']) ? trim(strval($form['check_week'])) : $local['check_week'];
		$end_time = isset($form['end_time']) ? trim(strval($form['end_time'])) : $local['end_time'];
		$day_paper = isset($form['day_paper']) ? $form['day_paper'] : $local['day_paper'];
		$week_paper = isset($form['week_paper']) ? $form['week_paper'] : $local['week_paper'];
		$month_paper = isset($form['month_paper']) ? $form['month_paper'] : $local['month_paper'];
		$down_check_time = isset($form['down_check_time']) ? $form['down_check_time'] : $local['down_check_time'];
		$runType = isset($form['runType']) ? $form['runType'] : 1;

		if (time() >= strtotime($local["end_time"] . " 23:59:59") && time() < strtotime($end_time . " 23:59:59")) {
			$day = up_timeCalcTrueday(time(), $end_time, $check_week);
		} else {
			if (strtotime($end_time . " 23:59:59") - strtotime($local["end_time"] . " 23:59:59") <= 0 && $local["check_week"] == $check_week) {
				$day = 0;
			} else {
				$oldDay = up_timeCalcTrueday(time(), $local["end_time"], $local["check_week"]);
				$newDay = up_timeCalcTrueday(time(), $end_time, $check_week);
				$day = $newDay - $oldDay;
				if ($day < 0) {
					$day = 0;
				}
			}
		}
		$bei = up_xybBei($platform, $runType);
		$money = round(up_qg_price($platform, $child) * $day * $bei, 2);
		up_wlog($childUid, "TaiShan-Upstream预改单", "本台id：{$localId} 上游id：{$local['sxdkId']} {$platform} {$phone} 增加天数：{$day} 原结束：{$local['end_time']} 现结束：{$end_time} 应付：{$money} 余额：{$child['money']}", 0);
		if (floatval($child['money']) < $money) {
			up_wlog($childUid, "TaiShan-Upstream改单失败", "本台id：{$localId} 原因：余额不足 余额：{$child['money']} 应付：{$money}", 0);
			up_json(array("code" => 1, "msg" => "余额不足"));
		}

		$form["id"] = $local["sxdkId"];
		$result = dockSendPostRequest("editOrder", $form, $childUid);
		if (!up_ok($result)) {
			$rmsg = is_array($result) && isset($result['msg']) ? $result['msg'] : '源台改单失败';
			up_wlog($childUid, "TaiShan-Upstream改单失败", "本台id：{$localId} 上游id：{$local['sxdkId']} 原因：{$rmsg}", 0);
			up_json(is_array($result) ? $result : array("code" => 1, "msg" => "源台改单失败"));
		}
		up_wlog($childUid, "TaiShan-Upstream上游改单成功", "本台id：{$localId} 上游id：{$local['sxdkId']}", 0);
		if (!isset($form["up_check_time"]) || $form["up_check_time"] == "") {
			$form["up_check_time"] = isset($form["check_time"]) ? $form["check_time"] : $local['up_check_time'];
		}
		$wxpush = up_processWxpushField($local["wxpush"]);
		if ($platform == "xyb") {
			$wxpush["runType"] = $runType;
		}
		$wxpushJson = up_esc(json_encode($wxpush, JSON_UNESCAPED_UNICODE));
		$upSql = up_esc($form["up_check_time"]);
		$passwordSql = up_esc($password);
		$nameSql = up_esc($name);
		$addressSql = up_esc($address);
		$downSql = up_esc($down_check_time);
		$checkWeekSql = up_esc($check_week);
		$endTimeSql = up_esc($end_time);
		$dayPaperSql = up_esc($day_paper);
		$weekPaperSql = up_esc($week_paper);
		$monthPaperSql = up_esc($month_paper);
		$DB->query("update qingka_wangke_sxdk set password='{$passwordSql}',name='{$nameSql}',address='{$addressSql}',up_check_time='{$upSql}',down_check_time='{$downSql}',check_week='{$checkWeekSql}',end_time='{$endTimeSql}',wxpush='{$wxpushJson}',day_paper='{$dayPaperSql}',week_paper='{$weekPaperSql}',month_paper='{$monthPaperSql}',updateTime='{$today}' where id='{$localId}' and uid='{$childUid}'");
		if ($money > 0 && !up_deduct($DB, $childUid, $money, "下级对接改单 {$platform} {$phone} 本台id：{$localId} 增加天数{$day} 扣除{$money}")) {
			up_wlog($childUid, "TaiShan-Upstream改单异常", "本台id：{$localId} 原因：改单成功但扣费失败", 0);
			up_json(array("code" => 1, "msg" => "改单成功但扣费失败，请联系管理员核对余额"));
		}
		up_wlog($childUid, "TaiShan-Upstream改单成功", "{$platform} {$phone} 本台id：{$localId} 增加天数：{$day} 原结束：{$local['end_time']} 现结束：{$end_time} 扣除{$money}", -$money);
		up_json(array("code" => 0, "msg" => "订单修改成功,扣费：{$money}", "msgs" => isset($result["msg"]) ? $result["msg"] : ""));
		break;
	}

	case 'deleteOrder': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_wlog($childUid, "TaiShan-Upstream删单失败", "本台id：{$localId} 原因：您无此订单", 0);
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		up_wlog($childUid, "TaiShan-Upstream预删单", "本台id：{$localId} 上游id：{$local['sxdkId']} {$local['platform']} {$local['phone']} 结束：{$local['end_time']}", 0);
		$payload = up_mapIdToSource($local);
		$resp = dockSendPostRequest("deleteOrder", $payload, $childUid);
		if (!up_ok($resp)) {
			$rmsg = isset($resp["msg"]) ? $resp["msg"] : "删除失败";
			up_wlog($childUid, "TaiShan-Upstream删单失败", "本台id：{$localId} 上游id：{$local['sxdkId']} 原因：{$rmsg}", 0);
			up_json(array("code" => 1, "msg" => $rmsg));
		}
		$other_msg = "";
		$money = 0;
		global $delReturnMoney;
		if ($delReturnMoney) {
			$day = up_timeCalcTrueday(time(), $local["end_time"], $local["check_week"]);
			$wxpush = up_processWxpushField($local["wxpush"]);
			$runType = isset($wxpush['runType']) ? $wxpush['runType'] : 1;
			$bei = up_xybBei($local["platform"], $runType);
			$money = round(up_qg_price($local["platform"], $child) * $day * $bei, 2);
			if ($money > 0 && strtotime($local["end_time"] . " 23:59:59") > time()) {
				$other_msg = ",订单未到期，已退款：{$money}";
				up_refund($DB, $childUid, $money, "下级对接删单退款 {$local['phone']} 本台id：{$localId} {$money}");
			} else {
				$other_msg = ",此订单已到期，无需退款";
			}
		}
		$DB->query("delete from qingka_wangke_sxdk where id='{$localId}' and uid='{$childUid}'");
		up_wlog($childUid, "TaiShan-Upstream删单成功", "本台id：{$localId} 上游id：{$local['sxdkId']} {$local['platform']} {$local['phone']} {$other_msg}", +$money);
		up_json(array("code" => 0, "msg" => "删除成功" . $other_msg));
		break;
	}

	case 'nowCheck': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_wlog($childUid, "TaiShan-Upstream立即打卡失败", "本台id：{$localId} 原因：您无此订单", 0);
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$money = round(up_qg_price($local['platform'], $child), 2);
		up_wlog($childUid, "TaiShan-Upstream预立即打卡", "本台id：{$localId} 上游id：{$local['sxdkId']} {$local['platform']} {$local['phone']} 应付：{$money}", 0);
		if (floatval($child['money']) < $money) {
			up_wlog($childUid, "TaiShan-Upstream立即打卡失败", "本台id：{$localId} 原因：余额不足", 0);
			up_json(array("code" => 1, "msg" => "余额不足"));
		}
		$payload = array("id" => $local['sxdkId'], "platform" => $local['platform']);
		$result = dockSendPostRequest("nowCheck", $payload, $childUid);
		if (up_ok($result)) {
			if (!up_deduct($DB, $childUid, $money, "下级立即打卡 {$local['platform']} {$local['phone']} 本台id：{$localId} 扣除{$money}")) {
				$result["msg"] = (isset($result["msg"]) ? $result["msg"] : "") . "（警告：扣费失败请联系管理员）";
				up_wlog($childUid, "TaiShan-Upstream立即打卡异常", "本台id：{$localId} 上游成功但扣费失败", 0);
			} else {
				up_wlog($childUid, "TaiShan-Upstream立即打卡成功", "本台id：{$localId} {$local['platform']} {$local['phone']} 扣除{$money}", -$money);
			}
		} else {
			$rmsg = isset($result["msg"]) ? $result["msg"] : "失败";
			up_wlog($childUid, "TaiShan-Upstream立即打卡失败", "本台id：{$localId} 原因：{$rmsg}", 0);
		}
		up_json($result);
		break;
	}

	case 'buPapers': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_wlog($childUid, "TaiShan-Upstream补报告失败", "本台id：{$localId} 原因：您无此订单", 0);
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$payload = array(
			"id" => $local['sxdkId'],
			"platform" => $local['platform'],
			"startTime" => isset($input['startTime']) ? $input['startTime'] : '',
			"endTime" => isset($input['endTime']) ? $input['endTime'] : '',
			"type" => isset($input['type']) ? $input['type'] : (isset($input['levelName']) ? $input['levelName'] : ''),
		);
		$result = dockSendPostRequest("buPapers", $payload, $childUid);
		$levelName = $payload['type'];
		if (up_ok($result)) {
			up_wlog($childUid, "TaiShan-Upstream补报告成功", "本台id：{$localId} 类型：{$levelName} 区间：{$payload['startTime']}-{$payload['endTime']}", 0);
		} else {
			$rmsg = isset($result["msg"]) ? $result["msg"] : "失败";
			up_wlog($childUid, "TaiShan-Upstream补报告失败", "本台id：{$localId} 类型：{$levelName} 原因：{$rmsg}", 0);
		}
		up_json($result);
		break;
	}

	case 'xybBindWx': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_wlog($childUid, "TaiShan-Upstream绑微失败", "本台id：{$localId} 原因：您无此订单", 0);
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		if ($local['platform'] != 'xyb') {
			up_json(array("code" => 1, "msg" => "该接口仅xyb可用"));
		}
		if (time() > strtotime($local["end_time"] . " 23:59:59")) {
			$day = 0;
		} else {
			$day = up_timeCalcTrueday(time(), $local["end_time"], $local["check_week"]);
			if ($day < 0) {
				$day = 0;
			}
		}
		$unit = up_qg_price($local["platform"], $child);
		$bindMoney = $unit * 1000;
		$editMoney = $unit * $day * 4;
		$money = round($bindMoney + $editMoney, 2);
		up_wlog($childUid, "TaiShan-Upstream预绑微", "本台id：{$localId} 上游id：{$local['sxdkId']} 剩余天数：{$day} 应付：{$money}（绑微{$bindMoney}+补齐{$editMoney}）余额：{$child['money']}", 0);
		if (floatval($child['money']) < $money) {
			up_wlog($childUid, "TaiShan-Upstream绑微失败", "本台id：{$localId} 原因：余额不足", 0);
			up_json(array("code" => 1, "msg" => "余额不足"));
		}
		$payload = up_mapIdToSource($local);
		$payload["v"] = 1;
		$result = dockSendPostRequest("xybBindWx", $payload, $childUid);
		if (!up_ok($result)) {
			$rmsg = isset($result["msg"]) ? $result["msg"] : "失败";
			up_wlog($childUid, "TaiShan-Upstream绑微失败", "本台id：{$localId} 上游id：{$local['sxdkId']} 原因：{$rmsg}", 0);
			up_json($result);
		}
		if (!up_deduct($DB, $childUid, $money, "下级xyb绑微 {$local['phone']} 本台id：{$localId} 扣除{$money}")) {
			up_wlog($childUid, "TaiShan-Upstream绑微异常", "本台id：{$localId} 原因：绑微成功但扣费失败", 0);
			up_json(array("code" => 1, "msg" => "绑微成功但扣费失败，请联系管理员核对余额"));
		}
		$wxpush = up_processWxpushField($local["wxpush"]);
		$wxpush["runType"] = 3;
		$wxpushJson = up_esc(json_encode($wxpush, JSON_UNESCAPED_UNICODE));
		$DB->query("update qingka_wangke_sxdk set wxpush='{$wxpushJson}' where id='{$localId}' and uid='{$childUid}' limit 1");
		up_wlog($childUid, "TaiShan-Upstream绑微成功", "本台id：{$localId} 上游id：{$local['sxdkId']} 扣除{$money}", -$money);
		$result["msg"] = (isset($result["msg"]) ? $result["msg"] : "") . "扣除费用：{$money}";
		up_json($result);
		break;
	}

	case 'setCheckCode': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$code = up_esc(isset($input['code']) ? $input['code'] : '');
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$payload = array("id" => $local['sxdkId'], "platform" => $local['platform'], "code" => isset($input['code']) ? $input['code'] : '');
		$result = dockSendPostRequest("setCheckCode", $payload, $childUid);
		if (up_ok($result)) {
			$DB->query("update qingka_wangke_sxdk set code='{$code}' where id='{$localId}' and uid='{$childUid}'");
		}
		up_json($result);
		break;
	}

	case 'setHolidayCode': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$code = isset($input['code']) ? $input['code'] : '';
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$payload = array("id" => $local['sxdkId'], "platform" => $local['platform'], "code" => $code);
		up_json(dockSendPostRequest("setHolidayCode", $payload, $childUid));
		break;
	}

	case 'getLog': {
		// 子站可能传本站 id，或 phone+platform
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$payload = array("phone" => $local['phone'], "platform" => $local['platform']);
		} else {
			$payload = array(
				"phone" => isset($input['phone']) ? $input['phone'] : '',
				"platform" => isset($input['platform']) ? $input['platform'] : '',
			);
		}
		up_json(dockSendPostRequest("getLog", $payload, $childUid));
		break;
	}

	case 'getAsyncTask': {
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$payload = array("phone" => $local['phone'], "platform" => $local['platform']);
		} else {
			$payload = array(
				"phone" => isset($input['phone']) ? $input['phone'] : '',
				"platform" => isset($input['platform']) ? $input['platform'] : '',
			);
		}
		up_json(dockSendPostRequest("getAsyncTask", $payload, $childUid));
		break;
	}

	case 'getWxPush': {
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$payload = array("phone" => $local['phone'], "platform" => $local['platform']);
		} else {
			$payload = $input;
		}
		up_json(dockSendPostRequest("getWxPush", $payload, $childUid));
		break;
	}

	case 'wxpush': {
		$localId = isset($input['id']) ? intval($input['id']) : 0;
		$local = up_getLocalOrder($DB, $childUid, $localId);
		if (!$local) {
			up_json(array("code" => 1, "msg" => "您无此订单"));
		}
		$form = $input;
		$form['id'] = $local['sxdkId'];
		$form['platform'] = $local['platform'];
		$result = dockSendPostRequest("wxpush", $form, $childUid);
		if (up_ok($result)) {
			$wxpush = up_processWxpushField($local["wxpush"]);
			if (isset($form["wxpushUid"])) {
				$wxpush["wxpush"] = $form["wxpushUid"];
			} elseif (isset($form["wxpush"])) {
				$wxpush["wxpush"] = $form["wxpush"];
			}
			$wxpushJson = up_esc(json_encode($wxpush, JSON_UNESCAPED_UNICODE));
			$DB->query("update qingka_wangke_sxdk set wxpush='{$wxpushJson}' where id='{$localId}' and uid='{$childUid}' limit 1");
		}
		up_json($result);
		break;
	}

	case 'getCommentList': {
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$payload = array("id" => $local['sxdkId'], "platform" => $local['platform'], "uid" => $commentTraceUid);
		} else {
			$payload = array("uid" => $commentTraceUid);
		}
		up_json(dockSendPostRequest("getCommentList", $payload, $childUid));
		break;
	}

	case 'addComment': {
		$question = isset($input['question']) ? $input['question'] : '';
		$payload = array("uid" => $commentTraceUid, "question" => $question);
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$payload['id'] = $local['sxdkId'];
			$payload['platform'] = $local['platform'];
		}
		up_json(dockSendPostRequest("addComment", $payload, $childUid));
		break;
	}

	case 'searchPhoneInfo': {
		up_json(dockSendPostRequest("searchPhoneInfo", $input, $childUid));
		break;
	}

	case 'xxyAddressSearchPoi': {
		up_json(dockSendPostRequest("xxyAddressSearchPoi", $input, $childUid));
		break;
	}

	case 'xxtGetSchoolList': {
		up_json(dockSendPostRequest("xxtGetSchoolList", $input, $childUid));
		break;
	}

	case 'xxyGetSchoolList': {
		$cacheFile = __DIR__ . "/temp/xxyGetSchoolList.json";
		if (file_exists($cacheFile) && up_isCacheValid($cacheFile, 60 * 60 * 12)) {
			$cacheData = file_get_contents($cacheFile);
			if ($cacheData) {
				exit($cacheData);
			}
		}

		// 无缓存：先直连官方（不经源台）
		$fetchOfficial = function ($url) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'User-Agent: Mozilla/5.0',
				'Accept: application/json',
			));
			$raw = curl_exec($ch);
			curl_close($ch);
			if (!$raw) {
				return null;
			}
			$decoded = json_decode($raw, true);
			return is_array($decoded) ? $decoded : null;
		};

		$resp = $fetchOfficial("https://api.xixunyun.com/login/schoolmap?from=app");
		$respnx = $fetchOfficial("http://sxapi.nxeduyun.com/login/schoolmap?from=app");

		$response = array('code' => 20000, 'data' => array());
		if (!empty($resp) && isset($resp["code"]) && $resp["code"] == 20000 && !empty($resp["data"])) {
			foreach ($resp["data"] as $groud) {
				$temp = array('schools' => array());
				if (!empty($groud["schools"])) {
					foreach ($groud["schools"] as $school) {
						$temp['schools'][] = array(
							'differ_api' => isset($school["differ_api"]) ? $school["differ_api"] : '',
							'school_name' => $school['school_name'],
							'school_id' => $school['school_id'],
						);
					}
				}
				$response["data"][] = $temp;
			}
		}
		if (!empty($respnx) && isset($respnx["code"]) && $respnx["code"] == 20000 && !empty($respnx["data"])) {
			foreach ($respnx["data"] as $groud) {
				$temp = array('schools' => array());
				if (!empty($groud["schools"])) {
					foreach ($groud["schools"] as $school) {
						if (isset($school["differ_api"]) && $school["differ_api"]) {
							continue;
						}
						$school["differ_api"] = "http://sxapi.nxeduyun.com/";
						$temp['schools'][] = array(
							'differ_api' => $school["differ_api"],
							'school_name' => $school['school_name'],
							'school_id' => $school['school_id'],
						);
					}
				}
				$response["data"][] = $temp;
			}
		}

		if (count($response["data"]) > 10) {
			$data = json_encode($response, JSON_UNESCAPED_UNICODE);
			@file_put_contents($cacheFile, $data);
			exit($data);
		}

		// 官方失败再回退源台
		$result = dockSendPostRequest("xxyGetSchoolList", array(), $childUid);
		if (isset($result['data']) && is_array($result['data']) && count($result['data']) > 10) {
			@file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
		}
		up_json($result);
		break;
	}

	case 'hzjGetSchoolList': {
		// 与 api.php 一致：直连官方，不经源台
		$ch = curl_init("https://hzj.gzdekan.com/api/getSchoolListWeb?authorization=");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "");
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Mozilla/5.0'));
		$raw = curl_exec($ch);
		curl_close($ch);
		if ($raw) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				exit($raw);
			}
			// 非 JSON 也原样返回（与 api.php 行为一致）
			exit($raw);
		}
		// 官方失败再回退源台
		$result = dockSendPostRequest("hzjGetSchoolList", array(), $childUid);
		up_json($result);
		break;
	}

	default: {
		// 未知接口：有 id 则必须属于本对接，禁止拿别人的 id 打源台
		if (!empty($input['id'])) {
			$local = up_getLocalOrder($DB, $childUid, $input['id']);
			if (!$local) {
				up_json(array("code" => 1, "msg" => "您无此订单"));
			}
			$input['id'] = $local['sxdkId'];
			if (empty($input['platform'])) {
				$input['platform'] = $local['platform'];
			}
			if (empty($input['phone'])) {
				$input['phone'] = $local['phone'];
			}
		}
		up_json(dockSendPostRequest($act, $input, $childUid));
		break;
	}
}

// ---------- 以下同 api.php：辅助函数放后部，对接请求放文件末尾 ----------

function up_getDayOfWeek($timestamp)
{
	$date = new DateTime(date('Y-m-d H:i:s', $timestamp));
	return intval($date->format('N')) - 1;
}

function up_formatDate($timestamp)
{
	return date('Y-m-d', $timestamp);
}

function up_timeCalcTrueday($nowsjc, $end_time, $check_week)
{
	$check_week = explode(",", $check_week);
	$check_week = array_map('intval', $check_week);
	sort($check_week);
	$nowWeekDay = up_getDayOfWeek($nowsjc);
	$endSjc = strtotime($end_time . " 23:59:59");
	if ($endSjc < $nowsjc) {
		return 0;
	}
	$weekEndSjc = strtotime(up_formatDate($nowsjc + (6 - $nowWeekDay) * 86400) . " 23:59:59");
	$nowWeekLast = array_filter($check_week, function ($item) use ($nowWeekDay) {
		return (int) $item >= $nowWeekDay;
	});
	$endWeekDay = up_getDayOfWeek($endSjc);
	if ($endSjc <= $weekEndSjc) {
		$lastWeekLast = array_filter($nowWeekLast, function ($item) use ($endWeekDay) {
			return (int) $item <= $endWeekDay;
		});
		return count($lastWeekLast);
	}
	$endWeekLast = array_filter($check_week, function ($item) use ($endWeekDay) {
		return (int) $item <= $endWeekDay;
	});
	$intSjc = $endSjc - ($endWeekDay + 1) * 86400 - $weekEndSjc;
	return (count($nowWeekLast) + ($intSjc / 7 / 86400) * count($check_week) + count($endWeekLast));
}

function up_processWxpushField($wxpush)
{
	if ($wxpush === null || $wxpush === '') {
		return array("wxpush" => null);
	}
	$decoded = json_decode($wxpush, true);
	if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
		return $decoded;
	}
	return array("wxpush" => $wxpush);
}

function up_isCacheValid($file, $expiry)
{
	if (!file_exists($file)) {
		return false;
	}
	return (time() - filemtime($file)) < $expiry;
}

function up_mapIdToSource($order)
{
	return array(
		"id" => $order['sxdkId'],
		"platform" => $order['platform'],
		"phone" => $order['phone'],
		"password" => $order['password'],
		"check_week" => $order['check_week'],
		"end_time" => $order['end_time'],
		"wxpush" => $order['wxpush'],
		"code" => $order['code'],
		"name" => $order['name'],
		"address" => $order['address'],
		"up_check_time" => $order['up_check_time'],
		"down_check_time" => $order['down_check_time'],
		"day_paper" => $order['day_paper'],
		"week_paper" => $order['week_paper'],
		"month_paper" => $order['month_paper'],
	);
}

function up_esc($v)
{
	if (is_array($v)) {
		return $v;
	}
	return daddslashes(trim(strval($v)));
}

function up_ok($result)
{
	return is_array($result) && isset($result["code"]) && intval($result["code"]) === 0;
}

function up_xybBei($platform, $runType)
{
	if ($platform == 'xyb' && intval($runType) === 3) {
		return 5;
	}
	return 1;
}

function up_parseAct($get = null, $server = null)
{
	if ($get === null) {
		$get = $_GET;
	}
	if ($server === null) {
		$server = $_SERVER;
	}
	$act = '';
	if (!empty($get['act'])) {
		$act = trim($get['act']);
	} elseif (!empty($server['PATH_INFO'])) {
		$act = trim($server['PATH_INFO'], '/');
	} elseif (!empty($server['ORIG_PATH_INFO'])) {
		$act = trim($server['ORIG_PATH_INFO'], '/');
	}
	if ($act === '' && !empty($server['REQUEST_URI'])) {
		if (preg_match('#upstream\.php/([a-zA-Z0-9_]+)#', $server['REQUEST_URI'], $m)) {
			$act = $m[1];
		}
	}
	if (strpos($act, '/') !== false) {
		$parts = explode('/', $act);
		$act = end($parts);
	}
	return preg_replace('/[^a-zA-Z0-9_]/', '', $act);
}

function up_extractAuth($input)
{
	$authUid = isset($input['admin']) ? trim(strip_tags(strval($input['admin']))) : '';
	$authKey = isset($input['token']) ? trim(strip_tags(strval($input['token']))) : '';
	if ($authUid === '' && isset($input['uid'])) {
		$authUid = trim(strip_tags(strval($input['uid'])));
	}
	if ($authKey === '' && isset($input['key'])) {
		$authKey = trim(strip_tags(strval($input['key'])));
	}
	return array($authUid, $authKey);
}

function up_validateChildKey($child, $authKey)
{
	if (!$child) {
		return "用户不存在";
	}
	if (!isset($child['key']) || $child['key'] === '0' || $child['key'] === '' || $child['key'] === 0) {
		return "你还没有开通接口哦";
	}
	if ($child['key'] != $authKey) {
		return "密匙错误";
	}
	return null;
}

function up_calcOrderMoney($platform, $child, $end_time, $check_week, $runType = 1, $now = null)
{
	if ($now === null) {
		$now = time();
	}
	$day = up_timeCalcTrueday($now, $end_time, $check_week);
	$bei = up_xybBei($platform, $runType);
	return array(
		'day' => $day,
		'bei' => $bei,
		'money' => round(up_qg_price($platform, $child) * $day * $bei, 2),
	);
}

function up_calcEditExtraDays($oldEnd, $oldWeek, $newEnd, $newWeek, $now = null)
{
	if ($now === null) {
		$now = time();
	}
	if ($now >= strtotime($oldEnd . " 23:59:59") && $now < strtotime($newEnd . " 23:59:59")) {
		return up_timeCalcTrueday($now, $newEnd, $newWeek);
	}
	if (strtotime($newEnd . " 23:59:59") - strtotime($oldEnd . " 23:59:59") <= 0 && $oldWeek == $newWeek) {
		return 0;
	}
	$oldDay = up_timeCalcTrueday($now, $oldEnd, $oldWeek);
	$newDay = up_timeCalcTrueday($now, $newEnd, $newWeek);
	$day = $newDay - $oldDay;
	return $day < 0 ? 0 : $day;
}

function up_calcXybBindMoney($child, $end_time, $check_week, $now = null)
{
	if ($now === null) {
		$now = time();
	}
	if ($now > strtotime($end_time . " 23:59:59")) {
		$day = 0;
	} else {
		$day = up_timeCalcTrueday($now, $end_time, $check_week);
		if ($day < 0) {
			$day = 0;
		}
	}
	$unit = up_qg_price('xyb', $child);
	$bindMoney = $unit * 1000;
	$editMoney = $unit * $day * 4;
	return array(
		'day' => $day,
		'bindMoney' => round($bindMoney, 2),
		'editMoney' => round($editMoney, 2),
		'money' => round($bindMoney + $editMoney, 2),
	);
}

function up_calcDeleteRefund($platform, $child, $end_time, $check_week, $wxpushRaw, $now = null)
{
	if ($now === null) {
		$now = time();
	}
	$day = up_timeCalcTrueday($now, $end_time, $check_week);
	$wxpush = up_processWxpushField($wxpushRaw);
	$runType = isset($wxpush['runType']) ? $wxpush['runType'] : 1;
	$bei = up_xybBei($platform, $runType);
	$money = round(up_qg_price($platform, $child) * $day * $bei, 2);
	$canRefund = ($money > 0 && strtotime($end_time . " 23:59:59") > $now);
	return array(
		'day' => $day,
		'money' => $canRefund ? $money : 0,
		'canRefund' => $canRefund,
	);
}

/**
 * 反馈用 uid：本层对接 uid + '_' + 下层 uid（可多层套娃）
 * 注意：整串须按字符串交给源台，禁止 intval。
 */
function up_traceUid($childUid, $clientUid)
{
	$childUid = trim(strval($childUid));
	$clientUid = preg_replace('/[^0-9A-Za-z_]/', '', trim(strval($clientUid)));
	if ($clientUid === '' || $clientUid === $childUid) {
		return $childUid;
	}
	return $childUid . '_' . $clientUid;
}

function up_getLocalOrder($DB, $childUid, $localId)
{
	$localId = intval($localId);
	return $DB->get_row("select * from qingka_wangke_sxdk where uid='{$childUid}' and id='{$localId}' limit 1");
}

/** 套娃上游日志（对接账号 uid） */
function up_wlog($childUid, $title, $msg, $money = 0)
{
	if (function_exists('wlog')) {
		wlog($childUid, $title, $msg, $money);
	}
}

/** 网络日志：保留域名+path，去掉 query */
function up_log_url($url)
{
	$url = strval($url);
	$p = @parse_url($url);
	if (!is_array($p) || empty($p['host'])) {
		return preg_replace('/\?.*$/', '', $url);
	}
	$scheme = !empty($p['scheme']) ? $p['scheme'] . '://' : '';
	$host = $p['host'];
	$port = isset($p['port']) ? ':' . $p['port'] : '';
	$path = isset($p['path']) ? $p['path'] : '';
	return $scheme . $host . $port . $path;
}

function up_deduct($DB, $childUid, $money, $logText)
{
	$money = round(floatval($money), 2);
	if ($money <= 0) {
		return true;
	}
	$before = $DB->get_row("select money from qingka_wangke_user where uid='{$childUid}' limit 1");
	$DB->query("update qingka_wangke_user set money=money-'{$money}' where uid='{$childUid}' and money>='{$money}' limit 1 ");
	$after = $DB->get_row("select money from qingka_wangke_user where uid='{$childUid}' limit 1");
	$ok = $before && $after && (floatval($after['money']) <= floatval($before['money']) - $money + 0.001);
	if ($ok) {
		up_wlog($childUid, "TaiShan-Upstream扣费", $logText . "，余额：" . ($before['money']) . "→" . ($after['money']), -$money);
	} else {
		up_wlog($childUid, "TaiShan-Upstream扣费失败", "{$logText}，扣费前余额：" . ($before ? $before['money'] : 'null') . "，扣费后：" . ($after ? $after['money'] : 'null'), 0);
	}
	return $ok;
}

function up_refund($DB, $childUid, $money, $logText)
{
	$money = round(floatval($money), 2);
	if ($money <= 0) {
		return;
	}
	$before = $DB->get_row("select money from qingka_wangke_user where uid='{$childUid}' limit 1");
	$DB->query("update qingka_wangke_user set money=money+'{$money}' where uid='{$childUid}' limit 1 ");
	$after = $DB->get_row("select money from qingka_wangke_user where uid='{$childUid}' limit 1");
	up_wlog($childUid, "TaiShan-Upstream退款", $logText . "，余额：" . ($before ? $before['money'] : '?') . "→" . ($after ? $after['money'] : '?'), +$money);
}

function up_json($data)
{
	exit(json_encode($data));
}

/**
 * 打真正源台（token/账号在 setting.php 配置）
 */
function dockSendPostRequest($urlEnd, $jsonData, $logUid = 0)
{
	global $sxdk_token, $sxdk_admin, $sxdk_ts_version, $sxdk_urlList;
	$token = $sxdk_token;
	$admin = $sxdk_admin;

	if (!sxdk_credentials_ready()) {
		return array("code" => 1, "msg" => "上级站未配置源台 token/账号，请编辑 sxdk/setting.php");
	}

	$jsonData["admin"] = $admin;
	$jsonData["token"] = $token;
	$jsonData["ts_version"] = $sxdk_ts_version;

	$urlList = $sxdk_urlList;
	$timeOut = -1;
	while ($timeOut < count($urlList) * 3) {
		$timeOut++;
		$url = $urlList[intdiv($timeOut, 3)] . $urlEnd;
		$ch = curl_init($url);
		$jsonDataEncoded = json_encode($jsonData);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=UTF-8'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
		$result = curl_exec($ch);
		$curlErr = curl_error($ch);
		curl_close($ch);
		if ($result) {
			$decoded = json_decode($result, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		up_wlog($logUid, "TaiShan-Upstream网络异常", up_log_url($url) . ($curlErr ? "，curl:{$curlErr}" : ""), 0);
		if (strpos($url, "addOrder") !== false) {
			$phoneLog = isset($jsonData['phone']) ? $jsonData['phone'] : '';
			$platformLog = isset($jsonData['platform']) ? $jsonData['platform'] : '';
			up_wlog($logUid, "TaiShan-Upstream下单网络异常回查", "账号：{$phoneLog} 平台：{$platformLog} url：" . up_log_url($url), 0);
			for ($i = 0; $i < 3; $i++) {
				if ($i > 0) {
					sleep(1);
				}
				$resultSelect = dockSendPostRequest("selectOrderById", $jsonData, $logUid);
				if (isset($resultSelect["code"]) && intval($resultSelect["code"]) === 0 && isset($resultSelect['data']) && count($resultSelect['data']) === 1) {
					try {
						$localTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
						$createTime = new DateTime($resultSelect['data'][0]["createTime"], new DateTimeZone('Asia/Shanghai'));
						$diff = abs($createTime->getTimestamp() - $localTime->getTimestamp());
					} catch (Exception $e) {
						$diff = 0;
					}
					if ($diff <= 300) {
						up_wlog($logUid, "TaiShan-Upstream下单网络异常判定成功", "账号：{$phoneLog} 平台：{$platformLog} 回查命中，时间差{$diff}秒", 0);
						return array("code" => 0, "msg" => "网络异常，判定为添加成功", "selectOrderById" => $resultSelect);
					}
				}
			}
			up_wlog($logUid, "TaiShan-Upstream下单网络异常判定失败", "账号：{$phoneLog} 平台：{$platformLog} 回查未命中", 0);
			return array("code" => 1, "msg" => "添加订单网络异常，下单失败");
		}
	}
	return array("code" => 1, "msg" => "网络异常，源台无法连接，请反馈给管理员");
}
