package config

import "testing"

// TestAtoiFen 元转分边界。
func TestAtoiFen(t *testing.T) {
	cases := []struct {
		in   string
		want int
	}{
		{"3", 300}, {"3.5", 350}, {"0.01", 1}, {"12.567", 1257},
		{"abc", 300}, {"", 300}, {"0", 300}, // 非法/0 回退默认 3 元
	}
	for _, c := range cases {
		if got := atoiFen(c.in); got != c.want {
			t.Errorf("atoiFen(%q) = %d, want %d", c.in, got, c.want)
		}
	}
}

// TestGenCodeRandomToken 随机 token 长度与唯一性。
func TestGenCodeRandomToken(t *testing.T) {
	a, b := randomToken(16), randomToken(16)
	if a == b || len(a) != 32 {
		t.Fatal("randomToken 异常")
	}
}

// TestRegisterOpenParsing 注册开关解析。
func TestRegisterOpenParsing(t *testing.T) {
	t.Setenv("REGISTER_OPEN", "0")
	if parseRegisterOpen() {
		t.Fatal("0 应关闭注册")
	}
	t.Setenv("REGISTER_OPEN", "false")
	if parseRegisterOpen() {
		t.Fatal("false 应关闭注册")
	}
	t.Setenv("REGISTER_OPEN", "")
	if parseRegisterOpen() {
		t.Fatal("未配置应默认关闭注册")
	}
	t.Setenv("REGISTER_OPEN", "1")
	if !parseRegisterOpen() {
		t.Fatal("REGISTER_OPEN=1 应开放注册")
	}
}
