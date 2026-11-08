syytianheban（爽歪歪天河版）部署说明
==============================

重要：先理解它的对接模型
--------------------------
爽歪歪不是极光、破界、KDB 那种“二开站复制一套下单页和订单表”的运动订单 API。
它的模型是：

    本站代理账号
        -> 为某个源台项目生成独立客户 Key（初始余额可为0）
        -> 用代理的本站余额向这个 Key 充值
        -> 用户打开源台返回的 customer_link
        -> 所有实际业务、下单和消费都在源台页面内完成

因此本站不需要再造一套运动下单前端、订单表、查单和运动退款逻辑。
本站只管理“客户 Key + 余额兑换 + 专属链接”。

一、本版支持的功能
----------------------
1. 直连爽歪歪源台：
   - 生成客户：generateCustomerApi
   - 调整客户余额：adjustCustomerBalance
   - 查询客户余额：getCustomerInfo
   - 禁用客户并退回剩余余额：deleteCustomerApi
   - 重新启用客户：enableCustomer
   - 优先保存并打开源台原样返回的 customer_link，旧数据才使用 redirect_url + key 兜底。

2. 同系统套娃：
   - 下游仍使用上面同一组核心接口。
   - 每个下游客户都会在最终上游创建独立客户，不再共用一个上游客户余额。
   - 逐层保存 upstream_customer_id、upstream_project_id 和真实访问密钥。
   - 余额增加时逐层扣款，余额减少或禁用时逐层返款。
   - 套娃交易支持 transaction_id/request_id 防重复记账。

3. 计费：
   - 保留当前系统的用户等级费率 addprice、项目单价 price 和基准费率 base_rate。
   - 实际单价 = 项目单价 × 用户费率 ÷ 基准费率。
   - 用户输入的是需要充值的项目公里数，后端按“公里数 × 实际元/km单价”计算本站应扣余额。
   - 项目公里数、计费单价和本站扣款统一保留四位小数，扣款使用条件更新，避免并发超扣。

二、部署目录
--------------
将整个 syytianheban 文件夹上传到站点根目录，例如：

    /www/wwwroot/你的站点/syytianheban/


访问地址：

    https://你的域名/syytianheban/
    https://你的域名/syytianheban/index.php

套娃 OpenAPI 地址：

    https://你的域名/syytianheban/openapi.php

当前天河主域名对应的下游接口：

    https://skyriver.top/syytianheban/openapi.php

文件及访问接口对应关系：

    syytianheban/index.php
        前端管理入口，需要先登录你的主站。

    syytianheban/api.php?action=具体操作
        管理界面内部接口，需要主站登录状态；前端使用相对路径自动访问本目录接口。

    syytianheban/openapi.php?action=具体操作
        提供给下游站的套娃接口，使用下游账号生成的 api_key 鉴权。

    syytianheban/ct.php
    syytianheban/UniversalAPI.php
        内部类文件，不允许浏览器直接访问。

    syytianheban/balance_sync_check.php
        命令行诊断工具，不允许浏览器直接访问。需要时在站点目录执行：
        php syytianheban/balance_sync_check.php

程序使用相对接口地址，因此目录改为 syytianheban 后，前端请求会自然落到
/syytianheban/api.php，无需在代码中写死域名，也无需配置伪静态。

不需要额外的 MySQL 安装 SQL。UniversalAPI.php 会自动创建或升级文件夹内的 SQLite 数据库。
服务器需要开启 PHP 扩展：curl、pdo_sqlite、sqlite3，并保证 syytianheban 目录对 PHP 运行用户可写。

宝塔服务器使用 www 用户运行 PHP 时，上传后可检查目录归属和权限：

    chown -R www:www /www/wwwroot/daxiongbuxi/syytianheban
    find /www/wwwroot/daxiongbuxi/syytianheban -type d -exec chmod 750 {} \;
    find /www/wwwroot/daxiongbuxi/syytianheban -type f -exec chmod 640 {} \;

建议在宝塔“网站 -> 设置 -> 配置文件”的当前域名 server 段内加入以下安全规则。
应放在 server 大括号内，位置建议放在 PHP 配置 include 之前：

    location = /syytianheban/ct.php {
        return 404;
    }

    location = /syytianheban/UniversalAPI.php {
        return 404;
    }

    location = /syytianheban/balance_sync_check.php {
        return 404;
    }

    location ~* ^/syytianheban/.*\.(log|db|sqlite|txt)$ {
        return 404;
    }

添加后执行 nginx -t，确认 successful 后再重载 Nginx。
尤其不能让“爽歪歪寄生系统数据库-勿删勿清理.log”被公网下载，里面保存了业务映射和密钥数据。

