<?php
include(__DIR__.'/confing/common.php');
$hid = 33;//对接天河的货源id，一般在货源页面查看，填错了会有问题请自行检查
$pricee = 0.93;//1.3获取到的商品按定价价格提高30% 填1=跟天河skyriver源站价格完全相同，其他自行倍数计算
$dockcro = 0; // 是否新建数据库，无数据默认1即可，有数据默认0 0则上架商品同时更新价格 
//1会全面克隆分类和商品1-1复刻 0 则只上架商品
$skipCategories = array('22', '1', '23', '13', '8', '52', '42', '55', '65','11'); // 替换成您实际要跳过的上游分类 ID 格式以此类推，填了代表不上架也不更新这几个分类的项目
$categoryIdMap = array(
    '2' => '15',
    '3' => '9',
    '4' => '4',
    '5' => '5',
    '14' => '4',
    '15' => '13',
    '16' => '16',
    '17' => '74',
    '18' => '73',
    '19' => '12',
    '21' => '21',
    '24' => '24',
    '46' => '11',
    '47' => '3',
    '48' => '48',
    '50' => '50',
    '51' => '51',
    '60' => '68',
    '62' => '62',
    '63' => '63',
    '64' => '64',
    '66' => '66',

); // 不创建分类时使用；只有配置了映射的上游分类才会同步，商品会归入对应的本地分类
$categoryNameReplace = array(
    '2' => array('from' => '奶昔-', 'to' => '【奶昔】'),
    '3' => array('from' => 'pup-', 'to' => '【波妞】'),
    '4' => array('from' => 'yl-', 'to' => '【一流】'),
    '5' => array('from' => '东哥-', 'to' => '【东哥】'),
    '14' => array('from' => '少年-', 'to' => '【一流】'),
    '15' => array('from' => 'tenu-', 'to' => '【校代】'),
    '16' => array('from' => '五福-', 'to' => '【五福】'),
    '17' => array('from' => '课代表-', 'to' => '【课代表】'),
    '18' => array('from' => '王者-', 'to' => '【王牌】'),
    '19' => array('from' => '小青-', 'to' => '【小青】'),
    '21' => array('from' => '毛豆-', 'to' => '【毛豆】'),
    '24' => array('from' => '特斯拉-', 'to' => '【特斯拉】'),
    '46' => array('from' => '红薯-', 'to' => '【红薯】'),
    '47' => array('from' => '茉莉-', 'to' => '【茉莉】'),
    '48' => array('from' => '彩虹-', 'to' => '【彩虹】'),
    '49' => array('from' => '8090-', 'to' => '【酒吧】'),
    '50' => array('from' => 'YYY继教-', 'to' => '【YYY】'),
    '51' => array('from' => '欲梦-', 'to' => '【欲梦】'),
    '60' => array('from' => '橘子-', 'to' => '【小沐】'),
    '62' => array('from' => '奶牛-', 'to' => '【奶牛】'),
    '63' => array('from' => '易教育-', 'to' => '【易教育】'),
    '64' => array('from' => '懒洋洋-', 'to' => '【懒洋洋】'),
    '66' => array('from' => '猫猫继教-', 'to' => '【Morning】'),
    // '分类ID' => array('from' => '要替换的前缀', 'to' => '替换成的前缀'),
); // 根据上游分类ID，对新上架商品名称进行前缀替换
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
    set_time_limit(0);
    // 关闭输出缓冲，实现实时进度显示
    ini_set('output_buffering', 'off');
    while (ob_get_level()) {
        ob_end_flush();
    }

    $a = $DB->get_row("SELECT * FROM qingka_wangke_huoyuan WHERE hid='{$hid}'");
    if (!$a) {
        jsonReturn(-1, "货源信息不存在");
    }
    $data = array("uid" => $a["user"], "key" => $a["pass"]);
    $er_url = "{$a["url"]}/api.php?act=getclass";
    echo "正在获取上游商品数据..." . str_repeat(' ', 4096) . "<br/>";
    flush();
    $result = get_url($er_url, $data);
    $result1 = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result1["data"])) {
        jsonReturn(-1, "API 返回数据格式错误或缺失");
    }
    $categories = $result1["data"];
    $totalCount = count($categories);
    echo "获取到 {$totalCount} 条上游商品，开始处理...<br/>";
    flush();

    $numItemsInserted = 0;
    $numItemsUpdated = 0;
    $numItemsDeleted = 0;
    $batchSize = 50; // 批量插入条数

    // 一次性预加载所有本地现有商品，key 为 noun
    $existingProducts = array();
    $localRes = $DB->query("SELECT noun, name FROM qingka_wangke_class WHERE docking='{$hid}'");
    while ($row = $DB->fetch($localRes)) {
        $existingProducts[$row['noun']] = $row;
    }

    // 上游商品ID列表
    $upstreamProductIds = array_map(function($category) {
        return $category['cid'];
    }, $categories);

    // 下架本地不存在于上游商品ID中的商品
    foreach ($existingProducts as $noun => $row) {
        if (!in_array($noun, $upstreamProductIds)) {
            $DB->query("UPDATE qingka_wangke_class SET status = 0 WHERE noun='{$noun}' AND docking='{$hid}'");
            $numItemsDeleted++;
            echo "下架商品：{$row['name']}<br/>";
            flush();
        }
    }
    unset($existingProducts); // 释放内存
    unset($localRes);

    // 同步分类（仅当 dockcro == 1）
    if ($dockcro == 1) {
        foreach ($categories as $value) {
            if (in_array($value['fenlei'], $skipCategories)) {
                continue;
            }
            if (empty($category) || $value['fenlei'] == $category) {
                $existingCategory = $DB->get_row("SELECT * FROM qingka_wangke_fenlei WHERE id='{$value['fenlei']}'");
                if (!$existingCategory) {
                    $DB->query("INSERT INTO qingka_wangke_fenlei (id, sort, name, status, time) VALUES ('{$value['fenlei']}', '0', '{$value['fenleiname']}', '1', NOW())");
                }
            }
        }
    }

    // 重新加载现有商品（用于判断 UPDATE / INSERT）
    $existingProducts = array();
    $localRes = $DB->query("SELECT noun FROM qingka_wangke_class WHERE docking='{$hid}'");
    while ($row = $DB->fetch($localRes)) {
        $existingProducts[$row['noun']] = true;
    }

    // 批量插入缓存
    $insertValues = array();
    $processedCount = 0;

    foreach ($categories as $index => $value) {
        // 跳过不需要的分类
        if (in_array($value['fenlei'], $skipCategories)) {
            continue;
        }
        if (!empty($category) && $value['fenlei'] != $category) {
            continue;
        }
        // 不创建分类时，必须通过分类 ID 映射到本地已有分类
        if ($dockcro == 0 && !isset($categoryIdMap[(string)$value['fenlei']])) {
            continue;
        }

        $price = number_format($value['price'] * $pricee, 2, '.', '');
        $cid = $value['cid'];
        $fenlei = $dockcro == 0 ? $categoryIdMap[(string)$value['fenlei']] : $value['fenlei'];
        $sortVal = $value['sort'];
        $statusVal = $value['status'];
        $productName = $value['name'];
        // 根据上游分类ID对商品名称进行前缀替换
        if (isset($categoryNameReplace[(string)$value['fenlei']])) {
            $rule = $categoryNameReplace[(string)$value['fenlei']];
            $productName = str_replace($rule['from'], $rule['to'], $productName);
        }
        $productName = $DB->escape($productName);
        $productContent = $DB->escape($value['content']);

        if (isset($existingProducts[$cid])) {
            // 已存在 → 更新名称、分类、价格、说明、状态
            $DB->query("UPDATE qingka_wangke_class SET name='{$productName}', fenlei='{$fenlei}', price='{$price}', content='{$productContent}', status='{$statusVal}' WHERE docking='{$hid}' AND noun='{$cid}'");
            $numItemsUpdated++;
        } else {
            // 不存在 → 加入批量插入队列
            $insertValues[] = "('{$productName}', '{$cid}', '{$cid}', '{$fenlei}', '$hid', '$hid', '{$price}', '{$sortVal}', '{$productContent}', NOW(), '{$statusVal}')";

            // 达到批量大小，执行批量插入
            if (count($insertValues) >= $batchSize) {
                $insertResult = $DB->query("INSERT INTO qingka_wangke_class (name, getnoun, noun, fenlei, queryplat, docking, price, sort, content, addtime, status)
                            VALUES " . implode(',', $insertValues));
                if ($insertResult) {
                    $numItemsInserted += count($insertValues);
                } else {
                    echo "批量上架失败，数量：" . count($insertValues) . "<br/>";
                }
                $insertValues = array();
            }
        }

        $processedCount++;
        // 每处理 10 条输出进度
        if ($processedCount % 10 == 0) {
            echo "进度: {$processedCount} / {$totalCount}（新上架 {$numItemsInserted}，更新 {$numItemsUpdated}，下架 {$numItemsDeleted}）<br/>";
            flush();
        }
    }

    // 处理剩余未达到批量大小的插入
    if (!empty($insertValues)) {
        $insertResult = $DB->query("INSERT INTO qingka_wangke_class (name, getnoun, noun, fenlei, queryplat, docking, price, sort, content, addtime, status)
                    VALUES " . implode(',', $insertValues));
        if ($insertResult) {
            $numItemsInserted += count($insertValues);
        } else {
            echo "批量上架失败，数量：" . count($insertValues) . "<br/>";
        }
    }

    echo "本次对接上架了{$numItemsInserted}个新商品，更新了{$numItemsUpdated}个商品的价格，下架了{$numItemsDeleted}个商品" . ($dockcro == 1 ? "，分类信息已更新" : "") . "<br/>";
    echo "处理完成！<br/>";
    flush();
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
    set_time_limit(0);
    ini_set('output_buffering', 'off');
    while (ob_get_level()) {
        ob_end_flush();
    }

    $a = $DB->get_row("SELECT * FROM qingka_wangke_huoyuan WHERE hid='{$hid}'");
    if (!$a) {
        jsonReturn(-1, "货源信息不存在");
    }
    $data = array("uid" => $a["user"], "key" => $a["pass"]);
    $er_url = "{$a["url"]}/api.php?act=getclass";
    echo "正在获取上游商品数据..." . str_repeat(' ', 4096) . "<br/>";
    flush();
    $result = get_url($er_url, $data);
    $result1 = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result1["data"])) {
        jsonReturn(-1, "API 返回数据格式错误或缺失");
    }
    $categories = $result1["data"];
    $totalCount = count($categories);
    echo "获取到 {$totalCount} 条上游商品，开始处理...<br/>";
    flush();

    $numItemsUpdated = 0;

    // 预加载本地现有商品，key 为 noun
    $existingProducts = array();
    $localRes = $DB->query("SELECT noun FROM qingka_wangke_class WHERE docking='{$hid}'");
    while ($row = $DB->fetch($localRes)) {
        $existingProducts[$row['noun']] = true;
    }

    foreach ($categories as $index => $value) {
        // 跳过不需要的分类
        if (in_array($value['fenlei'], $skipCategories)) {
            continue;
        }
        // 不创建分类时，必须通过分类 ID 映射到本地已有分类
        if ($dockcro == 0 && !isset($categoryIdMap[(string)$value['fenlei']])) {
            continue;
        }

        // 只更新本地已存在的商品
        if (isset($existingProducts[$value['cid']])) {
            $price = number_format($value['price'] * $pricee, 2, '.', '');
            $fenlei = $dockcro == 0 ? $categoryIdMap[(string)$value['fenlei']] : $value['fenlei'];
            $productName = $value['name'];
            // 根据上游分类ID对商品名称进行前缀替换
            if (isset($categoryNameReplace[(string)$value['fenlei']])) {
                $rule = $categoryNameReplace[(string)$value['fenlei']];
                $productName = str_replace($rule['from'], $rule['to'], $productName);
            }
            $productName = $DB->escape($productName);
            $productContent = $DB->escape($value['content']);
            $DB->query("UPDATE qingka_wangke_class SET name='{$productName}', fenlei='{$fenlei}', price='{$price}', content='{$productContent}' WHERE docking='{$hid}' AND noun='{$value['cid']}'");
            $numItemsUpdated++;
        }

        // 每处理 10 条输出进度
        if (($index + 1) % 10 == 0) {
            echo "进度: " . ($index + 1) . " / {$totalCount}（已更新 {$numItemsUpdated}）<br/>";
            flush();
        }
    }
    echo "本次更新了{$numItemsUpdated}个商品的价格<br/>";
    echo "处理完成！<br/>";
    flush();
}
?>
