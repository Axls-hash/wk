package fetchers

var Platforms = []string{"\u667a\u6167\u6811", "\u5b66\u4e60\u901a", "\u667a\u6167\u804c\u6559", "welearn", "\u6c60\u9c7c", "MOOC", "\u4f18\u5b66\u9662", "u\u6821\u56ed"}

type fetchFunc func(username, password, school string) ([]Course, error)

var registry = map[string]fetchFunc{
	"\u5b66\u4e60\u901a":       FetchSkyriverXuexitong,
	"\u667a\u6167\u6811":       FetchSkyriverZhihuishu,
	"\u667a\u6167\u804c\u6559": FetchSkyriverZhijiao,
	"welearn":                  FetchSkyriverWelearn, "\u6c60\u9c7c": FetchSkyriverChiguan,
	"u\u6821\u56ed": FetchSkyriverUxiaoyuan, "\u4f18\u5b66\u9662": FetchSkyriverYouxueyuan, "MOOC": FetchSkyriverMooc,
}

func IsPlatform(p string) bool {
	for _, name := range Platforms {
		if name == p {
			return true
		}
	}
	if isMorePlatform(p) {
		return true
	}
	_, ok := productBy(p)
	return ok
}
func FetchCourses(platform, username, password, school string) ([]Course, error) {
	if fn, ok := registry[platform]; ok {
		return fn(username, password, school)
	}
	if isMorePlatform(platform) || hasProduct(platform) {
		return skyriverGetCourse(platform, school, username, password)
	}
	return fetchPlaceholder(platform, username, password, school)
}
func hasProduct(name string) bool { _, ok := productBy(name); return ok }
func fetchPlaceholder(platform, username, password, school string) ([]Course, error) {
	return []Course{{Title: "placeholder course", Status: "in_progress"}}, nil
}
