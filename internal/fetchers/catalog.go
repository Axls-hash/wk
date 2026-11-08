package fetchers

// 冷门网课平台目录：与主流 Platforms 分开维护，全部仍经天河（skyriver）接入。
//
// 新增冷门平台只需在 MorePlatforms 追加一行：
//   - Name 必须是查课/下单的唯一标识（会作为账户 key 前缀与前端显示名）
//   - Noun 是天河对接 ID（act=get 查课、act=add 下单 的 platform 参数），
//     即 act=getclass 返回的 cid
//   - Category 是天河分类 ID（登记用）
//
// 从天河拉某分类下的可对接项目：
//   POST api.php?act=getclass&uid=..&key=..&fenlei=<分类ID>
//   data 数组每项 {name, cid, fenlei, price, content}，cid 即 Noun。

type CatalogEntry struct {
	Name     string // 平台名（唯一）
	Noun     string // 天河对接 ID（cid）
	Category string // 天河分类 ID
}

// MorePlatforms 冷门平台列表（按展示顺序）。
var MorePlatforms = []CatalogEntry{
	// 分类8 = 龙龙货源（305 个项目）。默认对接「龙龙-学习通全包」cid=602，
	// 如需换成其它龙龙项目（如二倍速观看视频 cid=597）改 Noun 即可。
	{Name: "龙龙", Noun: "602", Category: "8"},
	// 分类3 = pup/波妞货源（52 个项目，U校园等）。默认对接「pup-U校园AI版 整本时长」cid=96。
	{Name: "pup", Noun: "96", Category: "3"},
	// 分类67 = 灵珠货源（63 个项目，大量成教/继教/专技平台）。默认对接「灵珠-中国大学」cid=2201。
	{Name: "灵珠", Noun: "2201", Category: "67"},
	// 分类4 = 一流货源（183 个项目，成教/继教/职教/实训平台）。默认对接「yl-中国大学」cid=169。
	{Name: "一流", Noun: "169", Category: "4"},
	// 分类21 = 毛豆货源（18 个项目，智慧职教MOOC/SPOC/资源库/AI优课 + U校园/智慧树/中国大学）。
	// 默认对接「毛豆-智慧职教四合一」cid=1446（与主线智慧职教 SKYRIVER_PLATFORM_ZJY=1446 一致）。
	{Name: "毛豆", Noun: "1446", Category: "21"},
	// 分类2 = 奶昔货源（28 个项目，学习通/智慧树/U校园/Utalk/随行课堂/学习公社/中国大学mooc）。
	// 默认对接「奶昔-智慧树慢刷」cid=56（与主线智慧树 SKYRIVER_PLATFORM_ZHS=56 一致）。
	{Name: "奶昔", Noun: "56", Category: "2"},
	// 分类53 = 曙光货源（51 个项目，学习通/U校园/Welearn/池馆/青书学堂/智慧树/中国大学MOOC等）。
	// 默认对接「曙光-中国大学MOOC」cid=5747（与主线 MOOC SKYRIVER_PLATFORM_MOOC=5747 一致）。
	{Name: "曙光", Noun: "5747", Category: "53"},
	// 分类19 = 小青货源（121 个项目，青书学堂/学起plus/随行课堂Welearn/智慧树AI课/雨课堂/优学院/U校园等大量成教继教平台）。
	// 默认对接「小青-青书学堂 全包」cid=1250，如需换成其它小青项目改 Noun 即可。
	{Name: "小青", Noun: "1250", Category: "19"},
	// 分类16 = 五福货源（310 个项目，学习通/学起plus/welearn 随行课堂/继续教育平台等）。
	// 默认对接「五福-学习通（视频+测验）」cid=5278。
	{Name: "五福", Noun: "5278", Category: "16"},
	// 分类47 = 茉莉货源（157 个项目，学起Plus/青书学堂/U校园/清华社等）。
	// 默认对接「茉莉-学起Plus(全包)」cid=3232。
	{Name: "茉莉", Noun: "3232", Category: "47"},
	// 分类15 = tenu货源（363 个项目，麦能学堂/广财慕课/云南大学继教/学堂在线等成教继教平台）。
	// 默认对接「tenu-麦能学堂」cid=832。
	{Name: "tenu", Noun: "832", Category: "15"},
	// 分类42 = 国开专区（上游 66 个项目；按要求排除「天河独家优质」15 个，实际上架 51 个）。
	// 货源为霍希/kk/龙猫等，默认对接「霍希-新国开」cid=400。
	{Name: "国开", Noun: "400", Category: "42"},
	// 分类17 = 课代表货源（55 个项目，学习通/智慧树/智慧职教等）。
	// 默认对接「课代表-智慧树-默认慢刷全包」cid=926。
	{Name: "课代表", Noun: "926", Category: "17"},
	// ---- 后续新增在此追加，例如 ----
	// {Name: "龙龙-广开", Noun: "634", Category: "8"},
	// {Name: "龙龙-青书学堂", Noun: "636", Category: "8"},
}

// isMorePlatform 判断是否冷门目录内平台。
func isMorePlatform(name string) bool {
	for _, e := range MorePlatforms {
		if e.Name == name {
			return true
		}
	}
	return false
}
