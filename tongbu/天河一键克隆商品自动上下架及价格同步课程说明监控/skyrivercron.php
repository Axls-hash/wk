<?php
include('../confing/common.php');
$hid = 1;//对接天河的货源id，一般在货源页面查看，填错了会有问题请自行检查
$pricee = 5;//1.3获取到的商品按定价价格提高30% 填1=跟天河skyriver源站价格完全相同，其他自行倍数计算
$dockcro = 0; // 是否新建数据库，无数据默认1即可，有数据默认0 0则上架商品同时更新价格 
//1会全面克隆分类和商品1-1复刻 0 则只上架商品
$skipCategories = array('0', '0', '0', '0'); // 替换成您实际要跳过的上游分类 ID 格式以此类推，填了代表不上架也不更新这几个分类的项目
$act = isset($_GET['act']) ? daddslashes($_GET['act']) : null;
if ($act == 'cookie') {
    qcookie();
}
//一键执行操作之前记着先备份
//一键执行操作之前记着先备份
//一键执行操作之前记着先备份
// 执行一键对接如果是新建数据库推荐这个一键克隆  
// 执行一键对接如果是新建数据库推荐这个一键克隆 
// 执行一键对接如果是新建数据库推荐这个一键克隆 
// 执行一键对接如果是新建数据库推荐这个一键克隆 
// 执行一键对接如果是新建数据库推荐这个一键克隆 
// 执行一键对接如果是新建数据库推荐这个一键克隆 

elseif ($act == 'yijiankelong') { // 执行一键对接新建网址推荐这个一键克隆  
    $a = $DB->get_row("SELECT * FROM qingka_wangke_huoyuan WHERE hid='{$hid}'");
    if (!$a) {
        jsonReturn(-1, "货源信息不存在");
    }
    $data = array("uid" => $a["user"], "key" => $a["pass"]);
    $er_url = "{$a["url"]}/api.php?act=getclass";
    $result = get_url($er_url, $data);
    $result1 = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result1["data"])) {
        jsonReturn(-1, "API 返回数据格式错误或缺失");
    }
    $categories = $result1["data"];
    $numItemsInserted = 0;
    $numItemsUpdated = 0;
    $numItemsDeleted = 0;
    
    // 获取本地现有商品
    $localProducts = $DB->query("SELECT noun, name FROM qingka_wangke_class WHERE docking='{$hid}'");
    $upstreamProductIds = array_map(function($category) {
        return $category['cid'];
    }, $categories); // 上游商品ID列表
    
    // 下架本地不存在于上游商品ID中的商品
    while ($row = $DB->fetch($localProducts)) {
        if (!in_array($row['noun'], $upstreamProductIds)) {
            // 直接下架商品
            $DB->query("UPDATE qingka_wangke_class SET status = 0 WHERE noun='{$row['noun']}' AND docking='{$hid}'");
            $numItemsDeleted++;
            echo "下架商品：{$row['name']}" . PHP_EOL;
        }
    }

    // 定义要跳过的 fenlei ID 列表
    if ($dockcro == 1) { // 仅当 $dockcro 为 1 时执行分类的插入或更新
        foreach ($categories as $value) {
            // 如果 fenlei ID 在跳过列表中，则跳过
            if (in_array($value['fenlei'], $skipCategories)) {
                continue;
            }

            if (empty($category) || $value['fenlei'] == $category) {
                $existingCategory = $DB->get_row("SELECT * FROM qingka_wangke_fenlei WHERE id='{$value['fenlei']}'");
                // 插入分类部分
                if (!$existingCategory) {
                    $DB->query("INSERT INTO qingka_wangke_fenlei (id, sort, name, status, time) VALUES ('{$value['fenlei']}', '0', '{$value['fenleiname']}', '1', NOW())");
                } 
                // 更新现有分类（如果需要）
                // $DB->query("UPDATE qingka_wangke_fenlei SET name='{$value['category_name']}' WHERE id='{$value['fenlei']}'");
                // }
            }
        }
    }

    foreach ($categories as $value) {
        // 如果 fenlei ID 在跳过列表中，则跳过
        if (in_array($value['fenlei'], $skipCategories)) {
            continue;
        }

        if (empty($category) || $value['fenlei'] == $category) {
            $price = $value['price'] * $pricee;
            //额外算法：$price = $value['price'] * $pricee + 0.1，先乘后加;
            $sort = $numItemsInserted + $numItemsUpdated + 1; // 排序字段
            $existingProduct = $DB->get_row("SELECT * FROM qingka_wangke_class WHERE docking='{$hid}' AND noun='{$value['cid']}'");
            if ($existingProduct) {
                $DB->query("UPDATE qingka_wangke_class SET price='{$price}', content='{$value['content']}', name='{$value['name']}', sort='{$value['sort']}', status='{$value['status']}' WHERE docking='{$hid}' AND noun='{$value['cid']}'");
                $numItemsUpdated++;
            } else {
                $DB->query("INSERT INTO qingka_wangke_class ( name, getnoun, noun, fenlei, queryplat, docking, price, sort, content, addtime, status) 
                            VALUES ( '{$value['name']}', '{$value['cid']}', '{$value['cid']}', '{$value['fenlei']}', '$hid', '$hid', '{$price}', '{$value['sort']}', '{$value['content']}', NOW(), '{$value['status']}')");
                $numItemsInserted++;
            }
        }
    }
    echo "本次对接上架了{$numItemsInserted}个新商品，更新了{$numItemsUpdated}个商品的价格，下架了{$numItemsDeleted}个商品" . ($dockcro == 1 ? "，分类信息已更新" : "") . "</br>";
}
//一键执行操作之前记着先备份
//一键执行操作之前记着先备份
//一键执行操作之前记着先备份
//仅更新商品价格，须谨慎最好重新测试后才尝试
//仅更新商品价格，须谨慎最好重新测试后才尝试
//仅更新商品价格，须谨慎最好重新测试后才尝试
//仅更新商品价格，须谨慎最好重新测试后才尝试
//仅更新商品价格，须谨慎最好重新测试后才尝试

elseif ($act == 'jiageshuoming') { // 仅更新商品价格和商品说明
    $a = $DB->get_row("SELECT * FROM qingka_wangke_huoyuan WHERE hid='{$hid}'");
    if (!$a) {
        jsonReturn(-1, "货源信息不存在");
    }
    $data = array("uid" => $a["user"], "key" => $a["pass"]);
    $er_url = "{$a["url"]}/api.php?act=getclass";
    $result = get_url($er_url, $data);
    $result1 = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result1["data"])) {
        jsonReturn(-1, "API 返回数据格式错误或缺失");
    }
    $categories = $result1["data"];
    $numItemsUpdated = 0;
    
    // 定义要跳过的 fenlei ID 列表
    foreach ($categories as $value) {
        // 如果 fenlei ID 在跳过列表中，则跳过
        if (in_array($value['fenlei'], $skipCategories)) {
            continue;
        }

        $price = $value['price'] * $pricee;
        $existingProduct = $DB->get_row("SELECT * FROM qingka_wangke_class WHERE docking='{$hid}' AND noun='{$value['cid']}'");
        if ($existingProduct) {
            $DB->query("UPDATE qingka_wangke_class SET price='{$price}', content='{$value['content']}' WHERE docking='{$hid}' AND noun='{$value['cid']}'");
            $numItemsUpdated++;
        }
    }
    echo "本次更新了{$numItemsUpdated}个商品的价格</br>";
}
?>