三、直连爽歪歪的配置
------------------------
1. 用管理员账号进入 syytianheban。
2. 进入“系统配置”：
   - 套娃模式：关闭。
   - 源平台 API 地址：https://syyrun.com/api.php
   - 源平台 API 密钥：填爽歪歪的“用户管理 API 密钥”。
   - 基准费率：按你系统当前等级计费标准填写。
3. 进入“项目管理”，点“同步项目”。系统会通过公开的 getProjectInfo 接口自动获取：
   - 源项目 ID。
   - 项目名称和说明。
   - 项目访问地址 redirect_url。
   - 项目文档地址 docs_url。
4. 源台公开项目信息不含二开计费价格。新同步项目默认禁用，必须编辑项目，设置标准等级的“元/km”单价后再开启。
5. “手动添加”仅用于源台项目接口异常或临时补录。

四、给下游开通套娃
--------------------
1. 你的下游先在你的主站正常注册代理账号，你给该账号设置 addprice 等级和可用余额。
2. 下游登录 syytianheban，在“我的对接信息”生成用户对接密钥。
3. 交付给下游的两项信息：
   - API 地址：https://skyriver.top/syytianheban/openapi.php
   - API 密钥：该下游账号自己生成的对接密钥。
4. 下游在自己的 syytianheban 系统配置中：
   - 套娃模式：开启。
   - 源平台 API 地址：填上面的 openapi.php 地址。
   - 源平台 API 密钥：填上面的下游专属密钥。
5. 下游点“从源平台同步项目”，即可获取你平台已启用的项目。

只要每一层都使用本版 syytianheban，便可继续向下对接。各层使用自己的用户等级和项目单价计费。

五、旧版数据处理
------------------
1. 升级时不会删除原有数据，新字段由程序自动补齐。
2. 原有的项目、用户对接密钥、兑换记录和工单记录会保留。
3. 旧版 openapi_customers 如果没有 upstream_customer_id，无法安全推断它在上游的真实客户 ID。
   这类客户查询可显示本地缓存，但不允许继续调额或禁用，需要重新开户，防止误操作其他上游客户。
4. 更新前请先备份 syytianheban 内原有的 .log/.db 数据库文件。

六、接口请求规则
------------------
- generateCustomerApi：GET（套娃接口也同时接受 POST）
- deleteCustomerApi：GET（套娃接口也同时接受 POST）
- enableCustomer：GET（套娃接口也同时接受 POST）
- getCustomerInfo：GET
- getProjectInfo：GET（公开接口，无需 API Key）
- adjustCustomerBalance：POST

所有请求都同时支持 api_key 参数和 X-API-Key 请求头。

七、退款与对账安全
----------------------
1. 禁用客户前先查询上游实时余额。
2. 优先按上游 refunded 或 refund_details 返回值计算本层返款。
3. 如上游节点没有透传退回金额，才使用禁用前实时查询到的余额。
4. 本层返款失败时，自动尝试重新启用上游客户并恢复对应余额；回滚也失败时会明确提示手工对账。

八、文档能力边界
------------------
两份《爽歪歪对接接口说明》合并后已公开客户、余额和项目信息接口，但仍没有公开：
- 运动下单、查单、修改、加天、重置、退款订单等业务接口。
- 源台工单接口。

因此本版只实现文档能力范围内的客户开通、余额划转、禁用退余额及同协议套娃。
如需运动订单类功能，必须由爽歪歪另行提供对应接口文档，不能使用未公开路径猜测实现。
直连模式下已在界面 API 层明确拒绝调用源台工单；套娃模式下上游是本系统 openapi.php，可继续使用系统内工单。

九、上线前测试顺序
------------------
1. 先备份旧的“爽歪歪寄生系统数据库-勿删勿清理.log”，再上传本版 PHP 文件。
2. 用管理员账号打开一次 index.php，让自动迁移执行完成。
3. 直连模式只添加一个测试项目，使用小额测试：开户 -> 充值 -> 查询 -> 提现 -> 禁用。
4. 核对每一步的爽歪歪项目公里余额、本站用户余额和兑换记录。
5. 再用一个测试代理生成对接密钥，在第二套 syytianheban 开启套娃模式，测试项目同步和同样的五步余额流程。
6. 确认两个不同下游客户在上游生成了两个不同 customer_id，然后再正式开放。

注：爽歪歪源台接口没有公开按 transaction_id 查找开户结果的接口。如果开户请求超时，不要立即换一个交易号重复开户，应先在源台检查是否已经生成客户。
