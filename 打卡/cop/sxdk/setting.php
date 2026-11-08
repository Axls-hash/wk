<?php
/**
 * TaiShan 对接统一配置（api.php / cron.php / upstream.php 共用）
 * 改价格、退款开关、对接账号 token、对接地址：只改这一处。
 *
 * ---------- 两种用法 ----------
 * A）本站直连真正源台（默认）
 *    $sxdk_admin / $sxdk_token = 源台给你的账号、token
 *    $sxdk_urlList = 源台 /copilot/ 地址（可保留下方默认多个）
 *
 * B）本站作为「套娃下级」对接别人的 upstream（不当直连源台）
 *    $sxdk_admin   = 你在上级站的 uid
 *    $sxdk_token   = 你在上级站开通的 key（非 0）
 *    $sxdk_urlList = ["https://上级域名/sxdk/upstream.php/"];  // 末尾保留 /
 *    （上级怎么开 uid/key，见上级站 upstream.php 文件头注释）
 *
 * C）本站作为「套娃上级」
 *    仍按 A 填源台账密；另外上传 upstream.php，给下级开 key
 */

// ---------- 删除订单是否退款 ----------
// 是：true   否：false    // 仅删除退款，修改订单结束时间不退款
if (!isset($delReturnMoney)) {
	$delReturnMoney = true;
}

// ---------- 对接账号（源台 或 套娃上级，见文件头说明）----------
if (!isset($sxdk_token)) {
	$sxdk_token = "您的token";           // 源台 token；套娃下级则填上级给的 key
}
if (!isset($sxdk_admin)) {
	$sxdk_admin = "您的TaiShan账号";     // 源台账号；套娃下级则填你在上级站的 uid
}
if (!isset($sxdk_ts_version)) {
	$sxdk_ts_version = "260901";  // 版本号，与前端 copilot 显示保持一致
}
// 对接地址：直连源台用 /copilot/；套娃下级改成上级 upstream.php/（末尾 /）
if (!isset($sxdk_urlList)) {
	$sxdk_urlList = array(
		"http://location.copilotai.top:4007/copilot/",
		"http://82.156.247.209:4007/copilot/",
		"http://location.tspost.top:4007/copilot/",
		// 套娃下级示例（启用时删掉上面源台地址，只留这一行）：
		// "https://上级域名/sxdk/upstream.php/",
	);
}

/**
 * 倍率前单价（元/天），各平台单独改
 */
if (!function_exists('sxdk_platform_price')) {
	function sxdk_platform_price($platform)
	{
		$money = 10;  //默认
		if ($platform == "zxjy")
			$money = 0.6;   //职校家园倍率前价格/天
		if ($platform == "qzt")
			$money = 0.6;   //黔职通倍率前价格/天
		if ($platform == "xyb")
			$money = 0.6;   //校友帮倍率前价格/天
		if ($platform == "gxy")
			$money = 0.6;   //工学云倍率前价格/天
		if ($platform == "xxy")
			$money = 0.6;   //习讯云倍率前价格/天
		if ($platform == "xxt")
			$money = 0.6;   //学习通倍率前价格/天
		if ($platform == "hzj")
			$money = 0.6;   //慧职教倍率前价格/天
		if ($platform == "gxzy")
			$money = 0.6;   //广西职业倍率前价格/天
		if ($platform == "jxzhjy")
			$money = 0.6;   //江西智慧教育倍率前价格/天
		if ($platform == "cxy")
			$money = 0.6;   //成学云倍率前价格/天
		if ($platform == "bx")
			$money = 0.6;   //博行倍率前价格/天
		return $money;
	}
}

/**
 * 本站前台计价：平台单价 × 当前登录用户 addprice
 */
if (!function_exists('qg_price')) {
	function qg_price($platform)
	{
		global $userrow;
		return round(floatval($userrow['addprice']) * sxdk_platform_price($platform), 2);
	}
}

/**
 * 套娃上级网关计价：平台单价 × 下级用户 addprice
 */
if (!function_exists('up_qg_price')) {
	function up_qg_price($platform, $child)
	{
		return round(floatval($child['addprice']) * sxdk_platform_price($platform), 2);
	}
}

/**
 * 是否已填写真实源台凭证
 */
if (!function_exists('sxdk_credentials_ready')) {
	function sxdk_credentials_ready()
	{
		global $sxdk_token, $sxdk_admin;
		return !($sxdk_token === "您的token" || $sxdk_admin === "您的TaiShan账号" || $sxdk_token === "" || $sxdk_admin === "");
	}
}
