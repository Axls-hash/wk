<?php
include('../confing/common.php');
include(__DIR__ . '/setting.php');

function qg_laqu()
{
    global $DB;
    $result = sendPostRequest("yunOrder", array());
    if ($result["code"] == 0) {
        foreach ($result['data'] as $row) {

            $order = $DB->get_row("select * from qingka_wangke_sxdk where sxdkId='{$row['id']}' and platform='{$row['platform']}' limit 1 ");
            if ($order) {

                $is = $DB->query("update qingka_wangke_sxdk set code='{$row['code']}',wxpush='{$row['wxpush']}',end_time='{$row['end_time']}' where id='{$order['id']}'");
            }
        }
        $countNum = count($result["data"]);
        echo "拉取完成！同步：'$countNum'条成功";
    } else {
        echo "拉取失败：'{$result['msg']}'";
    }
}
function sendPostRequest($urlEnd, $jsonData){   
	global $sxdk_token, $sxdk_admin, $sxdk_ts_version, $sxdk_urlList;
	$token = $sxdk_token;
	$admin = $sxdk_admin;

	$jsonData["admin"] = $admin;
	$jsonData["token"] = $token;
	$jsonData["ts_version"] = $sxdk_ts_version;
	
	$urlList=$sxdk_urlList;
	$timeOut=-1;
	while($timeOut<count($urlList)*3){
	    $timeOut++;
	    $url=$urlList[intdiv($timeOut, 3)].$urlEnd;
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
    	curl_close($ch);
    	if($result){
        	$decoded = json_decode($result, true);
        	if (is_array($decoded)) {
                return $decoded;
            }
    	}
	}
	return array("code" => 1, "msg" => "网络异常，源台无法连接，请反馈给管理员");
}
$r = qg_laqu();
?>
